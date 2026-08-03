<?php

declare(strict_types=1);

/**
 * Shared UI helpers for PassGate Night Gate theme.
 */

function passgateAssetUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return $relativePath;
}

/**
 * Render common <head> tags for Night Gate pages.
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
