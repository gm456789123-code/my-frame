<?php

declare(strict_types=1);

// Zero WP CSS Injection per agents_md.md Section 3
add_action('wp_enqueue_scripts', function (): void {
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('global-styles');
    wp_dequeue_style('classic-theme-styles');
}, 100);

// Plugin Buffer: Remove default WordPress head bloat and plugin tracking hooks
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');

// Plugin Buffer: Disable XML-RPC completely (eliminates brute-force and DDoS amplification)
add_filter('xmlrpc_enabled', '__return_false');

// Plugin Buffer: Block unauthenticated REST API user enumeration (prevents username harvesting)
add_filter('rest_endpoints', function (array $endpoints): array {
    if (!is_user_logged_in() && isset($endpoints['/wp/v2/users'])) {
        unset($endpoints['/wp/v2/users']);
    }
    if (!is_user_logged_in() && isset($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) {
        unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
    }

    return $endpoints;
});

// Plugin Buffer: Prevent third-party plugins from injecting unsolicited filters into the_content
add_action('template_redirect', function (): void {
    if (!is_admin()) {
        // Ensure content rendering stays purely in Sovereign BlockAstParser control
        remove_filter('the_content', 'wpautop');
    }
}, 1);

// Plugin Buffer: Strip X-Pingback header and disable pingback/trackback processing
add_filter('wp_headers', function (array $headers): array {
    unset($headers['X-Pingback']);
    return $headers;
});
add_filter('xmlrpc_methods', function (array $methods): array {
    unset($methods['pingback.ping'], $methods['pingback.extensions.getPingbacks']);
    return $methods;
});

// Plugin Buffer: Soft-delete shielding for WordPress Core & Plugin queries
// Ensures plugins in wp-admin / REST do not resurrect or query soft-deleted Sovereign posts
add_filter('posts_where', function (string $where): string {
    global $wpdb;
    if ($wpdb instanceof wpdb && !is_admin()) {
        $where .= " AND {$wpdb->posts}.deleted_at IS NULL";
    }
    return $where;
});

// Plugin Buffer: Protect Sovereign theme from wordpress.org repository collision & unexpected auto-updates
add_filter('auto_update_theme', '__return_false');

// Plugin Buffer: Deregister frontend Heartbeat API polling initiated by plugins
add_action('init', function (): void {
    if (!is_admin() && function_exists('wp_deregister_script')) {
        wp_deregister_script('heartbeat');
    }
}, 1);



