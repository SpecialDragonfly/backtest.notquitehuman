<?php

use App\Backtest\YahooFinanceClient;
use App\Controller\AdminTickerController;
use App\Controller\AuthController;
use App\Controller\DashboardController;
use App\Controller\LabController;
use App\Middleware\AdminOnlyMiddleware;
use App\Middleware\GuestBlockMiddleware;
use App\Middleware\TokenAuthMiddleware;
use App\Repository\BacktestRunRepository;
use App\Repository\MomentumHoldingsRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\TickerRepository;
use App\Repository\UserRepository;
use App\Service\AuthTokenService;
use App\Service\MomentumRotationService;
use App\Service\PriceSyncService;
use App\Service\StrategyComparisonService;
use App\Service\TickerBacktestService;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Fetch a service from the container with its concrete type verified, so
 * PHPStan (and callers) see the real type instead of ContainerInterface::get()'s
 * generic `mixed` return.
 *
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
function containerGet(ContainerInterface $c, string $class): object
{
    $service = $c->get($class);
    if (!$service instanceof $class) {
        throw new RuntimeException(sprintf(
            'Container entry "%s" did not resolve to an instance of that class.',
            $class,
        ));
    }
    return $service;
}

return [
    // -- Logging --
    // Shared by web requests and CLI scripts (bin/*.php) alike, so both go
    // through the same service instead of ad hoc echo/error_log calls.
    LoggerInterface::class => function () {
        $logger = new Logger('backtest');
        $logger->pushHandler(new StreamHandler(dirname(__DIR__) . '/var/log/error.log', Level::Info));
        return $logger;
    },

    // -- Twig --
    Environment::class => function () {
        $loader = new FilesystemLoader(dirname(__DIR__) . '/templates');
        return new Environment($loader, [
            'debug' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
            'cache' => ($_ENV['APP_ENV'] ?? 'dev') === 'prod'
                ? dirname(__DIR__) . '/var/cache/twig'
                : false,
        ]);
    },

    // -- Database --
    // DB_CONNECTION=mysql + DB_HOST/DB_NAME/DB_USER/DB_PASS/DB_PORT in .env switches
    // to MySQL (production, and local testing against the shared notquitehuman
    // MariaDB server). Same switch as phinx.php. Defaults to SQLite (local dev).
    PDO::class => function () {
        // Falls back to getenv() since PHP CLI's default variables_order (no "E")
        // leaves $_ENV empty for shell-exported vars not loaded via .env.
        $env = function (string $key, string $default): string {
            $envValue = $_ENV[$key] ?? null;
            if (is_string($envValue)) {
                return $envValue;
            }
            $getenvValue = getenv($key);
            return $getenvValue !== false && $getenvValue !== '' ? $getenvValue : $default;
        };
        if ($env('DB_CONNECTION', 'sqlite') === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $env('DB_HOST', '127.0.0.1'),
                $env('DB_PORT', '3306'),
                $env('DB_NAME', 'notquitehuman_backtest'),
            );
            $pdo = new PDO($dsn, $env('DB_USER', ''), $env('DB_PASS', ''));
        } else {
            $pdo = new PDO('sqlite:' . dirname(__DIR__) . '/var/data.db');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        return $pdo;
    },

    // -- Repositories --
    UserRepository::class             => fn(ContainerInterface $c) => new UserRepository(containerGet($c, PDO::class)),
    MomentumHoldingsRepository::class => fn(ContainerInterface $c) => new MomentumHoldingsRepository(containerGet($c, PDO::class)),
    BacktestRunRepository::class      => fn(ContainerInterface $c) => new BacktestRunRepository(containerGet($c, PDO::class)),
    PriceHistoryRepository::class     => fn(ContainerInterface $c) => new PriceHistoryRepository(containerGet($c, PDO::class)),
    TickerRepository::class           => fn(ContainerInterface $c) => new TickerRepository(containerGet($c, PDO::class)),

    // -- Services --
    YahooFinanceClient::class => fn() => new YahooFinanceClient(),
    PriceSyncService::class => fn(ContainerInterface $c) => new PriceSyncService(
        containerGet($c, YahooFinanceClient::class),
        containerGet($c, PriceHistoryRepository::class),
        containerGet($c, TickerRepository::class),
    ),
    TickerBacktestService::class => fn(ContainerInterface $c) => new TickerBacktestService(
        containerGet($c, PriceHistoryRepository::class),
    ),
    AuthTokenService::class => fn(ContainerInterface $c) => new AuthTokenService(containerGet($c, PDO::class)),
    MomentumRotationService::class => fn(ContainerInterface $c) => new MomentumRotationService(
        containerGet($c, PriceHistoryRepository::class),
        containerGet($c, TickerRepository::class),
    ),
    StrategyComparisonService::class => fn(ContainerInterface $c) => new StrategyComparisonService(
        containerGet($c, PriceHistoryRepository::class),
        containerGet($c, TickerRepository::class),
    ),

    // -- Middleware --
    TokenAuthMiddleware::class => fn(ContainerInterface $c) => new TokenAuthMiddleware(
        containerGet($c, AuthTokenService::class),
        containerGet($c, UserRepository::class),
    ),
    AdminOnlyMiddleware::class => fn() => new AdminOnlyMiddleware(),
    GuestBlockMiddleware::class => fn() => new GuestBlockMiddleware(),

    // -- Controllers --
    AuthController::class => fn(ContainerInterface $c) => new AuthController(
        containerGet($c, Environment::class),
        containerGet($c, UserRepository::class),
        containerGet($c, AuthTokenService::class),
    ),
    DashboardController::class => fn(ContainerInterface $c) => new DashboardController(
        containerGet($c, Environment::class),
        containerGet($c, TickerBacktestService::class),
        containerGet($c, TickerRepository::class),
    ),
    LabController::class => fn(ContainerInterface $c) => new LabController(
        containerGet($c, Environment::class),
        containerGet($c, MomentumRotationService::class),
        containerGet($c, StrategyComparisonService::class),
        containerGet($c, BacktestRunRepository::class),
        containerGet($c, MomentumHoldingsRepository::class),
    ),
    AdminTickerController::class => fn(ContainerInterface $c) => new AdminTickerController(
        containerGet($c, Environment::class),
        containerGet($c, TickerRepository::class),
        containerGet($c, PriceHistoryRepository::class),
        containerGet($c, PriceSyncService::class),
    ),
];
