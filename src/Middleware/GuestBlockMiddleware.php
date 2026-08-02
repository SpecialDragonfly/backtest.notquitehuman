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
 * 'user' request attribute) on any route the guest account shouldn't reach —
 * currently the Lab, which isn't part of the guest's limited demo.
 */
class GuestBlockMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');

        if (!($user instanceof User && $user->isGuest())) {
            return $handler->handle($request);
        }

        if ($request->getMethod() === 'GET') {
            return (new Response(302))->withHeader('Location', '/');
        }

        $response = new Response(403);
        $body = json_encode(['error' => 'Forbidden']);
        $response->getBody()->write($body === false ? '{"error":"Forbidden"}' : $body);
        return $response->withHeader('Content-Type', 'application/json');
    }
}
