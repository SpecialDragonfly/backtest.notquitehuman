<?php

use Slim\App;

return function (App $app) {
    $app->addBodyParsingMiddleware();
    $app->addRoutingMiddleware();
    $app->addErrorMiddleware(
        displayErrorDetails: ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
        logErrors: true,
        logErrorDetails: true,
    );
};
