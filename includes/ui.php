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
 * Shared public site header.
 * Destinations: Events · Buy · Account · Staff
 *
 * @param 'home'|'events'|'buy'|'account'|'staff' $active
 */
function passgateRenderPublicNav(string $active = 'home'): void
{
    $isCustomer = function_exists('isCustomerAuthenticated') && isCustomerAuthenticated();
    $isAdmin = function_exists('isDistributorAuthenticated')
        && isDistributorAuthenticated()
        && (($_SESSION['distributor_role'] ?? '') === 'admin');
    $isStall = function_exists('isStallAuthenticated') && isStallAuthenticated();

    $link = static function (string $href, string $label, string $key, string $active, string $icon = ''): string {
        $cls = $key === $active ? 'pg-site-nav__link is-active' : 'pg-site-nav__link';
        $iconHtml = $icon !== '' ? '<i class="' . htmlspecialchars($icon) . '" aria-hidden="true"></i> ' : '';
        return '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '">' . $iconHtml . htmlspecialchars($label) . '</a>';
    };

    if ($isCustomer) {
        $accountHref = 'customer_dashboard.php';
        $accountLabel = 'My tickets';
    } else {
        $accountHref = 'customer_register.php';
        $accountLabel = 'Account';
    }

    if ($isAdmin) {
        $staffHref = 'distributors.php';
        $staffLabel = 'Admin';
    } elseif ($isStall) {
        $staffHref = 'terminal.php';
        $staffLabel = 'Scanner';
    } else {
        $staffHref = 'terminal.php';
        $staffLabel = 'Staff';
    }

    echo '<header class="pg-site-nav">';
    echo '<a class="pg-site-nav__brand" href="index.php">';
    echo '<span class="pg-brand-mark" style="width:2.15rem;height:2.15rem;border-radius:0.7rem;font-size:0.85rem;"><i class="fa-solid fa-ticket"></i></span>';
    echo '<span class="pg-brand" style="font-size:1.15rem;">PassGate</span>';
    echo '</a>';
    echo '<nav class="pg-site-nav__links" aria-label="Main">';
    echo $link('events.php', 'Events', 'events', $active, 'fa-solid fa-calendar-days');
    echo $link('buy.php', 'Buy', 'buy', $active);
    echo $link($accountHref, $accountLabel, 'account', $active);
    echo $link($staffHref, $staffLabel, 'staff', $active);
    echo '</nav>';
    echo '</header>';
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
    ];

    if ($next === '' || !in_array($next, $allowed, true)) {
        return $default;
    }

    return $next;
}
