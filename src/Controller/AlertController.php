<?php

namespace App\Controller;

use App\Domain\User;
use App\Repository\AlertRepository;
use App\Repository\TriggerRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Twig\Environment;

/**
 * Read-only list of live alerts — records created only by
 * App\Service\TriggerAlertService via the daily sync cron. Guest-blocked at
 * the route level, same reasoning as Lines/Triggers/Lab.
 */
class AlertController
{
    public function __construct(
        private Environment $twig,
        private AlertRepository $alertRepository,
        private TriggerRepository $triggerRepository,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);

        $triggersById = [];
        foreach ($this->triggerRepository->allForUser($user->getId()) as $trigger) {
            $triggersById[$trigger->getId()] = $trigger;
        }

        $rows = array_map(fn($alert) => [
            'alert' => $alert,
            'triggerName' => ($triggersById[$alert->getTriggerId()] ?? null)?->getName() ?? '(deleted trigger)',
            'ticker' => ($triggersById[$alert->getTriggerId()] ?? null)?->getTicker() ?? '?',
        ], $this->alertRepository->allForUser($user->getId()));

        $response->getBody()->write($this->twig->render('alerts/index.html.twig', [
            'isAdmin' => $user->isAdmin(),
            'rows' => $rows,
        ]));
        return $response;
    }

    private function requireUser(Request $request): User
    {
        $user = $request->getAttribute('user');
        if (!$user instanceof User) {
            throw new RuntimeException('AlertController reached without an authenticated user — check route middleware.');
        }
        return $user;
    }
}
