<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Integration;

use Cardyo\SpiralCursorPagination\Attribute\CursorPaginate;
use Cardyo\SpiralCursorPagination\Interceptor\CursorPaginationInterceptor;
use Cardyo\SpiralCursorPagination\Response\Connection;
use Cardyo\SpiralCursorPagination\Service\CursorPaginationHelper;
use Cardyo\Tests\SpiralCursorPagination\Fixtures;
use Cycle\Database\DatabaseManager;
use Cycle\Database\DatabaseProviderInterface;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Filter\Like;
use Spiral\DataGrid\Specification\Sorter\Sorter;

final class CursorPaginationInterceptorTest extends AbstractTestCase
{

    #[\Override]
    protected function defineMigrations(DatabaseManager $dbal): void
    {
        $schema = $dbal->database()->table('customers')->getSchema();

        $schema->uuid('uuid');
        $schema->string('name');
        $schema->string('email');
        $schema->datetime('created_at');
        $schema->datetime('last_activity_at')->nullable();
        $schema->integer('login_count')->default(0);

        $schema->index(['uuid'])->unique();
        $schema->index(['last_activity_at']);
        $schema->index(['login_count']);

        $schema->save();
    }

    #[Test]
    public function testInterceptorWithMinimalAttribute(): void
    {
        $this->seedTestCustomers();

        // Set up minimal GridSchema with sorter and paginator
        $gridSchema = new GridSchema();
        $gridSchema->addSorter('uuid', new Sorter('uuid'));
        $gridSchema->setPaginator($this->paginationHelper->createPaginator());
        $this->getContainer()->bindSingleton(TestMinimalGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestMinimalGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select()->orderBy('uuid', 'ASC');
            }
        };

        $request = new ServerRequest('GET', '/customers?paginate[first]=3');
        $result = $this->executeController($controller, 'index', $request);

        $this->assertInstanceOf(Connection::class, $result);
        $this->assertCount(3, $result->nodes);
        $this->assertCount(3, $result->edges);
    }

    #[Test]
    public function testInterceptorWithExplicitSchema(): void
    {
        $this->seedTestCustomers();

        // Register a GridSchema in the container
        $schema = new GridSchema();
        $schema->addFilter('search', new Like('name', '%Customer 2%'));
        $schema->addSorter('name', new Sorter('name'));

        $helper = new CursorPaginationHelper(
            gridFactory: self::createGridFactory(),
        );
        $schema->setPaginator($helper->createPaginator());

        $this->getContainer()->bindSingleton(TestCustomerGridSchema::class, fn() => $schema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestCustomerGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select()->orderBy('uuid', 'ASC');
            }
        };

        // Execute with filter enabled and sort params
        $request = new ServerRequest('GET', '/customers?filter[search]=1&sort[name]=asc&paginate[first]=10');
        $result = $this->executeController($controller, 'index', $request);

        $this->assertInstanceOf(Connection::class, $result);
        $this->assertCount(1, $result->nodes);
        $this->assertEquals('Customer 2', $result->nodes[0]->name);
    }

    #[Test]
    public function testInterceptorWithView(): void
    {
        $this->seedTestCustomers();

        // Set up GridSchema with sorter and paginator
        $gridSchema = new GridSchema();
        $gridSchema->addSorter('uuid', new Sorter('uuid'));
        $gridSchema->setPaginator($this->paginationHelper->createPaginator());
        $this->getContainer()->bindSingleton(TestViewMapperGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestViewMapperGridSchema::class, view: [self::class, 'mapToDTO'])]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select()->orderBy('uuid', 'ASC');
            }

            public static function mapToDTO(Fixtures\Entity\Customer $customer): array
            {
                return [
                    'id' => $customer->uuid,
                    'customer_name' => $customer->name,
                ];
            }
        };

        $request = new ServerRequest('GET', '/customers?paginate[first]=3');
        $result = $this->executeController($controller, 'index', $request);

        $this->assertInstanceOf(Connection::class, $result);
        $this->assertCount(3, $result->nodes);
        // After JSON round-trip, mapped arrays become stdClass objects
        $this->assertIsObject($result->nodes[0]);
        $this->assertObjectHasProperty('customer_name', $result->nodes[0]);
    }

    #[Test]
    public function testInterceptorWithCustomPageSize(): void
    {
        $this->seedTestCustomers();

        // Set up GridSchema with custom page size
        $gridSchema = new GridSchema();
        $gridSchema->addSorter('uuid', new Sorter('uuid'));
        $gridSchema->setPaginator($this->paginationHelper->createPaginator(defaultLimit: 2, maxLimit: 50));
        $this->getContainer()->bindSingleton(TestCustomPageSizeGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestCustomPageSizeGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select()->orderBy('uuid', 'ASC');
            }
        };

        // Request without explicit limit - should use default from GridSchema
        $request = new ServerRequest('GET', '/customers');
        $result = $this->executeController($controller, 'index', $request);

        $this->assertInstanceOf(Connection::class, $result);
        $this->assertCount(2, $result->nodes);
    }

    #[Test]
    public function testInterceptorWithTotalCount(): void
    {
        $this->seedTestCustomers();

        // Set up GridSchema with sorter and paginator
        $gridSchema = new GridSchema();
        $gridSchema->addSorter('uuid', new Sorter('uuid'));
        $gridSchema->setPaginator($this->paginationHelper->createPaginator());
        $this->getContainer()->bindSingleton(TestTotalCountGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestTotalCountGridSchema::class, countTotal: true)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select()->orderBy('uuid', 'ASC');
            }
        };

        $request = new ServerRequest('GET', '/customers?paginate[first]=3');
        $result = $this->executeController($controller, 'index', $request);

        $this->assertInstanceOf(Connection::class, $result);
        $this->assertEquals(5, $result->totalCount);
    }

    #[Test]
    public function testInterceptorDoesNotModifyParametersWithoutAttribute(): void
    {
        $controller = new class {
            public function index(string $id): string
            {
                return "id: $id";
            }
        };

        $request = new ServerRequest('GET', '/customers?paginate[first]=3');
        $result = $this->executeController($controller, 'index', $request, ['id' => '123']);

        $this->assertEquals('id: 123', $result);
    }

    #[Test]
    public function testInterceptorWithMethodAttributeAndSelectReturn(): void
    {
        $this->seedTestCustomers();

        // Controller returns a customized Select query
        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestCustomerGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                // Simulate tenant filtering or other custom query modifications
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select()
                    ->where('login_count', '>=', 20); // Filter for customers with 20+ logins
            }
        };

        // Register a GridSchema in the container
        $schema = new GridSchema();
        $schema->addSorter('name', new Sorter('name'));

        $helper = new CursorPaginationHelper(
            gridFactory: self::createGridFactory(),
        );
        $schema->setPaginator($helper->createPaginator());

        $this->getContainer()->bindSingleton(TestCustomerGridSchema::class, fn() => $schema);

        $request = new ServerRequest('GET', '/customers?sort[name]=asc&paginate[first]=10');
        $result = $this->executeController($controller, 'index', $request);

        $this->assertInstanceOf(Connection::class, $result);
        // Should have customers 2, 3, 4, 5 (login_count >= 20: 20, 30, 40, 50)
        $this->assertCount(4, $result->nodes);
        foreach ($result->nodes as $customer) {
            $this->assertGreaterThanOrEqual(20, $customer->loginCount);
        }
    }

    private function seedTestCustomers(): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);

        for ($i = 1; $i <= 5; $i++) {
            $customer = new Fixtures\Entity\Customer(
                uuid: sprintf('00000000-0000-0000-0000-%012d', $i),
                name: "Customer $i",
                email: "customer{$i}@example.com",
                createdAt: new \DateTimeImmutable(sprintf("2024-01-%02dT10:00:00Z", $i)),
                lastActivityAt: new \DateTimeImmutable(sprintf("2024-01-%02dT%02d:00:00Z", $i, 10 + $i)),
                loginCount: $i * 10,
            );

            $em->persist($customer);
        }

        $em->run();
    }

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
                    'loginCount' => 'login_count',
                    'lastActivityAt' => 'last_activity_at',
                    'createdAt' => 'created_at',
                ],
                SchemaInterface::TYPECAST => [
                    'uuid' => 'uuid',
                    'loginCount' => 'int',
                    'lastActivityAt' => 'datetime',
                    'createdAt' => 'datetime',
                ],
                SchemaInterface::RELATIONS => [],
            ],
        ]);
    }
}

// Dummy class for testing schema resolution
class TestCustomerGridSchema extends GridSchema {}

// GridSchema for minimal tests
class TestMinimalGridSchema extends GridSchema {}

// GridSchema for custom page size tests
class TestCustomPageSizeGridSchema extends GridSchema {}

// GridSchema for view mapper tests
class TestViewMapperGridSchema extends GridSchema {}

// GridSchema for total count tests
class TestTotalCountGridSchema extends GridSchema {}
