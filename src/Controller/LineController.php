<?php

namespace App\Controller;

use App\Domain\User;
use App\Line\FormulaEvaluator;
use App\Repository\LineRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Twig\Environment;

/**
 * CRUD for a logged-in user's own Lines — see App\Line\FormulaEvaluator for
 * what a formula can express. Guest-blocked at the route level (routes.php),
 * since the guest account is a single shared row and per-user state doesn't
 * make sense there.
 */
class LineController
{
    public function __construct(
        private Environment $twig,
        private LineRepository $lineRepository,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();

        $response->getBody()->write($this->twig->render('lines/index.html.twig', [
            'isAdmin' => $user->isAdmin(),
            'lines' => $this->lineRepository->allForUser($user->getId()),
            'error' => $params['error'] ?? null,
            'added' => $params['added'] ?? null,
        ]));
        return $response;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function create(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $name = trim(is_array($body) && is_string($body['name'] ?? null) ? $body['name'] : '');
        $fn = strtoupper(trim(is_array($body) && is_string($body['fn'] ?? null) ? $body['fn'] : ''));

        if ($name === '') {
            return $this->redirectWithError($response, 'Give the line a name.');
        }
        if (!in_array($fn, FormulaEvaluator::FUNCTIONS, true)) {
            return $this->redirectWithError($response, 'Pick a valid function.');
        }

        $formula = ['fn' => $fn];
        if ($fn === 'CONSTANT') {
            $value = is_array($body) ? ($body['value'] ?? null) : null;
            if (!is_numeric($value)) {
                return $this->redirectWithError($response, 'Constant lines need a numeric value.');
            }
            $formula['value'] = (float) $value;
        } elseif ($fn !== 'PRICE') {
            $period = is_array($body) ? ($body['period'] ?? null) : null;
            if (!is_numeric($period) || (int) $period < 1) {
                return $this->redirectWithError($response, ucfirst(strtolower($fn)) . ' needs a positive whole-number period.');
            }
            $formula['period'] = (int) $period;
        }

        $this->lineRepository->create($user->getId(), $name, $formula);

        return $response->withHeader('Location', '/lines?added=' . urlencode("Added \"{$name}\""))->withStatus(302);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $id = (int) ($args['id'] ?? 0);
        $line = $this->lineRepository->find($id);

        if ($line === null || $line->getUserId() !== $user->getId()) {
            return $this->redirectWithError($response, 'Line not found.');
        }
        if ($this->lineRepository->isReferencedByTrigger($id)) {
            return $this->redirectWithError($response, "\"{$line->getName()}\" is used by a trigger — delete the trigger first.");
        }

        $this->lineRepository->delete($id);

        return $response->withHeader('Location', '/lines?added=' . urlencode("Deleted \"{$line->getName()}\""))->withStatus(302);
    }

    private function requireUser(Request $request): User
    {
        $user = $request->getAttribute('user');
        if (!$user instanceof User) {
            throw new RuntimeException('LineController reached without an authenticated user — check route middleware.');
        }
        return $user;
    }

    private function redirectWithError(Response $response, string $message): Response
    {
        return $response->withHeader('Location', '/lines?error=' . urlencode($message))->withStatus(302);
    }
}
