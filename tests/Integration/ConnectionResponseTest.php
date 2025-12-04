<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Integration;

use Cardyo\SpiralCursorPagination\Attribute\CursorPaginate;
use Cardyo\SpiralCursorPagination\Response\ConnectionResponseInterface;
use Cardyo\Tests\SpiralCursorPagination\Fixtures;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Sorter\Sorter;

/**
 * Test ConnectionResponse JSON serialization and view mapper resolution.
 */
final class ConnectionResponseTest extends AbstractTestCase
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
                ],
                SchemaInterface::TYPECAST => [
                    'uuid' => 'uuid',
                    'createdAt' => 'datetime',
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
        $schema->index(['uuid'])->unique();
        $schema->save();
    }

    #[Test]
    public function testConnectionResponseJsonSerializationStructure(): void
    {
        $this->seedTestCustomers();

        // Set up GridSchema
        $gridSchema = new GridSchema();
        $gridSchema->addSorter('uuid', new Sorter('uuid'));
        $gridSchema->setPaginator($this->paginationHelper->createPaginator());
        $this->getContainer()->bindSingleton(TestResponseGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: TestResponseGridSchema::class, countTotal: true)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select()
                    ->orderBy('uuid', 'ASC');
            }
        };

        $request = new ServerRequest('GET', '/customers?paginate[first]=3');

        // Get raw response (before unwrapping in test helper)
        $interceptor = $this->createInterceptor($request);
        $reflection = new \ReflectionMethod($controller, 'index');
        $target = \Spiral\Interceptors\Context\Target::fromReflectionMethod($reflection, $controller);
        $context = new \Spiral\Interceptors\Context\CallContext($target, []);
        $handler = new \Spiral\Interceptors\Handler\CallableHandler();
        $result = $interceptor->intercept($context, $handler);

        // Verify it returns ConnectionResponseInterface (which is PSR-7 ResponseInterface)
        $this->assertInstanceOf(ConnectionResponseInterface::class, $result);
        $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result);

        // Read JSON body and verify structure
        $jsonBody = (string) $result->getBody();
        $json = json_decode($jsonBody, true, 512, JSON_THROW_ON_ERROR);

        // Verify HTTP status code
        $this->assertEquals(200, $result->getStatusCode());

        // Verify Content-Type header
        $this->assertEquals('application/json', $result->getHeaderLine('Content-Type'));

        $this->assertArrayHasKey('data', $json);
        $data = $json['data'];

        // Verify edges structure
        $this->assertArrayHasKey('edges', $data);
        $this->assertCount(3, $data['edges']);
        $this->assertArrayHasKey('cursor', $data['edges'][0]);
        $this->assertArrayHasKey('node', $data['edges'][0]);

        // Verify pageInfo structure
        $this->assertArrayHasKey('pageInfo', $data);
        $this->assertArrayHasKey('hasNextPage', $data['pageInfo']);
        $this->assertArrayHasKey('hasPreviousPage', $data['pageInfo']);
        $this->assertArrayHasKey('startCursor', $data['pageInfo']);
        $this->assertArrayHasKey('endCursor', $data['pageInfo']);
        $this->assertTrue($data['pageInfo']['hasNextPage']);
        $this->assertFalse($data['pageInfo']['hasPreviousPage']);

        // Verify nodes array included by default
        $this->assertArrayHasKey('nodes', $data);
        $this->assertCount(3, $data['nodes']);

        // Verify totalCount included
        $this->assertArrayHasKey('totalCount', $data);
        $this->assertEquals(5, $data['totalCount']);
    }

    #[Test]
    public function testViewMapperResolvedFromContainer(): void
    {
        $this->seedTestCustomers();

        // Register a mapper in the container
        $mapper = new class {
            public function map(Fixtures\Entity\Customer $customer): array
            {
                return [
                    'id' => $customer->uuid,
                    'displayName' => strtoupper($customer->name),
                ];
            }
        };
        $this->getContainer()->bindSingleton('CustomerMapper', $mapper);

        // Set up GridSchema
        $gridSchema = new GridSchema();
        $gridSchema->addSorter('uuid', new Sorter('uuid'));
        $gridSchema->setPaginator($this->paginationHelper->createPaginator());
        $this->getContainer()->bindSingleton(TestMapperGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            // View as [ClassName::class, 'method'] - class resolved from container
            #[CursorPaginate(schema: TestMapperGridSchema::class, view: ['CustomerMapper', 'map'])]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select()
                    ->orderBy('uuid', 'ASC');
            }
        };

        $request = new ServerRequest('GET', '/customers?paginate[first]=2');
        $connection = $this->executeController($controller, 'index', $request);

        // Verify nodes are mapped (they're arrays because that's what the mapper returns)
        $this->assertCount(2, $connection->nodes);
        // Note: Mappers can return arrays or objects - this one returns arrays
        if (is_array($connection->nodes[0])) {
            $this->assertArrayHasKey('displayName', $connection->nodes[0]);
            $this->assertEquals('CUSTOMER 1', $connection->nodes[0]['displayName']);
        } else {
            $this->assertObjectHasProperty('displayName', $connection->nodes[0]);
            $this->assertEquals('CUSTOMER 1', $connection->nodes[0]->displayName);
        }
    }

    #[Test]
    public function testViewMapperAsStringClassResolved(): void
    {
        $this->seedTestCustomers();

        // Register a callable mapper class in container
        $mapperClass = new class {
            public function __invoke(Fixtures\Entity\Customer $customer): array
            {
                return ['mapped' => $customer->name];
            }
        };
        $this->getContainer()->bindSingleton('CallableMapper', $mapperClass);

        // Set up GridSchema
        $gridSchema = new GridSchema();
        $gridSchema->addSorter('uuid', new Sorter('uuid'));
        $gridSchema->setPaginator($this->paginationHelper->createPaginator());
        $this->getContainer()->bindSingleton(TestCallableMapperGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            // View as string class name - resolved from container
            #[CursorPaginate(schema: TestCallableMapperGridSchema::class, view: 'CallableMapper')]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select()
                    ->orderBy('uuid', 'ASC');
            }
        };

        $request = new ServerRequest('GET', '/customers?paginate[first]=2');
        $connection = $this->executeController($controller, 'index', $request);

        // Verify nodes are mapped
        $this->assertCount(2, $connection->nodes);
        $this->assertObjectHasProperty('mapped', $connection->nodes[0]);
    }

    #[Test]
    public function testResponseCustomOptions(): void
    {
        $this->seedTestCustomers();

        // Set up GridSchema
        $gridSchema = new GridSchema();
        $gridSchema->addSorter('uuid', new Sorter('uuid'));
        $gridSchema->setPaginator($this->paginationHelper->createPaginator());
        $this->getContainer()->bindSingleton(TestCustomOptionsGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(
                schema: TestCustomOptionsGridSchema::class,
                options: ['status' => 201, 'property' => 'results', 'includeNodes' => false]
            )]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select()
                    ->orderBy('uuid', 'ASC');
            }
        };

        $request = new ServerRequest('GET', '/customers?paginate[first]=2');

        // Get raw response
        $interceptor = $this->createInterceptor($request);
        $reflection = new \ReflectionMethod($controller, 'index');
        $target = \Spiral\Interceptors\Context\Target::fromReflectionMethod($reflection, $controller);
        $context = new \Spiral\Interceptors\Context\CallContext($target, []);
        $handler = new \Spiral\Interceptors\Handler\CallableHandler();
        $result = $interceptor->intercept($context, $handler);

        // Read JSON from PSR-7 response
        $jsonBody = (string) $result->getBody();
        $json = json_decode($jsonBody, true, 512, JSON_THROW_ON_ERROR);

        // Verify custom options applied
        $this->assertEquals(201, $result->getStatusCode()); // HTTP status code
        $this->assertArrayHasKey('results', $json); // Custom property name
        $this->assertArrayNotHasKey('data', $json);

        // Verify nodes excluded
        $this->assertArrayNotHasKey('nodes', $json['results']);
        $this->assertArrayHasKey('edges', $json['results']);
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
            );
            $em->persist($customer);
        }

        $em->run();
    }
}

class TestResponseGridSchema extends GridSchema {}
class TestMapperGridSchema extends GridSchema {}
class TestCallableMapperGridSchema extends GridSchema {}
class TestCustomOptionsGridSchema extends GridSchema {}
