<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Integration;

use Cardyo\SpiralCursorPagination\Attribute\CursorPaginate;
use Cardyo\SpiralCursorPagination\Response\Connection;
use Cardyo\Tests\SpiralCursorPagination\Fixtures;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;

/**
 * Test cursor pagination without an incremental identifier (UUID primary key).
 */
class WithoutIncrementalIdentifierTest extends AbstractTestCase
{
    #[\Override]
    protected function defineSchema(): SchemaInterface
    {
        return new Schema([
            Fixtures\Entity\Customer::class => [
                SchemaInterface::ROLE => 'customer',
                SchemaInterface::DATABASE => 'default',
                SchemaInterface::TABLE => 'customers',
                SchemaInterface::PRIMARY_KEY => 'uuid',
                SchemaInterface::COLUMNS => [
                    'uuid' => 'uuid',
                    'name' => 'name',
                    'email' => 'email',
                    'createdAt' => 'created_at',
                    'updatedAt' => 'updated_at',
                    'lastActivityAt' => 'last_activity_at',
                    'loginCount' => 'login_count',
                ],
                SchemaInterface::TYPECAST => [
                    'uuid' => 'uuid',
                    'createdAt' => 'datetime',
                    'updatedAt' => 'datetime',
                    'lastActivityAt' => 'datetime',
                    'loginCount' => 'int',
                ],
                SchemaInterface::RELATIONS => [],
            ],
        ]);
    }

    #[\Override]
    protected function defineMigrations(DatabaseManager $dbal): void
    {
        $schema = $dbal->database()->table('customers')->getSchema();

        $schema->uuid('uuid');
        $schema->string('name');
        $schema->string('email');
        $schema->datetime('created_at');
        $schema->datetime('updated_at')->nullable();
        $schema->datetime('last_activity_at')->nullable();
        $schema->integer('login_count')->default(0);

        $schema->index(['uuid'])->unique();

        $schema->save();
    }

    #[Test]
    public function testDefaultPagination(): void
    {
        $this->seedCustomers($this->buildUuidCustomers());

        // Set up GridSchema with sorter and paginator
        $gridSchema = new \Spiral\DataGrid\GridSchema();
        $gridSchema->addSorter('uuid', new \Spiral\DataGrid\Specification\Sorter\Sorter('uuid'));
        $gridSchema->setPaginator($this->paginationHelper->createPaginator(defaultLimit: 5));
        $this->getContainer()->bindSingleton(TestUuidGridSchema::class, fn() => $gridSchema);

        $orm = $this->getContainer()->get(ORM::class);
        $controller = new class($orm) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestUuidGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select()->orderBy('uuid', 'ASC');
            }
        };

        $request = new ServerRequest('GET', '/customers');
        $connection = $this->executeController($controller, 'index', $request);

        // Should return 5 customers (pageSize limit)
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertCount(5, $connection->nodes);
        $this->assertTrue($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
    }

    /**
     * Creates predictable UUID customers so future test cases (incremental IDs, UUIDv7, etc.)
     * can reuse the same seeding logic with different factories.
     */
    private function buildUuidCustomers(int $count = 6): array
    {
        $customers = [];

        for ($index = 1; $index <= $count; ++$index) {
            $customers[] = new Fixtures\Entity\Customer(
                uuid: sprintf('00000000-0000-0000-0000-%012d', $index),
                name: sprintf('Customer %d', $index),
                email: sprintf('customer.%d@example.com', $index),
                createdAt: new \DateTimeImmutable(sprintf('2024-01-15T10:30:00Z +%d days', $index - 1)),
            );
        }

        return $customers;
    }

    private function seedCustomers(array $customers): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);

        foreach ($customers as $customer) {
            $em->persist($customer);
        }

        $em->run();
    }
}

// GridSchema for UUID pagination tests
class TestUuidGridSchema extends \Spiral\DataGrid\GridSchema {}
