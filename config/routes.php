<?php

use App\Controller\AuthController;
use App\Controller\DashboardController;
use App\Controller\LabController;
use App\Middleware\TokenAuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app) {
    // -- Housekeeping --
    $app->get('/favicon.ico', function (Request $req, Response $res) { return $res->withStatus(204); });

    // -- Auth (public) --
    $app->get('/login',  [AuthController::class, 'loginPage']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->post('/logout',[AuthController::class, 'logout']);

    // -- Dashboard / rebalance tool (auth required) --
    $app->get('/',                 [DashboardController::class, 'index'])->add(TokenAuthMiddleware::class);
    $app->post('/portfolio/apply', [DashboardController::class, 'apply'])->add(TokenAuthMiddleware::class);

    // -- Lab (auth required) --
    $app->get('/lab',         [LabController::class, 'index'])->add(TokenAuthMiddleware::class);
    $app->post('/lab/run',    [LabController::class, 'run'])->add(TokenAuthMiddleware::class);
    $app->get('/lab/custom',  [LabController::class, 'customForm'])->add(TokenAuthMiddleware::class);
    $app->post('/lab/custom', [LabController::class, 'customRun'])->add(TokenAuthMiddleware::class);
};
