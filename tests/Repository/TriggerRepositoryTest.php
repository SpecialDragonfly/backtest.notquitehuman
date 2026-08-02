<?php

use App\Domain\Trigger;
use App\Repository\TriggerRepository;
use PHPUnit\Framework\TestCase;

class TriggerRepositoryTest extends TestCase
{
    private PDO $db;
    private TriggerRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Mirrors db/migrations/0010_triggers.php.
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
        $this->db->exec('
            CREATE TABLE trigger_conditions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                trigger_id INTEGER,
                line_a_id INTEGER,
                line_b_id INTEGER,
                operator VARCHAR(8),
                position INTEGER
            )
        ');

        $this->repository = new TriggerRepository($this->db);
    }

    public function testCreateInsertsTheTriggerAndItsSingleCondition(): void
    {
        $this->repository->create(1, 'VOD', 'My trigger', 10, 'ABOVE', 20);

        $trigger = $this->repository->find(1);

        $this->assertInstanceOf(Trigger::class, $trigger);
        $this->assertEquals('VOD', $trigger->getTicker());
        $this->assertEquals('My trigger', $trigger->getName());
        $this->assertTrue($trigger->isActive());
        $this->assertCount(1, $trigger->getConditions());
        $this->assertEquals(10, $trigger->getConditions()[0]->getLineAId());
        $this->assertEquals(20, $trigger->getConditions()[0]->getLineBId());
        $this->assertEquals('ABOVE', $trigger->getConditions()[0]->getOperator());
    }

    public function testAllForUserOnlyReturnsThatUsersTriggers(): void
    {
        $this->repository->create(1, 'VOD', 'Mine', 10, 'ABOVE', 20);
        $this->repository->create(2, 'BP', 'Someone else\'s', 10, 'ABOVE', 20);

        $triggers = $this->repository->allForUser(1);

        $this->assertCount(1, $triggers);
        $this->assertEquals('Mine', $triggers[0]->getName());
    }

    public function testAllActiveForTickerExcludesDisarmedTriggers(): void
    {
        $this->repository->create(1, 'VOD', 'Active', 10, 'ABOVE', 20);
        $this->repository->create(1, 'VOD', 'Disarmed', 10, 'ABOVE', 20);
        $this->repository->setActive(2, false);

        $triggers = $this->repository->allActiveForTicker('VOD');

        $this->assertCount(1, $triggers);
        $this->assertEquals('Active', $triggers[0]->getName());
    }

    public function testSetActiveToggles(): void
    {
        $this->repository->create(1, 'VOD', 'Mine', 10, 'ABOVE', 20);

        $this->repository->setActive(1, false);
        $this->assertFalse($this->repository->find(1)->isActive());

        $this->repository->setActive(1, true);
        $this->assertTrue($this->repository->find(1)->isActive());
    }

    public function testDeleteRemovesTheTrigger(): void
    {
        $this->repository->create(1, 'VOD', 'Mine', 10, 'ABOVE', 20);
        $this->repository->delete(1);

        $this->assertNull($this->repository->find(1));
    }
}
