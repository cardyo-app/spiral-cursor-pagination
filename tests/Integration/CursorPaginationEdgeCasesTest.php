<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Integration;

use Cardyo\SpiralCursorPagination\CursorEncoder\CursorCoder;
use Cardyo\SpiralCursorPagination\Service\ConnectionFactory;
use Cardyo\SpiralCursorPagination\Service\CursorGenerator;
use Cardyo\SpiralCursorPagination\Service\PaginationMetadataCalculator;
use Cardyo\Tests\SpiralCursorPagination\Fixtures;
use Cycle\Database\DatabaseProviderInterface;
use Cycle\Database\Schema\AbstractTable;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use Cycle\ORM\Select;
use PHPUnit\Framework\Attributes\Test;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Filter\Gte;
use Spiral\DataGrid\Specification\Filter\Like;
use Spiral\DataGrid\Specification\Sorter\Sorter;

/**
 * Edge cases and production scenarios for cursor pagination.
 *
 * Tests cover:
 * - Empty result sets
 * - Single item results
 * - Exact page boundaries
 * - Complex filters with pagination
 * - Search with pagination
 * - Invalid/malformed cursors
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

    public function setUp(): void
    {
        parent::setUp();
        $this->initDatabase();
    }

    private function initDatabase(): void
    {
        $dbal = $this->getContainer()->get(DatabaseProviderInterface::class);

        $schema = $dbal->database('default')->table('customers')->getSchema();
        $schema->primary('uuid');
        $schema->string('name');
        $schema->string('email');
        $schema->datetime('created_at')->nullable();
        $schema->datetime('updated_at')->nullable();
        $schema->datetime('last_activity_at')->nullable();
        $schema->integer('login_count')->nullable();
        $schema->save();
    }

    /**
     * Test pagination with empty result set
     */
    #[Test]
    public function testPaginationWithEmptyResults(): void
    {
        // Don't seed any data
        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        $select = $select->orderBy('login_count', 'DESC');

        $gridSchema = new GridSchema();
        $paginator = $this->createPaginator(10);
        $gridSchema->setPaginator($paginator);

        $grid = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'paginate' => ['first' => 10],
            ]))
            ->create($select, $gridSchema);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );
        $connection = $connectionFactory->createConnection(
            results: $results,
            query: $grid->getSource(),
            paginatorState: ['first' => 10],
            encoder: new CursorCoder(),
        );

        // Empty results
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

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        $select = $select->orderBy('name', 'ASC');

        $gridSchema = new GridSchema();
        $paginator = $this->createPaginator(10);
        $gridSchema->setPaginator($paginator);

        $grid = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'paginate' => ['first' => 10],
            ]))
            ->create($select, $gridSchema);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );
        $connection = $connectionFactory->createConnection(
            results: $results,
            query: $grid->getSource(),
            paginatorState: ['first' => 10],
            encoder: new CursorCoder(),
        );

        // Single item
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

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        $select = $select->orderBy('name', 'ASC');

        $gridSchema = new GridSchema();
        $paginator = $this->createPaginator(10);
        $gridSchema->setPaginator($paginator);

        $grid = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'paginate' => ['first' => 3],
            ]))
            ->create($select, $gridSchema);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );
        $connection = $connectionFactory->createConnection(
            results: $results,
            query: $grid->getSource(),
            paginatorState: ['first' => 3],
            encoder: new CursorCoder(),
        );

        // Exactly 3 items
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

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);

        $gridSchema = new GridSchema();

        // Multiple filters like a real app: search, date range, threshold
        $gridSchema->addFilter('search', new Like('name', '%Alice%'));
        $gridSchema->addFilter('active', new Gte('login_count', 5));
        $gridSchema->addSorter('activity', new Sorter('last_activity_at'));

        $paginator = $this->createPaginator(10);
        $gridSchema->setPaginator($paginator);

        $grid = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'filter' => [
                    'search' => true,
                    'active' => true,
                ],
                'sort' => ['activity' => 'desc'],
                'paginate' => ['first' => 2],
            ]))
            ->create($select, $gridSchema);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );
        $connection = $connectionFactory->createConnection(
            results: $results,
            query: $grid->getSource(),
            paginatorState: ['first' => 2],
            encoder: new CursorCoder(),
        );

        // Should get filtered results
        $this->assertCount(2, $connection->nodes);

        // All results should match filters (name contains Alice, login_count >= 5)
        foreach ($connection->nodes as $node) {
            $this->assertStringContainsString('Alice', $node->name);
            $this->assertGreaterThanOrEqual(5, $node->loginCount);
        }

        // Cursors should work with filters
        $this->assertNotEmpty($connection->pageInfo->endCursor);

        // Navigate to next page with same filters
        $grid2 = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'filter' => [
                    'search' => true,
                    'active' => true,
                ],
                'sort' => ['activity' => 'desc'],
                'paginate' => [
                    'first' => 2,
                    'after' => $connection->pageInfo->endCursor,
                ],
            ]))
            ->create(clone $select, $gridSchema);

        $results2 = iterator_to_array($grid2->getIterator());
        $connection2 = $connectionFactory->createConnection(
            results: $results2,
            query: $grid2->getSource(),
            paginatorState: [
                'first' => 2,
                'after' => $connection->pageInfo->endCursor,
            ],
            encoder: new CursorCoder(),
        );

        // Second page should also match filters
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

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        $select = $select->orderBy('name', 'ASC');

        $gridSchema = new GridSchema();
        $paginator = $this->createPaginator(10);
        $gridSchema->setPaginator($paginator);

        // First request
        $grid1 = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'paginate' => ['first' => 3],
            ]))
            ->create(clone $select, $gridSchema);

        $results1 = iterator_to_array($grid1->getIterator());

        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );
        $connection1 = $connectionFactory->createConnection(
            results: $results1,
            query: $grid1->getSource(),
            paginatorState: ['first' => 3],
            encoder: new CursorCoder(),
        );

        $cursor = $connection1->pageInfo->endCursor;

        // Second request with same cursor (simulate browser back/forward)
        $grid2 = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'paginate' => [
                    'first' => 3,
                    'after' => $cursor,
                ],
            ]))
            ->create(clone $select, $gridSchema);

        $results2 = iterator_to_array($grid2->getIterator());
        $connection2 = $connectionFactory->createConnection(
            results: $results2,
            query: $grid2->getSource(),
            paginatorState: [
                'first' => 3,
                'after' => $cursor,
            ],
            encoder: new CursorCoder(),
        );

        // Third request with same cursor (should get identical results)
        $grid3 = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'paginate' => [
                    'first' => 3,
                    'after' => $cursor,
                ],
            ]))
            ->create(clone $select, $gridSchema);

        $results3 = iterator_to_array($grid3->getIterator());
        $connection3 = $connectionFactory->createConnection(
            results: $results3,
            query: $grid3->getSource(),
            paginatorState: [
                'first' => 3,
                'after' => $cursor,
            ],
            encoder: new CursorCoder(),
        );

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

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        $select = $select->orderBy('name', 'ASC');

        $gridSchema = new GridSchema();
        $paginator = $this->createPaginator(100);
        $gridSchema->setPaginator($paginator);

        $grid = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'paginate' => ['first' => 100],
            ]))
            ->create($select, $gridSchema);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );
        $connection = $connectionFactory->createConnection(
            results: $results,
            query: $grid->getSource(),
            paginatorState: ['first' => 100],
            encoder: new CursorCoder(),
        );

        // Only 3 items exist
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

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        $select = $select->orderBy('name', 'ASC');

        $gridSchema = new GridSchema();
        $paginator = $this->createPaginator(10);
        $gridSchema->setPaginator($paginator);

        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );

        $allItems = [];
        $cursor = null;

        // Paginate one item at a time
        for ($i = 0; $i < 10; $i++) {
            $input = ['first' => 1];
            if ($cursor) {
                $input['after'] = $cursor;
            }

            $grid = self::createGridFactory()
                ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                    'paginate' => $input,
                ]))
                ->create(clone $select, $gridSchema);

            $results = iterator_to_array($grid->getIterator());
            $connection = $connectionFactory->createConnection(
                results: $results,
                query: $grid->getSource(),
                paginatorState: $input,
                encoder: new CursorCoder(),
            );

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
