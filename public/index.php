<?php

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$definitions = require dirname(__DIR__) . '/config/container.php';
if (!is_array($definitions) && !$definitions instanceof \DI\Definition\Source\DefinitionSource) {
    throw new \RuntimeException('config/container.php must return an array or DefinitionSource.');
}

$builder = new ContainerBuilder();
$builder->addDefinitions($definitions);
try {
    $container = $builder->build();
} catch (Exception $e) {
    error_log(print_r($e, true));
    throw $e;
}

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
