<?php

use App\Domain\Line;
use App\Repository\LineRepository;
use PHPUnit\Framework\TestCase;

class LineRepositoryTest extends TestCase
{
    private PDO $db;
    private LineRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Mirrors db/migrations/0009_lines.php.
        $this->db->exec('
            CREATE TABLE lines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                name VARCHAR(64),
                formula TEXT,
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

        $this->repository = new LineRepository($this->db);
    }

    public function testCreateThenFindRoundTripsTheFormula(): void
    {
        $this->repository->create(1, 'SMA 50', ['fn' => 'SMA', 'period' => 50]);
        $line = $this->repository->find(1);

        $this->assertInstanceOf(Line::class, $line);
        $this->assertEquals(1, $line->getUserId());
        $this->assertEquals('SMA 50', $line->getName());
        $this->assertEquals(['fn' => 'SMA', 'period' => 50], $line->getFormula());
    }

    public function testAllForUserOnlyReturnsThatUsersLines(): void
    {
        $this->repository->create(1, 'Mine', ['fn' => 'PRICE']);
        $this->repository->create(2, 'Someone else\'s', ['fn' => 'PRICE']);

        $lines = $this->repository->allForUser(1);

        $this->assertCount(1, $lines);
        $this->assertEquals('Mine', $lines[0]->getName());
    }

    public function testFindReturnsNullForAMissingLine(): void
    {
        $this->assertNull($this->repository->find(999));
    }

    public function testDeleteRemovesTheLine(): void
    {
        $this->repository->create(1, 'Temp', ['fn' => 'PRICE']);
        $this->repository->delete(1);

        $this->assertNull($this->repository->find(1));
    }

    public function testIsReferencedByTriggerReflectsTriggerConditionRows(): void
    {
        $this->repository->create(1, 'A', ['fn' => 'PRICE']);
        $this->repository->create(1, 'B', ['fn' => 'CONSTANT', 'value' => 10]);

        $this->assertFalse($this->repository->isReferencedByTrigger(1));

        $this->db->exec('INSERT INTO trigger_conditions (trigger_id, line_a_id, line_b_id, operator, position) VALUES (1, 1, 2, "ABOVE", 0)');

        $this->assertTrue($this->repository->isReferencedByTrigger(1));
        $this->assertTrue($this->repository->isReferencedByTrigger(2));
        $this->assertFalse($this->repository->isReferencedByTrigger(3));
    }
}
