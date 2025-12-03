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
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Filter\Gte;
use Spiral\DataGrid\Specification\Filter\Like;
use Spiral\DataGrid\Specification\Sorter\Sorter;

/**
 * Edge cases and production scenarios for cursor pagination using interceptor pattern.
 *
 * Tests cover:
 * - Empty result sets
 * - Single item results
 * - Exact page boundaries
 * - Complex filters with pagination
 * - Pagination stability
 * - Requesting more items than exist
 * - Page size of 1
 */
class CursorPaginationEdgeCasesTest extends AbstractTestCase
{
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
        $schema->datetime('created_at')->nullable();
        $schema->datetime('updated_at')->nullable();
        $schema->datetime('last_activity_at')->nullable();
        $schema->integer('login_count')->nullable();

        $schema->index(['uuid'])->unique();

        $schema->save();
    }

    /**
     * Test pagination with empty result set
     */
    #[Test]
    public function testPaginationWithEmptyResults(): void
    {
        // Don't seed any data

        // Set up GridSchema with paginator (no sorters - interceptor will add default PK sorter)
        $gridSchema = new GridSchema();
        $gridSchema->setPaginator($this->paginationHelper->createPaginator(defaultLimit: 10));
        $this->getContainer()->bindSingleton(TestBasicGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestBasicGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select();
            }
        };

        $request = new ServerRequest('GET', '/customers');
        $connection = $this->executeController($controller, 'index', $request);

        // Empty results
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertCount(0, $connection->nodes);
        $this->assertCount(0, $connection->edges);

        // No pagination
        $this->assertFalse($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
        $this->assertNull($connection->pageInfo->startCursor);
        $this->assertNull($connection->pageInfo->endCursor);
    }

    /**
     * Test pagination with single item
     */
    #[Test]
    public function testPaginationWithSingleItem(): void
    {
        $this->seedSingleCustomer();

        // Set up GridSchema with paginator (no sorters - interceptor will add default PK sorter)
        $gridSchema = new GridSchema();
        $gridSchema->setPaginator($this->paginationHelper->createPaginator(defaultLimit: 10));
        $this->getContainer()->bindSingleton(TestBasicGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestBasicGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select();
            }
        };

        $request = new ServerRequest('GET', '/customers?paginate[first]=10');
        $connection = $this->executeController($controller, 'index', $request);

        // Single item
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertCount(1, $connection->nodes);
        $this->assertEquals('Customer 1', $connection->nodes[0]->name);

        // No pagination needed
        $this->assertFalse($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);

        // Cursors should still be present
        $this->assertNotNull($connection->pageInfo->startCursor);
        $this->assertNotNull($connection->pageInfo->endCursor);
        $this->assertEquals($connection->pageInfo->startCursor, $connection->pageInfo->endCursor);
    }

    /**
     * Test exact page boundary (limit = 3, exactly 3 items)
     */
    #[Test]
    public function testExactPageBoundary(): void
    {
        $this->seedExactlyThreeCustomers();

        // Set up GridSchema with paginator (no sorters - interceptor will add default PK sorter)
        $gridSchema = new GridSchema();
        $gridSchema->setPaginator($this->paginationHelper->createPaginator());
        $this->getContainer()->bindSingleton(TestDefaultGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestDefaultGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select();
            }
        };

        $request = new ServerRequest('GET', '/customers?paginate[first]=3');
        $connection = $this->executeController($controller, 'index', $request);

        // Exactly 3 items
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertCount(3, $connection->nodes);

        // No more pages (we got exactly the page size, but +1 query didn't find more)
        $this->assertFalse($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
    }

    /**
     * Test complex filtering with pagination (e.g., search + date range + status filter)
     */
    #[Test]
    public function testComplexFilteringWithPagination(): void
    {
        $this->seedCustomersForFiltering();

        // Create GridSchema with multiple filters
        $gridSchema = new GridSchema();
        $gridSchema->addFilter('search', new Like('name', '%Alice%'));
        $gridSchema->addFilter('active', new Gte('login_count', 5));
        $gridSchema->addSorter('activity', new Sorter('last_activity_at'));
        $gridSchema->setPaginator($this->paginationHelper->createPaginator(2));

        $this->getContainer()->bindSingleton(TestComplexFilterGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestComplexFilterGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select();
            }
        };

        $request = new ServerRequest('GET', '/customers?filter[search]=1&filter[active]=1&sort[activity]=desc&paginate[first]=2');
        $connection = $this->executeController($controller, 'index', $request);

        // Should get filtered results
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertCount(2, $connection->nodes);

        // All results should match filters (name contains Alice, login_count >= 5)
        foreach ($connection->nodes as $node) {
            $this->assertStringContainsString('Alice', $node->name);
            $this->assertGreaterThanOrEqual(5, $node->loginCount);
        }

        // Cursors should work with filters
        $this->assertNotEmpty($connection->pageInfo->endCursor);
        $this->assertTrue($connection->pageInfo->hasNextPage);

        // Navigate to next page with same filters
        $request2 = new ServerRequest(
            'GET',
            '/customers?filter[search]=1&filter[active]=1&sort[activity]=desc&paginate[first]=2&paginate[after]=' . $connection->pageInfo->endCursor
        );
        $connection2 = $this->executeController($controller, 'index', $request2);

        // Second page should also match filters
        $this->assertInstanceOf(Connection::class, $connection2);
        foreach ($connection2->nodes as $node) {
            $this->assertStringContainsString('Alice', $node->name);
            $this->assertGreaterThanOrEqual(5, $node->loginCount);
        }
    }

    /**
     * Test pagination stability (same cursor returns same page)
     */
    #[Test]
    public function testPaginationStability(): void
    {
        $this->seedTestCustomers();

        // Set up GridSchema with paginator (no sorters - interceptor will add default PK sorter)
        $gridSchema = new GridSchema();
        $gridSchema->setPaginator($this->paginationHelper->createPaginator());
        $this->getContainer()->bindSingleton(TestDefaultGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestDefaultGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select();
            }
        };

        // First request
        $request1 = new ServerRequest('GET', '/customers?paginate[first]=3');
        $connection1 = $this->executeController($controller, 'index', $request1);

        $cursor = $connection1->pageInfo->endCursor;

        // Second request with same cursor (simulate browser back/forward)
        $request2 = new ServerRequest('GET', "/customers?paginate[first]=3&paginate[after]={$cursor}");
        $connection2 = $this->executeController($controller, 'index', $request2);

        // Third request with same cursor (should get identical results)
        $request3 = new ServerRequest('GET', "/customers?paginate[first]=3&paginate[after]={$cursor}");
        $connection3 = $this->executeController($controller, 'index', $request3);

        // Results should be identical
        $this->assertEquals(
            array_map(fn($n) => $n->name, $connection2->nodes),
            array_map(fn($n) => $n->name, $connection3->nodes)
        );
    }

    /**
     * Test requesting more items than exist
     */
    #[Test]
    public function testRequestingMoreItemsThanExist(): void
    {
        $this->seedExactlyThreeCustomers();

        // Set up GridSchema with large max limit (no sorters - interceptor will add default PK sorter)
        $gridSchema = new GridSchema();
        $gridSchema->setPaginator($this->paginationHelper->createPaginator(maxLimit: 100));
        $this->getContainer()->bindSingleton(TestLargePageGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestLargePageGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select();
            }
        };

        $request = new ServerRequest('GET', '/customers?paginate[first]=100');
        $connection = $this->executeController($controller, 'index', $request);

        // Only 3 items exist
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertCount(3, $connection->nodes);
        $this->assertFalse($connection->pageInfo->hasNextPage);
    }

    /**
     * Test page size of 1 (common for mobile apps)
     */
    #[Test]
    public function testPageSizeOfOne(): void
    {
        $this->seedTestCustomers();

        // Set up GridSchema with paginator (no sorters - interceptor will add default PK sorter)
        $gridSchema = new GridSchema();
        $gridSchema->setPaginator($this->paginationHelper->createPaginator());
        $this->getContainer()->bindSingleton(TestDefaultGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestDefaultGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select();
            }
        };

        $allItems = [];
        $cursor = null;

        // Paginate one item at a time
        for ($i = 0; $i < 10; $i++) {
            $url = '/customers?paginate[first]=1';
            if ($cursor) {
                $url .= "&paginate[after]={$cursor}";
            }

            $request = new ServerRequest('GET', $url);
            $connection = $this->executeController($controller, 'index', $request);

            if (count($connection->nodes) === 0) {
                break;
            }

            $this->assertCount(1, $connection->nodes);
            $allItems[] = $connection->nodes[0];
            $cursor = $connection->pageInfo->endCursor;
        }

        // Should have retrieved all 5 customers
        $this->assertCount(5, $allItems);
        $this->assertEquals('Customer 1', $allItems[0]->name);
        $this->assertEquals('Customer 5', $allItems[4]->name);
    }

    private function seedSingleCustomer(): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);

        $customer = new Fixtures\Entity\Customer(
            uuid: '00000000-0000-0000-0000-000000000001',
            name: 'Customer 1',
            email: 'customer1@example.com',
            createdAt: new \DateTimeImmutable('2024-01-01'),
            updatedAt: null,
            lastActivityAt: new \DateTimeImmutable('2024-01-10'),
            loginCount: 10,
        );

        $em->persist($customer);
        $em->run();
    }

    private function seedExactlyThreeCustomers(): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);

        for ($i = 1; $i <= 3; $i++) {
            $customer = new Fixtures\Entity\Customer(
                uuid: sprintf('00000000-0000-0000-0000-%012d', $i),
                name: "Customer $i",
                email: "customer$i@example.com",
                createdAt: new \DateTimeImmutable("2024-01-0$i"),
                lastActivityAt: new \DateTimeImmutable("2024-01-1$i"),
                loginCount: $i * 10,
            );
            $em->persist($customer);
        }

        $em->run();
    }

    private function seedTestCustomers(): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);

        for ($i = 1; $i <= 5; $i++) {
            $customer = new Fixtures\Entity\Customer(
                uuid: sprintf('00000000-0000-0000-0000-%012d', $i),
                name: "Customer $i",
                email: "customer$i@example.com",
                createdAt: new \DateTimeImmutable("2024-01-0$i"),
                lastActivityAt: new \DateTimeImmutable("2024-01-1$i"),
                loginCount: $i * 10,
            );
            $em->persist($customer);
        }

        $em->run();
    }

    private function seedCustomersForFiltering(): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);

        $customers = [
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000001',
                name: 'Alice Smith',
                email: 'alice.smith@example.com',
                createdAt: new \DateTimeImmutable('2024-01-01'),
                lastActivityAt: new \DateTimeImmutable('2024-01-15'),
                loginCount: 10,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000002',
                name: 'Alice Johnson',
                email: 'alice.johnson@example.com',
                createdAt: new \DateTimeImmutable('2024-01-02'),
                lastActivityAt: new \DateTimeImmutable('2024-01-14'),
                loginCount: 8,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000003',
                name: 'Alice Brown',
                email: 'alice.brown@example.com',
                createdAt: new \DateTimeImmutable('2024-01-03'),
                lastActivityAt: new \DateTimeImmutable('2024-01-13'),
                loginCount: 6,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000004',
                name: 'Bob Wilson',
                email: 'bob.wilson@example.com',
                createdAt: new \DateTimeImmutable('2024-01-04'),
                lastActivityAt: new \DateTimeImmutable('2024-01-12'),
                loginCount: 15,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000005',
                name: 'Alice Davis',
                email: 'alice.davis@example.com',
                createdAt: new \DateTimeImmutable('2024-01-05'),
                lastActivityAt: new \DateTimeImmutable('2024-01-11'),
                loginCount: 2, // Below threshold
            ),
        ];

        foreach ($customers as $customer) {
            $em->persist($customer);
        }

        $em->run();
    }
}

// Dummy class for testing complex filter GridSchema
class TestComplexFilterGridSchema extends GridSchema {}

// GridSchema for basic tests with default limit of 10
class TestBasicGridSchema extends GridSchema {}

// GridSchema for tests without specific requirements (will use default from request)
class TestDefaultGridSchema extends GridSchema {}

// GridSchema with high max limit for large requests
class TestLargePageGridSchema extends GridSchema {}
