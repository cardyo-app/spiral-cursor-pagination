<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Integration;

use Cardyo\SpiralCursorPagination\CursorEncoder\CursorCoder;
use Cardyo\SpiralCursorPagination\Service\ConnectionFactory;
use Cardyo\SpiralCursorPagination\Service\CursorGenerator;
use Cardyo\SpiralCursorPagination\Service\PaginationMetadataCalculator;
use Cardyo\SpiralCursorPagination\Specification\Pagination\CursorPaginator;
use Cardyo\Tests\SpiralCursorPagination\Fixtures;
use Cycle\Database\DatabaseProviderInterface;
use Cycle\Database\Schema\AbstractTable;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use Cycle\ORM\Select;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Value\IntValue;
use Spiral\DataGrid\Specification\Value\RangeValue;
use Spiral\DataGrid\Specification\Value\RangeValue\Boundary;

/**
 * Test cursor pagination with manually configured sorting.
 *
 * This demonstrates the recommended usage pattern: apply sorting to the Select query
 * directly, then configure the paginator's sort fields to match.
 */
class CursorPaginationWithManualSortingTest extends AbstractTestCase
{
    #[\Override]
    public function setUp(): void
    {
        parent::setUp();
        $this->defineSchema();
        $this->setUpDatabase();
    }

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

    private function setUpDatabase(): void
    {
        $schema = $this->getContainer()
            ->get(DatabaseProviderInterface::class)
            ->database()
            ->table('customers')
            ->getSchema();

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
     * Test basic forward pagination with different sort fields and directions
     */
    #[Test]
    #[DataProvider('sortFieldProvider')]
    public function testForwardPaginationWithDifferentSorts(
        string $sortField,
        string $sortDirection,
        array $expectedOrder,
    ): void {
        $this->seedTestCustomers();

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        $select = $select->orderBy($sortField, $sortDirection);

        $gridSchema = new GridSchema();
        $paginator = $this->createPaginator(3);
        $gridSchema->setPaginator($paginator);

        $grid = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'paginate' => ['first' => 3],
            ]))
            ->create($select, $gridSchema);

        $results = iterator_to_array($grid->getIterator());

        // Create Connection response
        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );
        $connection = $connectionFactory->createConnection(
            results: $results,
            query: $grid->getSource(),
            paginatorState: $gridSchema->getPaginator()->getValue(),
            encoder: new CursorCoder(),
        );

        // Test Connection response
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

        // Test edges
        $this->assertCount(3, $connection->edges);
        foreach ($connection->edges as $edge) {
            $this->assertNotEmpty($edge->cursor);
            $this->assertNotNull($edge->node);
        }
    }

    public static function sortFieldProvider(): iterable
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
     * Test cursor-based keyset filtering works correctly
     */
    #[Test]
    public function testCursorKeysetFiltering(): void
    {
        $this->seedTestCustomers();

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        $select = $select->orderBy('login_count', 'DESC');

        $gridSchema = new GridSchema();
        $paginator = $this->createPaginator(3);
        $gridSchema->setPaginator($paginator);

        // First page
        $grid = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'paginate' => ['first' => 3],
            ]))
            ->create($select, $gridSchema);

        $results = iterator_to_array($grid->getIterator());

        // Create Connection response
        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );
        $connection = $connectionFactory->createConnection(
            results: $results,
            query: $grid->getSource(),
            paginatorState: $gridSchema->getPaginator()->getValue(),
            encoder: new CursorCoder(),
        );

        // Test Connection response
        $this->assertCount(3, $connection->nodes);
        $this->assertEquals(50, $connection->nodes[0]->loginCount);
        $this->assertEquals(40, $connection->nodes[1]->loginCount);
        $this->assertEquals(30, $connection->nodes[2]->loginCount);

        // Test PageInfo - should have more pages (Customer 2 and 1 remain)
        $this->assertTrue($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);

        // Test edges
        $this->assertCount(3, $connection->edges);
        foreach ($connection->edges as $edge) {
            $this->assertNotEmpty($edge->cursor);
            $this->assertNotNull($edge->node);
        }
    }

    /**
     * Test composite sorting (multiple fields)
     */
    #[Test]
    public function testCompositeFieldSorting(): void
    {
        // Create customers with same login_count but different last_activity_at
        $customers = [
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000001',
                name: 'Customer A',
                email: 'a@example.com',
                createdAt: new DateTimeImmutable('2024-01-01T10:00:00Z'),
                lastActivityAt: new DateTimeImmutable('2024-01-10T10:00:00Z'),
                loginCount: 10,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000002',
                name: 'Customer B',
                email: 'b@example.com',
                createdAt: new DateTimeImmutable('2024-01-02T10:00:00Z'),
                lastActivityAt: new DateTimeImmutable('2024-01-15T10:00:00Z'),
                loginCount: 10,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000003',
                name: 'Customer C',
                email: 'c@example.com',
                createdAt: new DateTimeImmutable('2024-01-03T10:00:00Z'),
                lastActivityAt: new DateTimeImmutable('2024-01-05T10:00:00Z'),
                loginCount: 10,
            ),
        ];

        foreach ($customers as $customer) {
            $this->persist($customer);
        }
        $this->flush();

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        $select = $select->orderBy('login_count', 'DESC')->orderBy('last_activity_at', 'DESC');

        $gridSchema = new GridSchema();
        $paginator = $this->createPaginator(2);
        $gridSchema->setPaginator($paginator);

        $grid = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'paginate' => ['first' => 2],
            ]))
            ->create($select, $gridSchema);

        $results = iterator_to_array($grid->getIterator());

        // Create Connection response
        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );
        $connection = $connectionFactory->createConnection(
            results: $results,
            query: $grid->getSource(),
            paginatorState: $gridSchema->getPaginator()->getValue(),
            encoder: new CursorCoder(),
        );

        // Test Connection response
        $this->assertCount(2, $connection->nodes);

        // All have same login_count, so sorted by last_activity_at DESC
        $this->assertEquals('Customer B', $connection->nodes[0]->name); // Jan 15
        $this->assertEquals('Customer A', $connection->nodes[1]->name); // Jan 10

        // Test PageInfo - should have more pages (Customer C remains)
        $this->assertTrue($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);

        // Test edges
        $this->assertCount(2, $connection->edges);
        foreach ($connection->edges as $edge) {
            $this->assertNotEmpty($edge->cursor);
            $this->assertNotNull($edge->node);
        }
    }

    /**
     * Test that cursors from one page can be used to navigate to the next page
     */
    #[Test]
    public function testCursorNavigationForward(): void
    {
        $this->seedTestCustomers();

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        $select = $select->orderBy('login_count', 'DESC');

        $gridSchema = new GridSchema();

        // Configure paginator
        $paginator = $this->createPaginator(10);
        $gridSchema->setPaginator($paginator);

        // Get first page (3 items)
        $firstPageInput = ['first' => 3];
        $grid = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'paginate' => $firstPageInput,
            ]))
            ->create(clone $select, $gridSchema);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );

        $firstPage = $connectionFactory->createConnection(
            results: $results,
            query: $grid->getSource(),
            paginatorState: $firstPageInput,
            encoder: new CursorCoder(),
        );

        // Verify first page
        $this->assertCount(3, $firstPage->nodes);
        $this->assertEquals('Customer 5', $firstPage->nodes[0]->name); // login_count: 50
        $this->assertEquals('Customer 4', $firstPage->nodes[1]->name); // login_count: 40
        $this->assertEquals('Customer 3', $firstPage->nodes[2]->name); // login_count: 30
        $this->assertTrue($firstPage->pageInfo->hasNextPage);
        $this->assertNotEmpty($firstPage->pageInfo->endCursor);

        // Use endCursor to get next page
        $afterCursor = $firstPage->pageInfo->endCursor;

        $secondPageInput = [
            'first' => 3,
            'after' => $afterCursor,
        ];
        $grid2 = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'paginate' => $secondPageInput,
            ]))
            ->create(clone $select, $gridSchema);

        $results2 = iterator_to_array($grid2->getIterator());

        $secondPage = $connectionFactory->createConnection(
            results: $results2,
            query: $grid2->getSource(),
            paginatorState: $secondPageInput,
            encoder: new CursorCoder(),
        );

        // Verify second page contains next items
        $this->assertCount(2, $secondPage->nodes);
        $this->assertEquals('Customer 2', $secondPage->nodes[0]->name); // login_count: 20
        $this->assertEquals('Customer 1', $secondPage->nodes[1]->name); // login_count: 10
        $this->assertFalse($secondPage->pageInfo->hasNextPage);
        $this->assertTrue($secondPage->pageInfo->hasPreviousPage);
    }

    /**
     * Test that cursors can be used to navigate backward
     */
    #[Test]
    public function testCursorNavigationBackward(): void
    {
        $this->seedTestCustomers();

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        $select = $select->orderBy('login_count', 'DESC');

        $gridSchema = new GridSchema();

        // Configure paginator
        $paginator = $this->createPaginator(10);
        $gridSchema->setPaginator($paginator);

        // Get a page from the middle using 'after' (skip first 2 items)
        $allPageInput = ['first' => 5];
        $grid = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'paginate' => $allPageInput,
            ]))
            ->create(clone $select, $gridSchema);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );
        $allPage = $connectionFactory->createConnection(
            results: $results,
            query: $grid->getSource(),
            paginatorState: $allPageInput,
            encoder: new CursorCoder(),
        );

        // Get cursor for Customer 3 (middle item)
        $middleCursor = $allPage->edges[2]->cursor; // Customer 3

        // Navigate backward from middle cursor
        $backwardPageInput = [
            'last' => 2,
            'before' => $middleCursor,
        ];

        // Create a new schema for backward pagination to avoid state pollution
        $backwardSchema = new GridSchema();
        $backwardPaginator = $this->createPaginator(10);
        $backwardSchema->setPaginator($backwardPaginator);

        $grid2 = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'paginate' => $backwardPageInput,
            ]))
            ->create(clone $select, $backwardSchema);

        $results2 = iterator_to_array($grid2->getIterator());

        $backwardPage = $connectionFactory->createConnection(
            results: $results2,
            query: $grid2->getSource(),
            paginatorState: $backwardPageInput,
            encoder: new CursorCoder(),
        );

        // Should get Customer 5 and Customer 4 (the 2 items before Customer 3)
        $this->assertCount(2, $backwardPage->nodes);
        $this->assertEquals('Customer 5', $backwardPage->nodes[0]->name); // login_count: 50
        $this->assertEquals('Customer 4', $backwardPage->nodes[1]->name); // login_count: 40
        $this->assertFalse($backwardPage->pageInfo->hasPreviousPage);
        $this->assertTrue($backwardPage->pageInfo->hasNextPage);
    }

    /**
     * Test cursor navigation across multiple pages
     */
    #[Test]
    public function testMultiPageCursorNavigation(): void
    {
        $this->seedTestCustomers();

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        $select = $select->orderBy('login_count', 'DESC');

        $gridSchema = new GridSchema();

        // Configure paginator with small page size
        $paginator = $this->createPaginator(10);
        $gridSchema->setPaginator($paginator);

        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );

        $allCustomers = [];
        $cursor = null;

        // Navigate through all pages collecting all customers
        do {
            $pageInput = ['first' => 2];
            if ($cursor !== null) {
                $pageInput['after'] = $cursor;
            }

            $grid = self::createGridFactory()
                ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                    'paginate' => $pageInput,
                ]))
                ->create(clone $select, $gridSchema);

            $results = iterator_to_array($grid->getIterator());

            $page = $connectionFactory->createConnection(
                results: $results,
                query: $grid->getSource(),
                paginatorState: $pageInput,
                encoder: new CursorCoder(),
            );

            array_push($allCustomers, ...$page->nodes);
            $cursor = $page->pageInfo->hasNextPage ? $page->pageInfo->endCursor : null;
        } while ($cursor !== null);

        // Should have collected all 5 customers
        $this->assertCount(5, $allCustomers);
        $this->assertEquals('Customer 5', $allCustomers[0]->name);
        $this->assertEquals('Customer 4', $allCustomers[1]->name);
        $this->assertEquals('Customer 3', $allCustomers[2]->name);
        $this->assertEquals('Customer 2', $allCustomers[3]->name);
        $this->assertEquals('Customer 1', $allCustomers[4]->name);
    }

    private function seedTestCustomers(): array
    {
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
            $this->persist($customer);
        }

        $this->flush();

        return $customers;
    }

    private function createPaginator(int $limit): CursorPaginator
    {
        return new CursorPaginator(
            defaultLimit: $limit,
            limitValue: new RangeValue(
                new IntValue(),
                Boundary::including(1),
                Boundary::including(100),
            ),
            cursorCoder: new CursorCoder(),
        );
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->getContainer()->get(EntityManagerInterface::class);
    }

    public function persist(object $entity): void
    {
        $this->getEntityManager()->persist($entity);
    }

    public function flush(): void
    {
        $this->getEntityManager()->run();
    }
}
