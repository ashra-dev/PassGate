<?php

declare(strict_types=1);

/**
 * One-time migration from legacy CSV files to PostgreSQL.
 *
 * Usage: php migrate.php
 *
 * For schema-only upgrades (stalls table, etc.): php upgrade_schema.php
 */

require_once __DIR__ . '/db.php';

$registryFile = __DIR__ . '/Master_Ticket_Registry.csv';
$distributorsFile = __DIR__ . '/distributors.csv';

if (!file_exists($registryFile)) {
    fwrite(STDERR, "ERROR: Master_Ticket_Registry.csv not found.\n");
    exit(1);
}

if (!file_exists($distributorsFile)) {
    fwrite(STDERR, "ERROR: distributors.csv not found.\n");
    exit(1);
}

$db = getDb();

try {
    $db->beginTransaction();

    // --- Distributors ---
    $distInsert = $db->prepare(
        'INSERT INTO distributors (id, name, email, role)
         VALUES (:id, :name, :email, :role)
         ON CONFLICT (id) DO UPDATE
         SET name = EXCLUDED.name, email = EXCLUDED.email, role = EXCLUDED.role'
    );

    $distFp = fopen($distributorsFile, 'r');
    if ($distFp === false) {
        throw new RuntimeException('Unable to open distributors.csv');
    }

    fgetcsv($distFp); // header

    while (($row = fgetcsv($distFp)) !== false) {
        if (empty($row[0])) {
            continue;
        }

        $distInsert->execute([
            'id'    => trim($row[0]),
            'name'  => trim($row[1] ?? ''),
            'email' => strtolower(trim($row[2] ?? '')),
            'role'  => strtolower(trim($row[3] ?? 'distributor')) ?: 'distributor',
        ]);
    }
    fclose($distFp);

    // --- Registry ---
    $fp = fopen($registryFile, 'r');
    if ($fp === false) {
        throw new RuntimeException('Unable to open Master_Ticket_Registry.csv');
    }

    $headers = fgetcsv($fp);
    if ($headers === false) {
        throw new RuntimeException('Registry CSV is empty');
    }

    $rows = [];
    while (($data = fgetcsv($fp)) !== false) {
        if (count($data) < count($headers)) {
            $data = array_pad($data, count($headers), '');
        }
        $rows[] = array_combine($headers, $data);
    }
    fclose($fp);

    if ($rows === []) {
        throw new RuntimeException('No ticket rows found in registry CSV');
    }

    // Event (single event assumed from first row)
    $eventName = trim($rows[0]['Event_Name'] ?? 'Imported Event');
    $eventStmt = $db->prepare('INSERT INTO events (name) VALUES (:name) RETURNING id');
    $eventStmt->execute(['name' => $eventName]);
    $eventId = (int) $eventStmt->fetchColumn();

    // Discover benefit column groups from headers
    $benefitColumns = [];
    foreach ($headers as $header) {
        if (preg_match('/^Benefit_(\d+)_Name$/', $header, $m)) {
            $num = $m[1];
            $benefitColumns[$num] = [
                'name' => $header,
                'max'  => "Benefit_{$num}_Max",
                'used' => "Benefit_{$num}_Used",
                'logs' => "Benefit_{$num}_Scan_Logs",
            ];
        }
    }
    ksort($benefitColumns, SORT_NUMERIC);

    // Tiers keyed by "name|price"
    $tierMap = [];
    $tierInsert = $db->prepare(
        'INSERT INTO tiers (event_id, name, price, quantity)
         VALUES (:event_id, :name, :price, :quantity)
         RETURNING id'
    );

    foreach ($rows as $row) {
        $tierName = trim($row['Ticket_Type'] ?? '');
        $price = (float) ($row['Price'] ?? 0);
        $key = $tierName . '|' . number_format($price, 2, '.', '');

        if (!isset($tierMap[$key])) {
            $tierInsert->execute([
                'event_id' => $eventId,
                'name'     => $tierName,
                'price'    => $price,
                'quantity' => 0,
            ]);
            $tierMap[$key] = [
                'id'       => (int) $tierInsert->fetchColumn(),
                'name'     => $tierName,
                'price'    => $price,
                'quantity' => 0,
                'benefits' => [],
            ];
        }
        $tierMap[$key]['quantity']++;
    }

    // Update tier quantities
    $tierQtyUpdate = $db->prepare('UPDATE tiers SET quantity = :qty WHERE id = :id');
    foreach ($tierMap as $tier) {
        $tierQtyUpdate->execute(['qty' => $tier['quantity'], 'id' => $tier['id']]);
    }

    // Benefits per tier (from first ticket of each tier)
    $benefitInsert = $db->prepare(
        'INSERT INTO benefits (tier_id, name, max_uses)
         VALUES (:tier_id, :name, :max_uses)
         RETURNING id'
    );

    $benefitIdMap = []; // tier_id => [normalized_name => benefit_id]

    foreach ($rows as $row) {
        $tierName = trim($row['Ticket_Type'] ?? '');
        $price = (float) ($row['Price'] ?? 0);
        $key = $tierName . '|' . number_format($price, 2, '.', '');
        $tierId = $tierMap[$key]['id'];

        if (!isset($benefitIdMap[$tierId])) {
            $benefitIdMap[$tierId] = [];

            foreach ($benefitColumns as $cols) {
                $bName = trim($row[$cols['name']] ?? '');
                if ($bName === '') {
                    continue;
                }

                $maxUses = (int) ($row[$cols['max']] ?? 1);
                if ($maxUses <= 0) {
                    $maxUses = 1;
                }

                $benefitInsert->execute([
                    'tier_id'  => $tierId,
                    'name'     => $bName,
                    'max_uses' => $maxUses,
                ]);
                $benefitId = (int) $benefitInsert->fetchColumn();
                $benefitIdMap[$tierId][strtolower($bName)] = [
                    'id'   => $benefitId,
                    'name' => $bName,
                ];
            }
        }
    }

    // Tickets
    $ticketInsert = $db->prepare(
        'INSERT INTO tickets (
            id, physical_number, event_id, tier_id, status,
            allocated_distributor_id, allocated_distributor_name
         ) VALUES (
            :id, :physical_number, :event_id, :tier_id, :status,
            :allocated_distributor_id, :allocated_distributor_name
         )'
    );

    $scanInsert = $db->prepare(
        'INSERT INTO scans (ticket_id, benefit_id, scanned_at, station_type)
         VALUES (:ticket_id, :benefit_id, :scanned_at, :station_type)'
    );

    $ticketCount = 0;
    $scanCount = 0;

    foreach ($rows as $row) {
        $ticketId = trim($row['Ticket_ID'] ?? '');
        if ($ticketId === '') {
            continue;
        }

        $tierName = trim($row['Ticket_Type'] ?? '');
        $price = (float) ($row['Price'] ?? 0);
        $key = $tierName . '|' . number_format($price, 2, '.', '');
        $tierId = $tierMap[$key]['id'];

        $allocId = trim($row['Allocated_Distributor_ID'] ?? '');
        $allocName = trim($row['Allocated_Distributor_Name'] ?? '');

        if ($allocId === '' || strcasecmp($allocId, 'Unallocated') === 0) {
            $allocId = null;
            $allocName = $allocName !== '' ? $allocName : '';
        }

        // Verify distributor FK – null out if missing
        if ($allocId !== null) {
            $check = $db->prepare('SELECT 1 FROM distributors WHERE id = :id');
            $check->execute(['id' => $allocId]);
            if (!$check->fetch()) {
                $allocId = null;
            }
        }

        $ticketInsert->execute([
            'id'                         => $ticketId,
            'physical_number'            => (int) ($row['Physical_Ticket_ID'] ?? 0),
            'event_id'                   => $eventId,
            'tier_id'                    => $tierId,
            'status'                     => trim($row['Ticket_Status'] ?? 'Active') ?: 'Active',
            'allocated_distributor_id'   => $allocId,
            'allocated_distributor_name' => $allocName,
        ]);
        $ticketCount++;

        // Scans from log columns
        foreach ($benefitColumns as $cols) {
            $bName = trim($row[$cols['name']] ?? '');
            $logRaw = trim($row[$cols['logs']] ?? '');

            if ($bName === '' || $logRaw === '') {
                continue;
            }

            $benefitId = $benefitIdMap[$tierId][strtolower($bName)]['id'] ?? null;
            if ($benefitId === null) {
                continue;
            }

            $timestamps = array_filter(array_map('trim', explode('|', $logRaw)));
            foreach ($timestamps as $ts) {
                // Normalize timestamp format
                $parsed = date_create($ts);
                $scannedAt = $parsed ? $parsed->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');

                $scanInsert->execute([
                    'ticket_id'    => $ticketId,
                    'benefit_id'   => $benefitId,
                    'scanned_at'   => $scannedAt,
                    'station_type' => $bName,
                ]);
                $scanCount++;
            }
        }
    }

    $db->commit();

    // Archive legacy CSV files
    $archiveDir = __DIR__ . '/archive';
    if (!is_dir($archiveDir)) {
        mkdir($archiveDir, 0755, true);
    }

    $timestamp = date('Ymd_His');
    rename($registryFile, "{$archiveDir}/Master_Ticket_Registry_{$timestamp}.csv");
    rename($distributorsFile, "{$archiveDir}/distributors_{$timestamp}.csv");

    echo "SUCCESS: Migration complete.\n";
    echo "  Event:       {$eventName}\n";
    echo "  Tiers:       " . count($tierMap) . "\n";
    echo "  Tickets:     {$ticketCount}\n";
    echo "  Scans:       {$scanCount}\n";
    echo "  CSV files archived to archive/\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, "FAILURE: " . $e->getMessage() . "\n");
    exit(1);
}
