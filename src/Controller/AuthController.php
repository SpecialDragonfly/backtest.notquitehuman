<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\AuthTokenService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class AuthController
{
    public function __construct(
        private Environment $twig,
        private UserRepository $userRepository,
        private AuthTokenService $authTokenService,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function loginPage(Request $request, Response $response, array $args): Response
    {
        $response->getBody()->write($this->twig->render('auth/login.html.twig', []));
        return $response;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function login(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody();

        $username = '';
        $password = '';
        if (is_array($body)) {
            if (isset($body['username']) && is_string($body['username'])) {
                $username = trim($body['username']);
            }
            if (isset($body['password']) && is_string($body['password'])) {
                $password = $body['password'];
            }
        }

        $user = $this->userRepository->findByUsername($username);

        if ($user === null || !password_verify($password, $user->getPasswordHash())) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Invalid credentials'], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $token = $this->authTokenService->generateToken($user->getId());
        $response->getBody()->write(json_encode(['success' => true], JSON_THROW_ON_ERROR));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Set-Cookie', 'backtest_token=' . $token . '; Path=/; HttpOnly; SameSite=Lax');
    }

    /**
     * No credentials needed — issues a token straight for the seeded 'guest'
     * account (see db/migrations/0008_seed_guest_user.php). Dashboard access
     * for that account is then limited to a fixed symbol list.
     *
     * @param array<string, mixed> $args
     */
    public function guestLogin(Request $request, Response $response, array $args): Response
    {
        $user = $this->userRepository->findByUsername('guest');

        if ($user === null) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Guest login is not available'], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $token = $this->authTokenService->generateToken($user->getId());
        $response->getBody()->write(json_encode(['success' => true], JSON_THROW_ON_ERROR));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Set-Cookie', 'backtest_token=' . $token . '; Path=/; HttpOnly; SameSite=Lax');
    }

    /**
     * @param array<string, mixed> $args
     */
    public function logout(Request $request, Response $response, array $args): Response
    {
        $cookieToken = $request->getCookieParams()['backtest_token'] ?? null;
        if (is_string($cookieToken)) {
            $this->authTokenService->revokeToken($cookieToken);
        }
        $response->getBody()->write(json_encode(['success' => true], JSON_THROW_ON_ERROR));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Set-Cookie', 'backtest_token=; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; SameSite=Lax');
    }
}
