<?php

// Shared CLI/web bootstrap: loads .env and builds the DI container from
// config/container.php. Callers must require vendor/autoload.php first.

use DI\ContainerBuilder;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$definitions = require __DIR__ . '/container.php';
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

return $container;
