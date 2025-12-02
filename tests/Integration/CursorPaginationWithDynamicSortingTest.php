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
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use Cycle\ORM\Select;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Sorter\Sorter;
use Spiral\DataGrid\Specification\Value\IntValue;
use Spiral\DataGrid\Specification\Value\RangeValue;
use Spiral\DataGrid\Specification\Value\RangeValue\Boundary;

/**
 * Test cursor pagination with dynamic sorting (sort fields not known at schema construction).
 *
 * This demonstrates the pattern where:
 * 1. GridSchema is constructed once (like in a service/grid class)
 * 2. Sort parameters come from user input at runtime
 * 3. Cursor pagination automatically detects sort fields from the compiled query
 */
class CursorPaginationWithDynamicSortingTest extends AbstractTestCase
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
     * Test that cursor pagination works WITHOUT calling withSortFields()
     *
     * This simulates the production pattern where the GridSchema is constructed
     * once (e.g., in CustomerGrid constructor) without knowing what sort parameters
     * the user will request.
     */
    #[Test]
    public function testDynamicSortFieldDetection(): void
    {
        $this->seedTestCustomers();

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);

        // Create grid schema like in production (e.g., CustomerGrid constructor)
        // Notice: NO withSortFields() call here because sort params aren't known yet
        $gridSchema = new GridSchema();
        $gridSchema->addSorter('activity', new Sorter('last_activity_at'));
        $paginator = $this->createPaginator(3);
        $gridSchema->setPaginator($paginator);

        // User requests with specific sort direction
        $grid = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'sort' => ['activity' => 'desc'],
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

        // Test the actual Connection response
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

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);

        // Grid schema without sort field configuration
        $gridSchema = new GridSchema();
        $gridSchema->addSorter('logins', new Sorter('login_count'));
        $gridSchema->setPaginator($this->createPaginator(2));

        // First page
        $grid = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'sort' => ['logins' => 'desc'],
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

        // Test Connection has correct data
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

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);
        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );

        // Grid schema offers multiple sort options
        $gridSchema = new GridSchema();
        $gridSchema->addSorter('activity', new Sorter('last_activity_at'));
        $gridSchema->addSorter('logins', new Sorter('login_count'));
        $gridSchema->setPaginator($this->createPaginator(3));

        // User chooses to sort by activity
        $grid1 = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'sort' => ['activity' => 'desc'],
                'paginate' => ['first' => 3],
            ]))
            ->create($select, $gridSchema);

        $results1 = iterator_to_array($grid1->getIterator());
        $connection1 = $connectionFactory->createConnection(
            results: $results1,
            query: $grid1->getSource(),
            paginatorState: $gridSchema->getPaginator()->getValue(),
            encoder: new CursorCoder(),
        );

        $this->assertEquals('Customer 5', $connection1->nodes[0]->name); // Latest activity
        $this->assertCount(3, $connection1->nodes);

        // User chooses to sort by logins
        $grid2 = self::createGridFactory()
            ->withInput(new \Spiral\DataGrid\Input\ArrayInput([
                'sort' => ['logins' => 'desc'],
                'paginate' => ['first' => 3],
            ]))
            ->create($select, $gridSchema);

        $results2 = iterator_to_array($grid2->getIterator());
        $connection2 = $connectionFactory->createConnection(
            results: $results2,
            query: $grid2->getSource(),
            paginatorState: $gridSchema->getPaginator()->getValue(),
            encoder: new CursorCoder(),
        );

        $this->assertEquals(50, $connection2->nodes[0]->loginCount); // Most logins
        $this->assertCount(3, $connection2->nodes);
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
