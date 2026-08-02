<?php

namespace App\Middleware;

use App\Repository\UserRepository;
use App\Service\AuthTokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class TokenAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthTokenService $authTokenService,
        private UserRepository $userRepository,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $rawToken = substr($authHeader, 7);
        } else {
            $cookieToken = $request->getCookieParams()['backtest_token'] ?? null;
            $rawToken = is_string($cookieToken) ? $cookieToken : null;
        }

        if ($rawToken !== null) {
            $userId = $this->authTokenService->validateToken($rawToken);

            if ($userId !== null) {
                $user = $this->userRepository->findById($userId);
                if ($user !== null) {
                    return $handler->handle($request->withAttribute('user', $user));
                }
            }
        }

        // GET requests get a redirect; API calls (non-GET) get a 401 JSON response.
        if ($request->getMethod() === 'GET') {
            return (new Response(302))->withHeader('Location', '/login');
        }

        $response = new Response(401);
        $body = json_encode(['error' => 'Unauthorized']);
        $response->getBody()->write($body === false ? '{"error":"Unauthorized"}' : $body);
        return $response->withHeader('Content-Type', 'application/json');
    }
}
