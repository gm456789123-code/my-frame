<?php

declare(strict_types=1);

namespace Tests\Infrastructure;

use App\Infrastructure\Wordpress\WpPageRepository;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class WpPageRepositoryTestCase extends TestCase
{
    public PDO $pdo;
    public WpPageRepository $repository;

    /** @var list<int> */
    public array $createdIds = [];
}
