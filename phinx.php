<?php

declare(strict_types=1);

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'local',
        'local' => [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? 'mysql',
            'name' => $_ENV['DB_DATABASE'] ?? 'wordpress',
            'user' => $_ENV['DB_USERNAME'] ?? 'wordpress',
            'pass' => $_ENV['DB_PASSWORD'] ?? 'wordpress',
            'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
            'charset' => 'utf8mb4',
        ],
    ],
];
