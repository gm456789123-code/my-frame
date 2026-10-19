<?php

declare(strict_types=1);

/**
 * Psalm stub for ext-redis to satisfy static analysis in environments without php-redis.
 */
class Redis
{
    public function connect(string $host, int $port = 6379, float $timeout = 0.0): bool
    {
        return true;
    }

    /**
     * @return string|false
     */
    public function get(string $key): string|false
    {
        return false;
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return true;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        return true;
    }

    /**
     * @param string|list<string> $key
     */
    public function del(string|array $key, string ...$otherKeys): int
    {
        return 1;
    }

    public function sAdd(string $key, string ...$members): int
    {
        return 1;
    }

    /**
     * @return list<string>
     */
    public function sMembers(string $key): array
    {
        return [];
    }

    /**
     * @param array<string, string> $streams
     * @return array<string, array<string, array<string, string>>>|false
     */
    public function xRead(array $streams, ?int $count = null, ?int $block = null): array|false
    {
        return [];
    }

    /**
     * @param array<string, string> $message
     */
    public function xAdd(string $stream, string $id, array $message): string|false
    {
        return '0-0';
    }

    /**
     * @param list<string> $ids
     */
    public function xDel(string $stream, array $ids): int
    {
        return 1;
    }
}
