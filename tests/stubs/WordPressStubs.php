<?php

declare(strict_types=1);

// Minimal stand-ins so the architecture test can resolve and flag these
// WordPress core symbols even when WordPress itself isn't loaded.

class WP_Post
{
}

class WP_Query
{
}

class wpdb
{
}
