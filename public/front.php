<?php

declare(strict_types=1);

// Sovereign Engine front controller for public pages. Deliberately does
// NOT load WordPress at all (no wp-load.php/wp-settings.php): nginx routes
// /wp-admin, /wp-login.php, /wp-cron.php etc. to WordPress's own files
// unchanged, and routes everything else here, so a normal page view never
// pays for a full WP boot + WP_Query just to be overridden afterwards.

require_once '/var/www/app/vendor/autoload.php';

use App\Application\UseCase\Page\GetLatestPublishedPageId;
use App\Application\UseCase\Page\GetPage;
use App\Infrastructure\Cache\RedisTaggedCache;
use App\Infrastructure\Env;
use App\Infrastructure\Wordpress\Gutenberg\BlockAstParser;
use App\Infrastructure\Wordpress\WpdbConnection;
use App\Infrastructure\Wordpress\WpPageRepository;
use App\Presentation\Http\PageController;
use App\Presentation\View\BlockRenderer;
use Latte\Engine;

$pdo = WpdbConnection::get();
$repository = new WpPageRepository($pdo);

$cache = null;
try {
    $redis = new Redis();
    $redis->connect(Env::string('REDIS_HOST', 'redis'), Env::int('REDIS_PORT', 6379), 1.0);
    $cache = new RedisTaggedCache($redis);
} catch (Throwable) {
    $cache = null;
}

$latte = new Engine();
$latte->setTempDirectory(sys_get_temp_dir());

$controller = new PageController(
    new GetPage($repository),
    new BlockAstParser(Env::string('APP_URL', '')),
    new BlockRenderer($latte, '/var/www/app/templates/blocks'),
    $cache,
    $pdo,
    Env::string('APP_ENV', 'local') === 'local',
);

$pageIdParam = $_GET['page_id'] ?? $_GET['p'] ?? null;
$pageId = is_string($pageIdParam) && ctype_digit($pageIdParam) ? (int) $pageIdParam : null;

if ($pageId === null) {
    $latest = (new GetLatestPublishedPageId($repository))->handle();
    $pageId = $latest->id?->value;
}

if ($pageId === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><body><h1>Nothing published yet</h1></body></html>';
    exit;
}

$response = $controller->show($pageId);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header("{$name}: {$value}");
}
echo $response['body'];
