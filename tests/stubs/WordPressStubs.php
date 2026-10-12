<?php

declare(strict_types=1);

// Deprecated and unused: this used to declare global WP_Post/WP_Query/wpdb
// stub classes so a (now-abandoned) approach to the architecture test could
// resolve them. It was registered as a composer.json autoload-dev "files"
// entry, which loads on every `vendor/autoload.php` include -- not just
// during tests -- and its `class wpdb {}` collided with WordPress's own
// real wpdb class once WordPress core was actually installed, causing a
// fatal "Cannot declare class wpdb" error on every real WP-CLI/WordPress
// request. tests/Architecture/DomainPurityTest.php now scans source files
// for these symbols directly instead of relying on class resolution, so
// nothing needs this file; kept empty (not deleted) pending cleanup.
