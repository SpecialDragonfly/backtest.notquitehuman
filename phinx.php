<?php

require_once __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

// Same DB_CONNECTION switch as config/container.php — 'sqlite' (default, local
// dev) or 'mysql' (production, and local testing against the shared
// notquitehuman MariaDB server). Falls back to getenv() since PHP CLI's
// default variables_order (no "E") leaves $_ENV empty for shell-exported
// vars that didn't come through a loaded .env file.
$env = fn(string $key, string $default = null) => $_ENV[$key] ?? (getenv($key) ?: $default);

$connection = $env('DB_CONNECTION', 'sqlite');

$environment = $connection === 'mysql'
    ? [
        'adapter' => 'mysql',
        'host'    => $env('DB_HOST', '127.0.0.1'),
        'name'    => $env('DB_NAME', 'notquitehuman_backtest'),
        'user'    => $env('DB_USER', ''),
        'pass'    => $env('DB_PASS', ''),
        'port'    => $env('DB_PORT', '3306'),
        'charset' => 'utf8mb4',
    ]
    : [
        'adapter' => 'sqlite',
        'name'    => __DIR__ . '/var/data',
        'suffix'  => '.db',
    ];

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds'      => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment'     => 'default',
        'default'                 => $environment,
    ],
    'version_order' => 'creation',
];
