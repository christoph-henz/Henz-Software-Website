<?php

declare(strict_types=1);

use App\Core\Config\ConfigRepository;
use App\Core\Config\EnvLoader;
use App\Core\Container\Container;
use App\Core\Database\ConnectionManager;
use App\Core\Database\Database;
use App\Core\Error\ErrorHandler;
use App\Core\Http\Application;
use App\Core\Http\Kernel;
use App\Core\Logging\Logger;
use App\Core\Routing\Router;
use App\Core\Routing\RouterFacade;
use App\Core\Security\EncryptionManager;

require_once base_path('vendor/autoload.php');

$envLoader = new EnvLoader();
$envLoader->load(base_path('.env'));
$envLoader->load(base_path('.env.local'));

$sessionName = trim((string) env('SESSION_COOKIE_NAME', 'GBSESSID'));
if ($sessionName !== '') {
    ini_set('session.name', $sessionName);
}

$sessionSavePath = trim((string) env('SESSION_SAVE_PATH', base_path('storage/sessions')));
if ($sessionSavePath !== '') {
    if (!is_dir($sessionSavePath)) {
        @mkdir($sessionSavePath, 0777, true);
    }
    if (is_dir($sessionSavePath) && is_writable($sessionSavePath)) {
        ini_set('session.save_path', $sessionSavePath);
    }
}

ini_set('session.cookie_path', '/');

$sessionCookieDomain = trim((string) env('SESSION_COOKIE_DOMAIN', ''));
if ($sessionCookieDomain === '') {
    $httpHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $hostOnly = preg_replace('/:\\d+$/', '', $httpHost);
    $hostOnly = is_string($hostOnly) ? $hostOnly : $httpHost;

    if ($hostOnly === 'getragen-begleiten.local' || str_ends_with($hostOnly, '.getragen-begleiten.local')) {
        $sessionCookieDomain = '.getragen-begleiten.local';
    } elseif ($hostOnly === 'getragen-begleiten.com' || str_ends_with($hostOnly, '.getragen-begleiten.com')) {
        $sessionCookieDomain = '.getragen-begleiten.com';
    }
}
if ($sessionCookieDomain !== '') {
    ini_set('session.cookie_domain', $sessionCookieDomain);
}

$config = ConfigRepository::instance();
$config->load(base_path('app/Config'), base_path('storage/cache/config.php'));
date_default_timezone_set((string) config('app.timezone', 'UTC'));

$container = new Container();
$container->singleton(Container::class, fn () => $container);
$container->singleton(ConnectionManager::class, fn () => new ConnectionManager());
$container->singleton(Database::class, fn (Container $c) => new Database($c->get(ConnectionManager::class)));
$container->singleton(Logger::class, fn () => new Logger(
    (string) config('app.log_path', base_path('storage/logs/app.log')),
    (string) config('app.log_level', 'debug')
));
$container->singleton(EncryptionManager::class, fn () => new EncryptionManager());

$router = new Router();
$kernel = new Kernel();

// Make container globally available for helpers like app(), db()
global $_app_container;
$_app_container = $container;

$kernel->setGlobal([
    App\Middleware\CorsMiddleware::class,
    App\Middleware\SecurityHeadersMiddleware::class,
    App\Middleware\RequestLoggingMiddleware::class,
]);

$kernel->alias('auth', App\Middleware\AuthMiddleware::class);
$kernel->alias('throttle', App\Middleware\RateLimitMiddleware::class);
$kernel->alias('admin_session', App\Middleware\AdminApiMiddleware::class);

RouterFacade::setRouter($router);

require base_path('routes/web.php');
require base_path('routes/admin.php');
require base_path('routes/api.php');

$errorHandler = new ErrorHandler((bool) config('app.debug', false), $container->get(Logger::class));
$errorHandler->register();

return [$container, new Application($container, $router, $kernel), $errorHandler];
