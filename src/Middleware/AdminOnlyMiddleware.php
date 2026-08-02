<?php

namespace App\Middleware;

use App\Domain\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Layered on top of TokenAuthMiddleware (which must run first and set the
 * 'user' request attribute) on any route that should be admin-only.
 */
class AdminOnlyMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');

        if ($user instanceof User && $user->isAdmin()) {
            return $handler->handle($request);
        }

        if ($request->getMethod() === 'GET') {
            // No user at all shouldn't happen here (TokenAuthMiddleware runs
            // first and would already have redirected), but fall back to
            // /login rather than / just in case; a logged-in non-admin goes
            // back to the dashboard instead of a confusing re-login prompt.
            $location = $user instanceof User ? '/' : '/login';
            return (new Response(302))->withHeader('Location', $location);
        }

        $response = new Response(403);
        $body = json_encode(['error' => 'Forbidden']);
        $response->getBody()->write($body === false ? '{"error":"Forbidden"}' : $body);
        return $response->withHeader('Content-Type', 'application/json');
    }
}
