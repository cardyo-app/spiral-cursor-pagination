<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Integration;

use Cardyo\SpiralCursorPagination\Attribute\CursorPaginate;
use Cardyo\SpiralCursorPagination\Response\Connection;
use Cardyo\Tests\SpiralCursorPagination\Fixtures;
use Cardyo\Tests\SpiralCursorPagination\Integration\GridSchemas\CustomerGridSchema;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;

/**
 * Test cursor pagination with dynamic sorting using the interceptor pattern.
 *
 * This demonstrates the production pattern where:
 * 1. GridSchema is constructed once (like in a service/grid class)
 * 2. Sort parameters come from user input at runtime
 * 3. Cursor pagination automatically detects sort fields from the compiled query
 */
class CursorPaginationWithDynamicSortingTest extends AbstractTestCase
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
        $schema->index(['last_activity_at']);
        $schema->index(['login_count']);

        $schema->save();
    }

    /**
     * Test that cursor pagination works WITHOUT manually specifying sort fields.
     *
     * This simulates the production pattern where the GridSchema is constructed
     * once (e.g., in CustomerGrid constructor) without knowing what sort parameters
     * the user will request.
     */
    #[Test]
    public function testDynamicSortFieldDetection(): void
    {
        $this->seedTestCustomers();

        // Register GridSchema - notice sort params aren't known yet
        $this->getContainer()->bindSingleton(CustomerGridSchema::class, function() {
            $schema = new CustomerGridSchema();
            $schema->setPaginator($this->paginationHelper->createPaginator(3));
            return $schema;
        });

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: CustomerGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select();
            }
        };

        // User requests with specific sort direction at runtime
        $request = new ServerRequest('GET', '/customers?sort[activity]=desc&sort[uuid]=asc&paginate[first]=3');
        $connection = $this->executeController($controller, 'index', $request);

        // Test the actual Connection response
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertCount(3, $connection->nodes);
        $this->assertCount(3, $connection->edges);

        // Verify order
        $this->assertEquals('Customer 5', $connection->nodes[0]->name);
        $this->assertEquals('Customer 4', $connection->nodes[1]->name);
        $this->assertEquals('Customer 3', $connection->nodes[2]->name);

        // Test PageInfo
        $this->assertTrue($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
        $this->assertNotNull($connection->pageInfo->startCursor);
        $this->assertNotNull($connection->pageInfo->endCursor);

        // Test edges have cursors
        foreach ($connection->edges as $edge) {
            $this->assertNotEmpty($edge->cursor);
            $this->assertNotNull($edge->node);
        }
    }

    /**
     * Test that keyset filtering works with dynamically detected sort fields
     */
    #[Test]
    public function testKeysetFilteringWithDynamicSorting(): void
    {
        $this->seedTestCustomers();

        $this->getContainer()->bindSingleton(CustomerGridSchema::class, function() {
            $schema = new CustomerGridSchema();
            $schema->setPaginator($this->paginationHelper->createPaginator(2));
            return $schema;
        });

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: CustomerGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select();
            }
        };

        // First page
        $request = new ServerRequest('GET', '/customers?sort[logins]=desc&sort[uuid]=asc&paginate[first]=2');
        $connection = $this->executeController($controller, 'index', $request);

        // Test Connection has correct data
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertCount(2, $connection->nodes);
        $this->assertEquals(50, $connection->nodes[0]->loginCount);
        $this->assertEquals(40, $connection->nodes[1]->loginCount);

        // Test PageInfo indicates more pages
        $this->assertTrue($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
    }

    /**
     * Test with multiple sorters - user chooses which one at runtime
     */
    #[Test]
    public function testMultipleSorterOptions(): void
    {
        $this->seedTestCustomers();

        // Grid schema offers multiple sort options
        $this->getContainer()->bindSingleton(CustomerGridSchema::class, function() {
            $schema = new CustomerGridSchema();
            $schema->setPaginator($this->paginationHelper->createPaginator(3));
            return $schema;
        });

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: CustomerGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select();
            }
        };

        // User chooses to sort by activity
        $request1 = new ServerRequest('GET', '/customers?sort[activity]=desc&sort[uuid]=asc&paginate[first]=3');
        $connection1 = $this->executeController($controller, 'index', $request1);

        $this->assertInstanceOf(Connection::class, $connection1);
        $this->assertEquals('Customer 5', $connection1->nodes[0]->name); // Latest activity
        $this->assertCount(3, $connection1->nodes);

        // User chooses to sort by logins
        $request2 = new ServerRequest('GET', '/customers?sort[logins]=desc&sort[uuid]=asc&paginate[first]=3');
        $connection2 = $this->executeController($controller, 'index', $request2);

        $this->assertInstanceOf(Connection::class, $connection2);
        $this->assertEquals(50, $connection2->nodes[0]->loginCount); // Most logins
        $this->assertCount(3, $connection2->nodes);
    }

    private function seedTestCustomers(): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);

        $customers = [
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000001',
                name: 'Customer 1',
                email: 'customer1@example.com',
                createdAt: new DateTimeImmutable('2024-01-01T10:00:00Z'),
                lastActivityAt: new DateTimeImmutable('2024-01-10T10:00:00Z'),
                loginCount: 10,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000002',
                name: 'Customer 2',
                email: 'customer2@example.com',
                createdAt: new DateTimeImmutable('2024-01-02T10:00:00Z'),
                lastActivityAt: new DateTimeImmutable('2024-01-15T10:00:00Z'),
                loginCount: 20,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000003',
                name: 'Customer 3',
                email: 'customer3@example.com',
                createdAt: new DateTimeImmutable('2024-01-03T10:00:00Z'),
                lastActivityAt: new DateTimeImmutable('2024-01-20T10:00:00Z'),
                loginCount: 30,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000004',
                name: 'Customer 4',
                email: 'customer4@example.com',
                createdAt: new DateTimeImmutable('2024-01-04T10:00:00Z'),
                lastActivityAt: new DateTimeImmutable('2024-01-25T10:00:00Z'),
                loginCount: 40,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000005',
                name: 'Customer 5',
                email: 'customer5@example.com',
                createdAt: new DateTimeImmutable('2024-01-05T10:00:00Z'),
                lastActivityAt: new DateTimeImmutable('2024-01-30T10:00:00Z'),
                loginCount: 50,
            ),
        ];

        foreach ($customers as $customer) {
            $em->persist($customer);
        }

        $em->run();
    }
}
