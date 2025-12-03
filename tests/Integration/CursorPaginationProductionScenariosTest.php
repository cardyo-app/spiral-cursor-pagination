<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Integration;

use Cardyo\SpiralCursorPagination\CursorEncoder\CursorCoder;
use Cardyo\SpiralCursorPagination\Service\ConnectionFactory;
use Cardyo\SpiralCursorPagination\Service\CursorGenerator;
use Cardyo\SpiralCursorPagination\Service\PaginationMetadataCalculator;
use Cardyo\SpiralCursorPagination\Specification\Pagination\CursorPaginator;
use Cardyo\Tests\SpiralCursorPagination\Fixtures;
use Cycle\Database\DatabaseManager;
use Cycle\Database\DatabaseProviderInterface;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use Cycle\ORM\Select;
use PHPUnit\Framework\Attributes\Test;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Filter\Gte;
use Spiral\DataGrid\Specification\Filter\Lte;
use Spiral\DataGrid\Specification\Sorter\Sorter;

/**
 * Production scenarios and real-world use cases.
 *
 * Simulates actual API endpoints and user workflows:
 * - User search with live filtering
 * - Admin dashboards with sorting
 * - Mobile app infinite scroll
 * - Data consistency across pagination
 * - Composite cursor scenarios
 */
class CursorPaginationProductionScenariosTest extends AbstractTestCase
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
     * Scenario: User dashboard with activity filtering
     * Real app: Admin views active users, sorted by recent activity
     */
    #[Test]
    public function testUserDashboardScenario(): void
    {
        $this->seedRealisticCustomerData();

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);

        $gridSchema = new GridSchema();

        // Filter: only active users (logged in within last 30 days)
        $thirtyDaysAgo = new \DateTimeImmutable('-30 days');
        $gridSchema->addFilter('recent', new Gte('last_activity_at', $thirtyDaysAgo->format('Y-m-d')));

        // Sort by most recent activity
        $gridSchema->addSorter('activity', new Sorter('last_activity_at'));

        $paginator = $this->createPaginator(20);
        $gridSchema->setPaginator($paginator);

        // First page
        $grid = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'filter' => ['recent' => true],
                'sort' => ['activity' => 'desc'],
                'paginate' => ['first' => 10],
            ]))
            ->create(clone $select, $gridSchema);

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

        // Verify results are filtered and sorted correctly
        $this->assertGreaterThan(0, count($connection->nodes));

        foreach ($connection->nodes as $node) {
            $this->assertGreaterThanOrEqual(
                $thirtyDaysAgo->getTimestamp(),
                $node->lastActivityAt->getTimestamp(),
                "User {$node->name} should have recent activity"
            );
        }

        // Verify descending order
        for ($i = 0; $i < count($connection->nodes) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                $connection->nodes[$i + 1]->lastActivityAt->getTimestamp(),
                $connection->nodes[$i]->lastActivityAt->getTimestamp(),
                'Should be sorted by activity DESC'
            );
        }
    }

    /**
     * Scenario: Mobile app infinite scroll
     * Real app: Instagram/Twitter-like feed loading more items as user scrolls
     */
    #[Test]
    public function testInfiniteScrollScenario(): void
    {
        $this->seedManyCustomers(50); // Simulate larger dataset

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        $select = $select->orderBy('created_at', 'DESC'); // Most recent first

        $gridSchema = new GridSchema();
        $paginator = $this->createPaginator(50);
        $gridSchema->setPaginator($paginator);

        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );

        $pageSize = 10;
        $loadedItems = [];
        $cursor = null;
        $pageCount = 0;

        // Simulate infinite scroll - load pages until no more data
        while (true) {
            $input = ['first' => $pageSize];
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

            $pageCount++;
            array_push($loadedItems, ...$connection->nodes);

            if (!$connection->pageInfo->hasNextPage) {
                break;
            }

            $cursor = $connection->pageInfo->endCursor;

            // Safety: don't loop forever
            if ($pageCount > 10) {
                break;
            }
        }

        // Should have loaded all 50 items across 5 pages
        $this->assertCount(50, $loadedItems);
        $this->assertEquals(5, $pageCount);

        // Items should be in descending order by created_at
        for ($i = 0; $i < count($loadedItems) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                $loadedItems[$i + 1]->createdAt->getTimestamp(),
                $loadedItems[$i]->createdAt->getTimestamp()
            );
        }
    }

    /**
     * Scenario: Search results pagination
     * Real app: Google-like search with "Next" pagination
     */
    #[Test]
    public function testSearchResultsPagination(): void
    {
        $this->seedSearchableCustomers();

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);

        $gridSchema = new GridSchema();

        // Search by name
        $gridSchema->addFilter(
            'query',
            new \Spiral\DataGrid\Specification\Filter\Like('name', '%Tech%')
        );

        // Sort by relevance (simplified: by name)
        $gridSchema->addSorter('name', new Sorter('name'));

        $paginator = $this->createPaginator(100);
        $gridSchema->setPaginator($paginator);

        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );

        // Page 1
        $grid1 = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'filter' => ['query' => true],
                'sort' => ['name' => 'asc'],
                'paginate' => ['first' => 3],
            ]))
            ->create(clone $select, $gridSchema);

        $results1 = iterator_to_array($grid1->getIterator());
        $connection1 = $connectionFactory->createConnection(
            results: $results1,
            query: $grid1->getSource(),
            paginatorState: ['first' => 3],
            encoder: new CursorCoder(),
        );

        $this->assertCount(3, $connection1->nodes);
        foreach ($connection1->nodes as $node) {
            $this->assertStringContainsString('Tech', $node->name);
        }

        // Page 2 - user clicks "Next"
        $grid2 = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'filter' => ['query' => true],
                'sort' => ['name' => 'asc'],
                'paginate' => [
                    'first' => 3,
                    'after' => $connection1->pageInfo->endCursor,
                ],
            ]))
            ->create(clone $select, $gridSchema);

        $results2 = iterator_to_array($grid2->getIterator());
        $connection2 = $connectionFactory->createConnection(
            results: $results2,
            query: $grid2->getSource(),
            paginatorState: [
                'first' => 3,
                'after' => $connection1->pageInfo->endCursor,
            ],
            encoder: new CursorCoder(),
        );

        // Page 2 should also match search query
        foreach ($connection2->nodes as $node) {
            $this->assertStringContainsString('Tech', $node->name);
        }

        // No duplicate items between pages
        $page1Ids = array_map(fn($n) => $n->uuid, $connection1->nodes);
        $page2Ids = array_map(fn($n) => $n->uuid, $connection2->nodes);
        $this->assertEmpty(array_intersect($page1Ids, $page2Ids), 'Pages should not have duplicate items');
    }

    /**
     * Scenario: Data table with dynamic column sorting
     * Real app: Admin panel where user can click column headers to sort
     */
    #[Test]
    public function testDynamicColumnSorting(): void
    {
        $this->seedRealisticCustomerData();

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);

        $gridSchema = new GridSchema();
        $gridSchema->addSorter('name', new Sorter('name'));
        $gridSchema->addSorter('email', new Sorter('email'));
        $gridSchema->addSorter('logins', new Sorter('login_count'));

        $paginator = $this->createPaginator(50);
        $gridSchema->setPaginator($paginator);

        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );

        // User sorts by name ASC
        $grid1 = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'sort' => ['name' => 'asc'],
                'paginate' => ['first' => 5],
            ]))
            ->create(clone $select, $gridSchema);

        $results1 = iterator_to_array($grid1->getIterator());
        $connection1 = $connectionFactory->createConnection(
            results: $results1,
            query: $grid1->getSource(),
            paginatorState: ['first' => 5],
            encoder: new CursorCoder(),
        );

        $firstByName = $connection1->nodes[0]->name;

        // User clicks "Name" column header again -> DESC
        $grid2 = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'sort' => ['name' => 'desc'],
                'paginate' => ['first' => 5],
            ]))
            ->create(clone $select, $gridSchema);

        $results2 = iterator_to_array($grid2->getIterator());
        $connection2 = $connectionFactory->createConnection(
            results: $results2,
            query: $grid2->getSource(),
            paginatorState: ['first' => 5],
            encoder: new CursorCoder(),
        );

        $firstByNameDesc = $connection2->nodes[0]->name;

        // First items should be different (opposite ends of alphabet)
        $this->assertNotEquals($firstByName, $firstByNameDesc);

        // User clicks "Logins" column -> sort by login count
        $grid3 = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'sort' => ['logins' => 'desc'],
                'paginate' => ['first' => 5],
            ]))
            ->create(clone $select, $gridSchema);

        $results3 = iterator_to_array($grid3->getIterator());
        $connection3 = $connectionFactory->createConnection(
            results: $results3,
            query: $grid3->getSource(),
            paginatorState: ['first' => 5],
            encoder: new CursorCoder(),
        );

        // Should be sorted by login count DESC
        for ($i = 0; $i < count($connection3->nodes) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                $connection3->nodes[$i + 1]->loginCount,
                $connection3->nodes[$i]->loginCount
            );
        }
    }

    /**
     * Scenario: Date range filtering with pagination
     * Real app: Analytics dashboard showing users by date range
     */
    #[Test]
    public function testDateRangeFilteringWithPagination(): void
    {
        $this->seedRealisticCustomerData();

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);

        $gridSchema = new GridSchema();

        // Date range filter: last 7 days
        $sevenDaysAgo = new \DateTimeImmutable('-7 days');
        $today = new \DateTimeImmutable();

        $gridSchema->addFilter('start', new Gte('created_at', $sevenDaysAgo->format('Y-m-d')));
        $gridSchema->addFilter('end', new Lte('created_at', $today->format('Y-m-d')));
        $gridSchema->addSorter('created', new Sorter('created_at'));

        $paginator = $this->createPaginator(50);
        $gridSchema->setPaginator($paginator);

        $grid = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'filter' => [
                    'start' => true,
                    'end' => true,
                ],
                'sort' => ['created' => 'desc'],
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

        // All results should be within date range
        foreach ($connection->nodes as $node) {
            $this->assertGreaterThanOrEqual(
                $sevenDaysAgo->getTimestamp(),
                $node->createdAt->getTimestamp()
            );
            $this->assertLessThanOrEqual(
                $today->getTimestamp(),
                $node->createdAt->getTimestamp()
            );
        }
    }

    /**
     * Scenario: Composite sorting (multiple sort fields)
     * Real app: Sort by status (primary), then by date (secondary)
     */
    #[Test]
    public function testCompositeSortingScenario(): void
    {
        $this->seedCustomersWithSameLoginCount();

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        // Sort by login_count DESC, then by name ASC (for ties)
        $select = $select->orderBy('login_count', 'DESC')->orderBy('name', 'ASC');

        $gridSchema = new GridSchema();
        $paginator = $this->createPaginator(50);
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

        // Find customers with same login count and verify secondary sort
        $customersWithTen = array_filter(
            $connection->nodes,
            fn($c) => $c->loginCount === 10
        );

        if (count($customersWithTen) > 1) {
            $names = array_map(fn($c) => $c->name, $customersWithTen);
            $sortedNames = $names;
            sort($sortedNames);

            $this->assertEquals(
                $sortedNames,
                array_values($names),
                'Within same login_count, should be sorted by name ASC'
            );
        }
    }

    private function createPaginator(int $limit): CursorPaginator
    {
        return new CursorPaginator(
            defaultLimit: $limit,
            limitValue: new \Spiral\DataGrid\Specification\Value\RangeValue(
                new \Spiral\DataGrid\Specification\Value\IntValue(),
                \Spiral\DataGrid\Specification\Value\RangeValue\Boundary::including(1),
                \Spiral\DataGrid\Specification\Value\RangeValue\Boundary::including(100),
            ),
            cursorCoder: new CursorCoder(),
        );
    }

    private function seedRealisticCustomerData(): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);

        $now = new \DateTimeImmutable();

        $customers = [
            ['name' => 'Alice Johnson', 'days_ago' => 1, 'logins' => 50],
            ['name' => 'Bob Smith', 'days_ago' => 3, 'logins' => 45],
            ['name' => 'Carol Williams', 'days_ago' => 5, 'logins' => 40],
            ['name' => 'David Brown', 'days_ago' => 7, 'logins' => 35],
            ['name' => 'Eve Davis', 'days_ago' => 10, 'logins' => 30],
            ['name' => 'Frank Miller', 'days_ago' => 15, 'logins' => 25],
            ['name' => 'Grace Wilson', 'days_ago' => 20, 'logins' => 20],
            ['name' => 'Henry Moore', 'days_ago' => 25, 'logins' => 15],
            ['name' => 'Ivy Taylor', 'days_ago' => 30, 'logins' => 10],
            ['name' => 'Jack Anderson', 'days_ago' => 40, 'logins' => 5],
        ];

        foreach ($customers as $i => $data) {
            $customer = new Fixtures\Entity\Customer(
                uuid: sprintf('00000000-0000-0000-0000-%012d', $i + 1),
                name: $data['name'],
                email: strtolower(str_replace(' ', '.', $data['name'])) . '@example.com',
                createdAt: $now->modify("-{$data['days_ago']} days"),
                lastActivityAt: $now->modify("-{$data['days_ago']} days"),
                loginCount: $data['logins'],
            );
            $em->persist($customer);
        }

        $em->run();
    }

    private function seedManyCustomers(int $count): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();

        for ($i = 1; $i <= $count; $i++) {
            $customer = new Fixtures\Entity\Customer(
                uuid: sprintf('00000000-0000-0000-0000-%012d', $i),
                name: "Customer $i",
                email: "customer$i@example.com",
                createdAt: $now->modify("-$i hours"),
                lastActivityAt: $now->modify("-$i hours"),
                loginCount: $i,
            );
            $em->persist($customer);
        }

        $em->run();
    }

    private function seedSearchableCustomers(): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);

        $names = [
            'TechCorp Solutions',
            'Digital Tech Inc',
            'Tech Innovations Ltd',
            'Modern Tech Group',
            'Tech Enterprises LLC',
            'Global Services Corp',
            'Business Solutions Inc',
        ];

        foreach ($names as $i => $name) {
            $customer = new Fixtures\Entity\Customer(
                uuid: sprintf('00000000-0000-0000-0000-%012d', $i + 1),
                name: $name,
                email: strtolower(str_replace(' ', '.', $name)) . '@example.com',
                createdAt: new \DateTimeImmutable("2024-01-" . sprintf('%02d', $i + 1)),
                lastActivityAt: new \DateTimeImmutable("2024-01-" . sprintf('%02d', $i + 1)),
                loginCount: $i + 1,
            );
            $em->persist($customer);
        }

        $em->run();
    }

    private function seedCustomersWithSameLoginCount(): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);

        $customers = [
            ['name' => 'Alpha', 'logins' => 10],
            ['name' => 'Beta', 'logins' => 10],
            ['name' => 'Gamma', 'logins' => 10],
            ['name' => 'Delta', 'logins' => 20],
            ['name' => 'Epsilon', 'logins' => 20],
            ['name' => 'Zeta', 'logins' => 30],
        ];

        foreach ($customers as $i => $data) {
            $customer = new Fixtures\Entity\Customer(
                uuid: sprintf('00000000-0000-0000-0000-%012d', $i + 1),
                name: $data['name'],
                email: strtolower($data['name']) . '@example.com',
                createdAt: new \DateTimeImmutable("2024-01-" . sprintf('%02d', $i + 1)),
                lastActivityAt: new \DateTimeImmutable("2024-01-" . sprintf('%02d', $i + 1)),
                loginCount: $data['logins'],
            );
            $em->persist($customer);
        }

        $em->run();
    }
}
