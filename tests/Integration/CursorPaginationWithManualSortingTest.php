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

/**
 * Test cursor pagination using the interceptor pattern with manual sorting.
 *
 * This demonstrates the production usage pattern where controllers use
 * the #[CursorPaginate] attribute and GridSchema classes.
 */
class CursorPaginationWithManualSortingTest extends AbstractTestCase
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
     * Test basic pagination with different sort orders
     */
    #[Test]
    #[DataProvider('sortFieldProvider')]
    public function testPaginationWithDifferentSorting(
        string $sortField,
        string $sortDirection,
        array $expectedOrder,
    ): void {
        $this->seedTestCustomers();

        // Register GridSchema in container
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
            "/customers?sort[{$sortField}]={$sortDirection}&paginate[first]=3"
        );

        $connection = $this->executeController($controller, 'index', $request);

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertCount(3, $connection->nodes);

        // Verify order
        $actualNames = array_map(fn($c) => $c->name, $connection->nodes);
        $this->assertEquals(
            array_slice($expectedOrder, 0, 3),
            $actualNames,
            "Results should be ordered correctly"
        );

        $this->assertTrue($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
    }

    public static function sortFieldProvider(): iterable
    {
        yield 'activity DESC' => [
            'sortField' => 'activity',
            'sortDirection' => 'desc',
            'expectedOrder' => ['Customer 5', 'Customer 4', 'Customer 3', 'Customer 2', 'Customer 1'],
        ];

        yield 'activity ASC' => [
            'sortField' => 'activity',
            'sortDirection' => 'asc',
            'expectedOrder' => ['Customer 1', 'Customer 2', 'Customer 3', 'Customer 4', 'Customer 5'],
        ];

        yield 'logins DESC' => [
            'sortField' => 'logins',
            'sortDirection' => 'desc',
            'expectedOrder' => ['Customer 5', 'Customer 4', 'Customer 3', 'Customer 2', 'Customer 1'],
        ];

        yield 'logins ASC' => [
            'sortField' => 'logins',
            'sortDirection' => 'asc',
            'expectedOrder' => ['Customer 1', 'Customer 2', 'Customer 3', 'Customer 4', 'Customer 5'],
        ];
    }

    /**
     * Test cursor navigation (forward and backward)
     */
    #[Test]
    public function testCursorNavigation(): void
    {
        $this->seedTestCustomers();

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

        // First page
        $request = new ServerRequest('GET', '/customers?sort[logins]=desc&paginate[first]=3');
        $firstPage = $this->executeController($controller, 'index', $request);

        $this->assertCount(3, $firstPage->nodes);
        $this->assertEquals('Customer 5', $firstPage->nodes[0]->name);
        $this->assertTrue($firstPage->pageInfo->hasNextPage);

        // Second page using cursor
        $afterCursor = $firstPage->pageInfo->endCursor;
        $request2 = new ServerRequest('GET', "/customers?sort[logins]=desc&paginate[first]=2&paginate[after]={$afterCursor}");
        $secondPage = $this->executeController($controller, 'index', $request2);

        $this->assertCount(2, $secondPage->nodes);
        $this->assertEquals('Customer 2', $secondPage->nodes[0]->name);
        $this->assertTrue($secondPage->pageInfo->hasPreviousPage);
    }

    /**
     * Test backward pagination
     */
    #[Test]
    public function testBackwardPagination(): void
    {
        $this->seedTestCustomers();

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

        // Get a cursor from middle of dataset
        $request = new ServerRequest('GET', '/customers?sort[logins]=desc&paginate[first]=3');
        $firstPage = $this->executeController($controller, 'index', $request);
        $cursor = $firstPage->pageInfo->endCursor;

        // Go backwards from that cursor
        $request2 = new ServerRequest('GET', "/customers?sort[logins]=desc&paginate[last]=2&paginate[before]={$cursor}");
        $backPage = $this->executeController($controller, 'index', $request2);

        $this->assertCount(2, $backPage->nodes);
        $this->assertEquals('Customer 5', $backPage->nodes[0]->name);
    }

    private function seedTestCustomers(): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);

        for ($i = 1; $i <= 5; $i++) {
            $customer = new Fixtures\Entity\Customer(
                uuid: sprintf('00000000-0000-0000-0000-%012d', $i),
                name: "Customer $i",
                email: "customer{$i}@example.com",
                createdAt: new DateTimeImmutable(sprintf("2024-01-%02dT10:00:00Z", $i)),
                lastActivityAt: new DateTimeImmutable(sprintf("2024-01-%02dT%02d:00:00Z", $i, 10 + $i)),
                loginCount: $i * 10,
            );

            $em->persist($customer);
        }

        $em->run();
    }
}
