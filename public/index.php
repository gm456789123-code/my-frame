<?php

declare(strict_types=1);

// Sovereign Framework Unified Entrypoint

// 1. If WordPress core is installed and ready, boot WordPress (which loads the Sovereign theme)
if (file_exists(__DIR__ . '/wp-blog-header.php')) {
    define('WP_USE_THEMES', true);
    require_once __DIR__ . '/wp-blog-header.php';
    exit;
}

// 2. Otherwise, serve the Sovereign Framework Standalone Installer & Starter Portal
require_once '/var/www/app/themes/sovereign/index.php';
