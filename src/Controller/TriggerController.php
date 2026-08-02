<?php

namespace App\Controller;

use App\Domain\Trigger;
use App\Domain\TriggerCondition;
use App\Domain\User;
use App\Line\TriggerEvaluationService;
use App\Repository\LineRepository;
use App\Repository\TickerRepository;
use App\Repository\TriggerRepository;
use App\Service\TickerBacktestService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Twig\Environment;

/**
 * CRUD for a logged-in user's own Triggers, plus the historical "would have
 * fired" view (computed on demand via TriggerEvaluationService::
 * findTransitionDates(), never persisted — only the live daily-sync cron,
 * added in a later phase, writes to the alerts table). Guest-blocked at the
 * route level, same reasoning as Lines/Lab.
 */
class TriggerController
{
    public function __construct(
        private Environment $twig,
        private TriggerRepository $triggerRepository,
        private LineRepository $lineRepository,
        private TickerRepository $tickerRepository,
        private TriggerEvaluationService $triggerEvaluationService,
        private TickerBacktestService $backtestService,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();

        $lines = $this->lineRepository->allForUser($user->getId());
        $linesById = [];
        foreach ($lines as $line) {
            $linesById[$line->getId()] = $line;
        }

        $rows = array_map(function (Trigger $trigger) use ($linesById) {
            $condition = $trigger->getConditions()[0] ?? null;
            $prices = $this->backtestService->loadPrices($trigger->getTicker());
            $firedDates = [];
            if ($condition !== null && count($prices) > 0) {
                $states = $this->triggerEvaluationService->evaluateStates($trigger, $prices);
                $firedDates = $this->triggerEvaluationService->findTransitionDates($states, $prices);
            }

            return [
                'trigger' => $trigger,
                'lineAName' => $condition !== null ? (($linesById[$condition->getLineAId()] ?? null)?->getName() ?? '(deleted line)') : '?',
                'lineBName' => $condition !== null ? (($linesById[$condition->getLineBId()] ?? null)?->getName() ?? '(deleted line)') : '?',
                'operator' => $condition?->getOperator(),
                'firedCount' => count($firedDates),
                'recentFiredDates' => array_slice(array_reverse($firedDates), 0, 5),
            ];
        }, $this->triggerRepository->allForUser($user->getId()));

        $response->getBody()->write($this->twig->render('triggers/index.html.twig', [
            'isAdmin' => $user->isAdmin(),
            'rows' => $rows,
            'lines' => $lines,
            'tickers' => $this->tickerRepository->all(),
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
        $get = fn(string $key) => is_array($body) && is_string($body[$key] ?? null) ? trim($body[$key]) : '';

        $name = $get('name');
        $ticker = $get('ticker');
        $operator = strtoupper($get('operator'));
        $lineAId = (int) $get('line_a_id');
        $lineBId = (int) $get('line_b_id');

        if ($name === '') {
            return $this->redirectWithError($response, 'Give the trigger a name.');
        }
        if (!in_array($ticker, $this->tickerRepository->all(), true)) {
            return $this->redirectWithError($response, 'Pick a valid ticker.');
        }
        if (!in_array($operator, [TriggerCondition::OPERATOR_ABOVE, TriggerCondition::OPERATOR_BELOW], true)) {
            return $this->redirectWithError($response, 'Pick a valid condition.');
        }
        if ($lineAId === $lineBId) {
            return $this->redirectWithError($response, 'Line A and Line B must be different.');
        }

        $lineA = $this->lineRepository->find($lineAId);
        $lineB = $this->lineRepository->find($lineBId);
        if ($lineA === null || $lineA->getUserId() !== $user->getId() || $lineB === null || $lineB->getUserId() !== $user->getId()) {
            return $this->redirectWithError($response, 'Pick two of your own lines.');
        }

        $this->triggerRepository->create($user->getId(), $ticker, $name, $lineAId, $operator, $lineBId);

        return $response->withHeader('Location', '/triggers?added=' . urlencode("Added \"{$name}\""))->withStatus(302);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $trigger = $this->findOwned($user, (int) ($args['id'] ?? 0));
        if ($trigger === null) {
            return $this->redirectWithError($response, 'Trigger not found.');
        }

        $this->triggerRepository->setActive($trigger->getId(), !$trigger->isActive());

        return $response->withHeader('Location', '/triggers')->withStatus(302);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $trigger = $this->findOwned($user, (int) ($args['id'] ?? 0));
        if ($trigger === null) {
            return $this->redirectWithError($response, 'Trigger not found.');
        }

        $this->triggerRepository->delete($trigger->getId());

        return $response->withHeader('Location', '/triggers?added=' . urlencode("Deleted \"{$trigger->getName()}\""))->withStatus(302);
    }

    private function findOwned(User $user, int $id): ?Trigger
    {
        $trigger = $this->triggerRepository->find($id);
        return ($trigger !== null && $trigger->getUserId() === $user->getId()) ? $trigger : null;
    }

    private function requireUser(Request $request): User
    {
        $user = $request->getAttribute('user');
        if (!$user instanceof User) {
            throw new RuntimeException('TriggerController reached without an authenticated user — check route middleware.');
        }
        return $user;
    }

    private function redirectWithError(Response $response, string $message): Response
    {
        return $response->withHeader('Location', '/triggers?error=' . urlencode($message))->withStatus(302);
    }
}
