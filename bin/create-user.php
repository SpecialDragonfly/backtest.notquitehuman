#!/usr/bin/env php
<?php

// One-off CLI to create the (single) login user. Run this once after
// migrating: php bin/create-user.php

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

function env(string $key, string $default): string
{
    $envValue = $_ENV[$key] ?? null;
    if (is_string($envValue)) {
        return $envValue;
    }
    $getenvValue = getenv($key);
    return $getenvValue !== false && $getenvValue !== '' ? $getenvValue : $default;
}

if (env('DB_CONNECTION', 'sqlite') === 'mysql') {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', '127.0.0.1'),
        env('DB_PORT', '3306'),
        env('DB_NAME', 'notquitehuman_backtest'),
    );
    $pdo = new PDO($dsn, env('DB_USER', ''), env('DB_PASS', ''));
} else {
    $pdo = new PDO('sqlite:' . dirname(__DIR__) . '/var/data.db');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

fwrite(STDOUT, "Username: ");
$username = trim((string) fgets(STDIN));

fwrite(STDOUT, "Password: ");
system('stty -echo');
$password = trim((string) fgets(STDIN));
system('stty echo');
fwrite(STDOUT, "\n");

if ($username === '' || $password === '') {
    fwrite(STDERR, "Username and password are both required.\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute([$username]);
if ($stmt->fetch() !== false) {
    fwrite(STDERR, "A user named \"{$username}\" already exists.\n");
    exit(1);
}

$pdo->prepare('INSERT INTO users (username, password, created) VALUES (?, ?, ?)')
    ->execute([$username, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);

fwrite(STDOUT, "Created user \"{$username}\".\n");
