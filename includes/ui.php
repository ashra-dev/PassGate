<?php

declare(strict_types=1);

/**
 * Shared UI helpers for PassGate.
 */

function passgateAssetUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return $relativePath;
}

/**
 * Render common <head> tags.
 */
function passgateRenderHead(string $title, array $options = []): void
{
    $includeFa = $options['fontawesome'] ?? true;
    $extra = $options['extra'] ?? '';
    $cssPath = passgateAssetUrl('assets/css/passgate.css');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    echo <<<HTML
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>{$safeTitle}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="{$cssPath}">

HTML;

    if ($includeFa) {
        echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">' . "\n";
    }

    if (is_string($extra) && $extra !== '') {
        echo $extra . "\n";
    }
}

/**
 * Whether a verified customer session is active (session + DB check).
 */
function passgateHasVerifiedCustomer(): bool
{
    if (!function_exists('isCustomerAuthenticated') || !isCustomerAuthenticated()) {
        return false;
    }

    try {
        $db = getDb();

        return getAuthenticatedCustomer($db) !== null;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Shared public site header.
 *
 * @param 'home'|'events'|'buy'|'account'|'validate'|'staff'|'admin' $active
 */
function passgateRenderPublicNav(string $active = 'home'): void
{
    $isCustomer = passgateHasVerifiedCustomer();
    $isAdmin = function_exists('isDistributorAuthenticated')
        && isDistributorAuthenticated()
        && (($_SESSION['distributor_role'] ?? '') === 'admin');
    $isDistributor = function_exists('isDistributorAuthenticated')
        && isDistributorAuthenticated()
        && (($_SESSION['distributor_role'] ?? '') === 'distributor');

    $link = static function (
        string $href,
        string $label,
        string $key,
        string $active,
        string $icon = '',
        string $extraClass = ''
    ): string {
        $cls = 'pg-site-nav__link';
        if ($key === $active) {
            $cls .= ' is-active';
        }
        if ($extraClass !== '') {
            $cls .= ' ' . $extraClass;
        }
        $iconHtml = $icon !== '' ? '<i class="' . htmlspecialchars($icon) . '" aria-hidden="true"></i> ' : '';

        return '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '">'
            . $iconHtml . htmlspecialchars($label) . '</a>';
    };

    echo '<header class="pg-site-nav">';
    echo '<a class="pg-site-nav__brand" href="index.php">';
    echo '<span class="pg-brand-mark" style="width:2.15rem;height:2.15rem;border-radius:0.7rem;font-size:0.85rem;"><i class="fa-solid fa-ticket"></i></span>';
    echo '<span class="pg-brand" style="font-size:1.15rem;">PassGate</span>';
    echo '</a>';
    echo '<nav class="pg-site-nav__links" aria-label="Main">';

    echo $link('index.php', 'Home', 'home', $active, 'fa-solid fa-house');
    echo $link('events.php', 'Events', 'events', $active, 'fa-solid fa-calendar-days');
    echo $link('buy.php', 'Buy tickets', 'buy', $active, 'fa-solid fa-cart-shopping');

    echo '<span class="pg-site-nav__sep" aria-hidden="true"></span>';

    if ($isCustomer) {
        echo $link('customer_dashboard.php', 'My tickets', 'account', $active, 'fa-solid fa-qrcode');
        echo $link('customer_logout.php', 'Log out', 'logout', $active, 'fa-solid fa-right-from-bracket', 'pg-site-nav__link--muted');
    } else {
        echo $link('customer_login.php?next=customer_dashboard.php', 'Log in', 'account', $active, 'fa-solid fa-right-to-bracket');
        echo $link('customer_register.php?next=buy.php', 'Sign up', 'signup', $active, '', 'pg-site-nav__link--muted');
    }

    echo '<span class="pg-site-nav__sep" aria-hidden="true"></span>';

    if ($isAdmin) {
        echo $link('distributors.php', 'Admin dashboard', 'admin', $active, 'fa-solid fa-gauge-high');
    } elseif ($isDistributor) {
        echo $link('distributor_dashboard.php', 'Distributor dashboard', 'staff', $active, 'fa-solid fa-building');
    } else {
        echo $link('admin_login.php', 'Admin login', 'admin', $active, 'fa-solid fa-gauge-high');
    }
    echo $link('staff_login.php', 'Staff login', 'staff-login', $active, 'fa-solid fa-id-badge');

    echo '</nav>';
    echo '</header>';
}

/**
 * Breadcrumb trail for nested public pages.
 *
 * @param list<array{label: string, href?: ?string}> $items Last item may omit href (current page).
 */
function passgateRenderBreadcrumb(array $items): void
{
    if ($items === []) {
        return;
    }

    echo '<nav class="pg-breadcrumb" aria-label="Breadcrumb"><ol>';
    $lastIndex = count($items) - 1;

    foreach ($items as $index => $item) {
        $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
        $href = $item['href'] ?? null;
        $isCurrent = $index === $lastIndex || $href === null || $href === '';

        echo '<li>';
        if (!$isCurrent && is_string($href) && $href !== '') {
            echo '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
        } else {
            echo '<span aria-current="page">' . $label . '</span>';
        }
        echo '</li>';
    }

    echo '</ol></nav>';
}

/**
 * Compact staff-area navigation (scanner, lookup, admin login).
 *
 * @param 'scanner'|'validate'|'staff-login'|'distributor-login'|'stall'|'admin-login'|'admin-token' $active
 */
function passgateRenderStaffNav(string $active = 'scanner'): void
{
    $link = static function (string $href, string $label, string $key, string $active, string $icon = ''): string {
        $cls = $key === $active ? 'pg-staff-nav__link is-active' : 'pg-staff-nav__link';
        $iconHtml = $icon !== '' ? '<i class="' . htmlspecialchars($icon) . '" aria-hidden="true"></i> ' : '';

        return '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '">' . $iconHtml . htmlspecialchars($label) . '</a>';
    };

    echo '<nav class="pg-staff-nav" aria-label="Staff">';
    echo $link('index.php', 'Home', 'home', $active, 'fa-solid fa-house');
    echo $link('staff_login.php', 'Staff login', 'staff-login', $active, 'fa-solid fa-id-badge');
    echo $link('distributor_login.php', 'Distributor login', 'distributor-login', $active, 'fa-solid fa-building');
    echo $link('admin_login.php', 'Admin login', 'admin-login', $active, 'fa-solid fa-gauge-high');
    echo $link('manual_login.php', 'Paste token', 'admin-token', $active, 'fa-solid fa-key');
    echo '</nav>';
}

/**
 * Footer links on customer-facing pages.
 */
function passgateRenderPublicFooter(): void
{
    $isCustomer = passgateHasVerifiedCustomer();
    $isAdmin = function_exists('isDistributorAuthenticated')
        && isDistributorAuthenticated()
        && (($_SESSION['distributor_role'] ?? '') === 'admin');
    $isDistributor = function_exists('isDistributorAuthenticated')
        && isDistributorAuthenticated()
        && (($_SESSION['distributor_role'] ?? '') === 'distributor');

    echo '<footer class="pg-public-footer">';
    echo '<nav class="pg-public-footer__links" aria-label="Footer">';
    echo '<a href="index.php">Home</a>';
    echo '<a href="events.php">Events</a>';
    echo '<a href="buy.php">Buy tickets</a>';
    if ($isCustomer) {
        echo '<a href="customer_dashboard.php">My tickets</a>';
    } else {
        echo '<a href="customer_login.php">Log in</a>';
    }
    if ($isAdmin) {
        echo '<a href="distributors.php">Admin dashboard</a>';
    } elseif ($isDistributor) {
        echo '<a href="distributor_dashboard.php">Distributor dashboard</a>';
    } else {
        echo '<a href="admin_login.php">Admin login</a>';
    }
    echo '<a href="distributor_login.php">Distributor login</a>';
    echo '<a href="staff_login.php">Staff login</a>';
    echo '</nav>';
    echo '</footer>';
}

/**
 * Allow only safe in-app next redirects after login/register.
 */
function passgateSafeNextUrl(?string $next, string $default = 'customer_dashboard.php'): string
{
    $next = trim((string) $next);
    $allowed = [
        'buy.php',
        'events.php',
        'customer_dashboard.php',
        'index.php',
        'thankyou.php',
        'terminal.php',
        'staff_login.php',
    ];

    if ($next === '' || !in_array($next, $allowed, true)) {
        return $default;
    }

    return $next;
}
