<?php

use App\Domain\Alert;
use App\Repository\AlertRepository;
use PHPUnit\Framework\TestCase;

class AlertRepositoryTest extends TestCase
{
    private PDO $db;
    private AlertRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE triggers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                ticker VARCHAR(16),
                name VARCHAR(64),
                is_active INTEGER,
                created_at DATETIME
            )
        ');
        // Mirrors db/migrations/0011_alerts.php.
        $this->db->exec('
            CREATE TABLE alerts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                trigger_id INTEGER,
                fired_on DATE,
                created_at DATETIME
            )
        ');
        $this->db->exec('CREATE UNIQUE INDEX idx_alerts_trigger_fired_on ON alerts (trigger_id, fired_on)');

        $this->repository = new AlertRepository($this->db);

        $this->db->exec("INSERT INTO triggers (id, user_id, ticker, name, is_active, created_at) VALUES (1, 1, 'VOD', 'Mine', 1, '2026-01-01 00:00:00')");
        $this->db->exec("INSERT INTO triggers (id, user_id, ticker, name, is_active, created_at) VALUES (2, 2, 'BP', 'Someone else''s', 1, '2026-01-01 00:00:00')");
    }

    public function testExistsForIsFalseUntilCreated(): void
    {
        $this->assertFalse($this->repository->existsFor(1, '2026-02-01'));

        $this->repository->create(1, '2026-02-01');

        $this->assertTrue($this->repository->existsFor(1, '2026-02-01'));
    }

    public function testAllForUserOnlyReturnsAlertsForThatUsersTriggersNewestFirst(): void
    {
        $this->repository->create(1, '2026-02-01');
        $this->repository->create(1, '2026-02-05');
        $this->repository->create(2, '2026-02-03'); // belongs to user 2's trigger

        $alerts = $this->repository->allForUser(1);

        $this->assertCount(2, $alerts);
        $this->assertContainsOnlyInstancesOf(Alert::class, $alerts);
        $this->assertEquals('2026-02-05', $alerts[0]->getFiredOn());
        $this->assertEquals('2026-02-01', $alerts[1]->getFiredOn());
    }
}
