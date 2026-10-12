<?php

declare(strict_types=1);

namespace App\Infrastructure\Wordpress;

use App\Infrastructure\Env;
use PDO;

final class WpdbConnection
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $host = Env::string('DB_HOST', 'mysql');
            $port = Env::string('DB_PORT', '3306');
            $database = Env::string('DB_DATABASE', 'wordpress');
            $username = Env::string('DB_USERNAME', 'wordpress');
            $password = Env::string('DB_PASSWORD', 'wordpress');

            self::$instance = new PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        }

        return self::$instance;
    }
}
