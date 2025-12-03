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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Filter\Gte;
use Spiral\DataGrid\Specification\Sorter\Sorter;

/**
 * Test cursor pagination integration with DataGrid's built-in sorters using the interceptor pattern.
 *
 * This validates that cursor pagination works alongside DataGrid's Sorter specification,
 * allowing users to control sorting direction via input parameters while still
 * getting cursor-based pagination.
 */
class CursorPaginationWithDataGridSortersTest extends AbstractTestCase
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
     * Test that cursor pagination works with DataGrid Sorter
     */
    #[Test]
    #[DataProvider('sorterDirectionProvider')]
    public function testCursorPaginationWithDataGridSorter(
        string $sortField,
        string $sortDirection,
        array $expectedOrder,
    ): void {
        $this->seedTestCustomers();

        // Register GridSchema with the specific sorter mapping
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

        $request = new ServerRequest(
            'GET',
            "/customers?sort[activity]={$sortDirection}&paginate[first]=3"
        );

        $connection = $this->executeController($controller, 'index', $request);

        // Test Connection response
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertCount(3, $connection->nodes);

        // Verify order
        $actualNames = array_map(fn($c) => $c->name, $connection->nodes);
        $this->assertEquals(
            array_slice($expectedOrder, 0, 3),
            $actualNames,
            "Results should be ordered by {$sortField} {$sortDirection}"
        );

        // Test PageInfo
        $this->assertTrue($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);

        // Test edges have cursors
        $this->assertCount(3, $connection->edges);
        foreach ($connection->edges as $edge) {
            $this->assertNotEmpty($edge->cursor);
        }
    }

    public static function sorterDirectionProvider(): iterable
    {
        yield 'last_activity_at DESC' => [
            'sortField' => 'last_activity_at',
            'sortDirection' => 'DESC',
            'expectedOrder' => ['Customer 5', 'Customer 4', 'Customer 3', 'Customer 2', 'Customer 1'],
        ];

        yield 'last_activity_at ASC' => [
            'sortField' => 'last_activity_at',
            'sortDirection' => 'ASC',
            'expectedOrder' => ['Customer 1', 'Customer 2', 'Customer 3', 'Customer 4', 'Customer 5'],
        ];

        yield 'login_count DESC' => [
            'sortField' => 'login_count',
            'sortDirection' => 'DESC',
            'expectedOrder' => ['Customer 5', 'Customer 4', 'Customer 3', 'Customer 2', 'Customer 1'],
        ];

        yield 'login_count ASC' => [
            'sortField' => 'login_count',
            'sortDirection' => 'ASC',
            'expectedOrder' => ['Customer 1', 'Customer 2', 'Customer 3', 'Customer 4', 'Customer 5'],
        ];
    }

    /**
     * Test cursor-based navigation with DataGrid sorter
     */
    #[Test]
    public function testCursorNavigationWithDataGridSorter(): void
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
        $request = new ServerRequest('GET', '/customers?sort[logins]=desc&paginate[first]=2');
        $connection = $this->executeController($controller, 'index', $request);

        // Test Connection response
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertCount(2, $connection->nodes);
        $this->assertEquals('Customer 5', $connection->nodes[0]->name);
        $this->assertEquals('Customer 4', $connection->nodes[1]->name);
        $this->assertEquals(50, $connection->nodes[0]->loginCount);
        $this->assertEquals(40, $connection->nodes[1]->loginCount);

        // Test PageInfo
        $this->assertTrue($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
    }

    /**
     * Test with filters and sorters together
     */
    #[Test]
    public function testCursorPaginationWithFiltersAndSorters(): void
    {
        $this->seedTestCustomers();

        // Create custom GridSchema with filter
        $gridSchema = new GridSchema();
        $gridSchema->addSorter('activity', new Sorter('last_activity_at'));
        $gridSchema->addFilter('minLogins', new Gte('login_count', 20));
        $gridSchema->setPaginator($this->paginationHelper->createPaginator(10));

        $this->getContainer()->bindSingleton(TestCustomerGridSchemaWithFilter::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestCustomerGridSchemaWithFilter::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select();
            }
        };

        $request = new ServerRequest('GET', '/customers?sort[activity]=desc&filter[minLogins]=1&paginate[first]=10');
        $connection = $this->executeController($controller, 'index', $request);

        // Should get 4 results (customers with login_count >= 20)
        // Customer 5 (50), Customer 4 (40), Customer 3 (30), Customer 2 (20)
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertCount(4, $connection->nodes);
        $this->assertEquals('Customer 5', $connection->nodes[0]->name);
        $this->assertEquals('Customer 4', $connection->nodes[1]->name);
        $this->assertEquals('Customer 3', $connection->nodes[2]->name);
        $this->assertEquals('Customer 2', $connection->nodes[3]->name);

        // Test PageInfo - no more pages since we got all filtered results
        $this->assertFalse($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);

        // Test edges have cursors
        $this->assertCount(4, $connection->edges);
        foreach ($connection->edges as $edge) {
            $this->assertNotEmpty($edge->cursor);
            $this->assertNotNull($edge->node);
        }
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

// Dummy class for testing GridSchema with filters
class TestCustomerGridSchemaWithFilter extends GridSchema {}
