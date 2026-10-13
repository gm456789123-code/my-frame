<?php

declare(strict_types=1);

// Sovereign Engine front controller for public pages. Deliberately does
// NOT load WordPress at all (no wp-load.php/wp-settings.php): nginx routes
// /wp-admin, /wp-login.php, /wp-cron.php etc. to WordPress's own files
// unchanged, and routes everything else here, so a normal page view never
// pays for a full WP boot + WP_Query just to be overridden afterwards.

// Self-locating: this file always lives at <project-root>/public/front.php,
// so the project root is always one level up, regardless of where the
// project was cloned/deployed -- no path needs editing here.
$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/vendor/autoload.php';

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

$siteUrl = rtrim(Env::string('APP_URL', 'http://localhost:8080'), '/');
$logoUrl = $siteUrl . '/wp-content/themes/sovereign/assets/logo.png';

$latte = new Engine();
$latte->setTempDirectory(sys_get_temp_dir());

// Everything that needs WordPress's schema (wp_posts etc.) is wrapped in
// one try/catch: if it isn't installed yet, that's a missing-table
// PDOException, not a special case we check for up front -- checking "is
// WordPress installed" on every request would cost a DB query even on
// warm/cached hits, defeating the whole point of this bypass.
try {
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

    $controller = new PageController(
        getPage: new GetPage($repository),
        parser: new BlockAstParser(Env::string('APP_URL', '')),
        renderer: new BlockRenderer($latte, $projectRoot . '/templates/blocks'),
        cache: $cache,
        pdo: $pdo,
        isLocal: Env::string('APP_ENV', 'local') === 'local',
        latte: $latte,
        layoutPath: $projectRoot . '/templates/layout.latte',
        siteUrl: $siteUrl,
        adminUrl: $siteUrl . '/wp-admin/',
        logoUrl: $logoUrl,
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
        echo $latte->renderToString($projectRoot . '/templates/layout.latte', [
            'locale' => 'en',
            'title' => 'Nothing published yet',
            'content' => '<p>Head to <a href="' . htmlspecialchars($siteUrl . '/wp-admin/') . '">wp-admin</a> to publish your first post.</p>',
            'siteName' => 'Sovereign Engine',
            'siteUrl' => $siteUrl,
            'adminUrl' => $siteUrl . '/wp-admin/',
            'logoUrl' => $logoUrl,
            'phpVersion' => PHP_VERSION,
        ]);
        exit;
    }

    $response = $controller->show($pageId);

    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header("{$name}: {$value}");
    }
    echo $response['body'];
} catch (Throwable) {
    // WordPress isn't installed yet (wp_posts doesn't exist) or the
    // database is unreachable -- either way, there's nothing to render
    // except "go run the installer".
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo $latte->renderToString($projectRoot . '/templates/starter.latte', [
        'siteName' => 'Sovereign Engine',
        'logoUrl' => $logoUrl,
        'phpVersion' => PHP_VERSION,
    ]);
}
