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
use Spiral\Core\Core;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Filter\Like;
use Spiral\DataGrid\Specification\Sorter\Sorter;

final class CursorPaginationInterceptorTest extends AbstractTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $helper = new CursorPaginationHelper(
            gridFactory: self::createGridFactory(),
        );

        $this->interceptor = new CursorPaginationInterceptor(
            paginationHelper: $helper,
            orm: $this->getContainer()->get(ORM::class),
            request: new ServerRequest('GET', '/customers?first=3'),
            container: $this->getContainer(),
        );

        $this->core = new Core($this->getContainer());
    }

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

        $controller = new class {
            public function index(
                #[CursorPaginate(Fixtures\Entity\Customer::class)]
                Connection $connection
            ): Connection {
                return $connection;
            }
        };

        $result = $this->interceptor->process(
            $controller::class,
            'index',
            [],
            $this->core,
        );

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

        $controller = new class {
            public function index(
                #[CursorPaginate(
                    entity: Fixtures\Entity\Customer::class,
                    schema: TestCustomerGridSchema::class,
                )]
                Connection $connection
            ): Connection {
                return $connection;
            }
        };

        // Create interceptor with filter enabled and sort params
        $interceptor = new CursorPaginationInterceptor(
            paginationHelper: $helper,
            orm: $this->getContainer()->get(ORM::class),
            request: new ServerRequest('GET', '/customers?filter[search]=1&sort[name]=asc&first=10'),
            container: $this->getContainer(),
        );

        $result = $interceptor->process(
            $controller::class,
            'index',
            [],
            $this->core,
        );

        $this->assertInstanceOf(Connection::class, $result);
        $this->assertCount(1, $result->nodes);
        $this->assertEquals('Customer 2', $result->nodes[0]->name);
    }

    #[Test]
    public function testInterceptorWithMapper(): void
    {
        $this->seedTestCustomers();

        $controller = new class {
            public function index(
                #[CursorPaginate(
                    entity: Fixtures\Entity\Customer::class,
                    mapper: [self::class, 'mapToDTO'],
                )]
                Connection $connection
            ): Connection {
                return $connection;
            }

            public static function mapToDTO(Fixtures\Entity\Customer $customer): array
            {
                return [
                    'id' => $customer->uuid,
                    'customer_name' => $customer->name,
                ];
            }
        };

        $result = $this->interceptor->process(
            $controller::class,
            'index',
            [],
            $this->core,
        );

        $this->assertInstanceOf(Connection::class, $result);
        $this->assertCount(3, $result->nodes);
        $this->assertIsArray($result->nodes[0]);
        $this->assertArrayHasKey('customer_name', $result->nodes[0]);
    }

    #[Test]
    public function testInterceptorWithCustomPageSize(): void
    {
        $this->seedTestCustomers();

        $controller = new class {
            public function index(
                #[CursorPaginate(
                    entity: Fixtures\Entity\Customer::class,
                    pageSize: 2,
                    maxPageSize: 50,
                )]
                Connection $connection
            ): Connection {
                return $connection;
            }
        };

        // Request without explicit limit - should use default from attribute
        $interceptor = new CursorPaginationInterceptor(
            paginationHelper: new CursorPaginationHelper(
                gridFactory: self::createGridFactory(),
            ),
            orm: $this->getContainer()->get(ORM::class),
            request: new ServerRequest('GET', '/customers'),
            container: $this->getContainer(),
        );

        $result = $interceptor->process(
            $controller::class,
            'index',
            [],
            $this->core,
        );

        $this->assertInstanceOf(Connection::class, $result);
        $this->assertCount(2, $result->nodes);
    }

    #[Test]
    public function testInterceptorWithTotalCount(): void
    {
        $this->seedTestCustomers();

        $controller = new class {
            public function index(
                #[CursorPaginate(
                    entity: Fixtures\Entity\Customer::class,
                    countTotal: true,
                )]
                Connection $connection
            ): Connection {
                return $connection;
            }
        };

        $result = $this->interceptor->process(
            $controller::class,
            'index',
            [],
            $this->core,
        );

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

        $result = $this->interceptor->process(
            $controller::class,
            'index',
            ['id' => '123'],
            $this->core,
        );

        $this->assertEquals('id: 123', $result);
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
