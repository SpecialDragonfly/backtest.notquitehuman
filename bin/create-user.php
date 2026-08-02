#!/usr/bin/env php
<?php

// One-off CLI to create the (single) login user. Run this once after
// migrating: php bin/create-user.php

use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$users = containerGet($container, UserRepository::class);
$logger = containerGet($container, LoggerInterface::class);

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

if ($users->findByUsername($username) !== null) {
    fwrite(STDERR, "A user named \"{$username}\" already exists.\n");
    exit(1);
}

$users->create($username, password_hash($password, PASSWORD_DEFAULT));
$logger->info("Created user \"{$username}\".");

fwrite(STDOUT, "Created user \"{$username}\".\n");
