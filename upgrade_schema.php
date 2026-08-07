<?php

declare(strict_types=1);

/**
 * Apply incremental schema upgrades (safe to run multiple times).
 *
 * Usage: php upgrade_schema.php
 */

require_once __DIR__ . '/includes/functions.php';

try {
    $db = getDb();
    ensureStallsSchema($db);
    ensureCategorySchema($db);
    ensureCustomerSchema($db);
    ensureDistributorsSchema($db);
    echo "SUCCESS: Schema upgrades applied.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILURE: ' . $e->getMessage() . "\n");
    exit(1);
}
