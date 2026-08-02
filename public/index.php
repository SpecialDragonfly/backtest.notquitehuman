<?php

use Slim\Factory\AppFactory;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$container = require dirname(__DIR__) . '/config/bootstrap.php';

AppFactory::setContainer($container);
$app = AppFactory::create();

$middleware = require dirname(__DIR__) . '/config/middleware.php';
if (!is_callable($middleware)) {
    throw new \RuntimeException('config/middleware.php must return a callable.');
}
$middleware($app);

$routes = require dirname(__DIR__) . '/config/routes.php';
if (!is_callable($routes)) {
    throw new \RuntimeException('config/routes.php must return a callable.');
}
$routes($app);

$app->run();
