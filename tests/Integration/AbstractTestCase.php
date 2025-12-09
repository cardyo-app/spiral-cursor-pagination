<?php

namespace Cardyo\Tests\SpiralCursorPagination\Integration;

use Cardyo\SpiralCursorPagination\Interceptor\CursorPaginationInterceptor;
use Cardyo\SpiralCursorPagination\Response\JsonConnectionResponse;
use Cardyo\SpiralCursorPagination\Service\CursorPaginationHelper;
use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\FileConnectionConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager;
use Cycle\Database\DatabaseProviderInterface;
use Cycle\ORM\EntityManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Cycle\DataGrid\Writer\QueryWriter;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\GridFactory;
use Spiral\DataGrid\GridFactoryInterface;
use Spiral\Interceptors\Context\CallContext;
use Spiral\Interceptors\Context\Target;
use Spiral\Interceptors\Handler\CallableHandler;
use Spiral\Testing\TestCase as SpiralTestCase;
use Spiral\Cycle\Bootloader as CycleBridge;

abstract class AbstractTestCase extends SpiralTestCase
{
    protected CursorPaginationInterceptor $interceptor;
    protected CursorPaginationHelper $paginationHelper;

    public function setUp(): void
    {
        parent::setUp();

        $databaseConfig = new DatabaseConfig([
            'default' => DatabaseConfig::DEFAULT_DATABASE,
            'databases' => [
                DatabaseConfig::DEFAULT_DATABASE => ['driver' => 'sqlite'],
            ],
            'drivers' => [
                'sqlite' => new SQLiteDriverConfig(
                    connection: new MemoryConnectionConfig(),
                    queryCache: true,
                ),
//                'sqlite' => new SQLiteDriverConfig(
//                    connection: new FileConnectionConfig(
//                        database: __DIR__ . '/../../runtime/test_database.sqlite',
//                    )
//                ),
            ],
        ]);
        $this->getApp()->getContainer()->bind(DatabaseConfig::class, $databaseConfig);

        $dbal = new DatabaseManager($databaseConfig);
        $this->getApp()->getContainer()->bind(DatabaseProviderInterface::class, DatabaseManager::class);
        $this->getApp()->getContainer()->bind(DatabaseManager::class, $dbal);

        $schema = $this->defineSchema();
        $this->getContainer()->bind(SchemaInterface::class, Schema::class);
        $this->getContainer()->bind(Schema::class, $schema);

        $orm = new ORM(new Factory($dbal), $schema);
        $this->getApp()->getContainer()->bind(ORMInterface::class, ORM::class);
        $this->getApp()->getContainer()->bind(ORM::class, $orm);

        $em = new EntityManager($orm);
        $this->getApp()->getContainer()->bind(EntityManagerInterface::class, EntityManager::class);
        $this->getApp()->getContainer()->bind(EntityManager::class, $em);

        // Run migrations to create database tables
        $this->defineMigrations($dbal);

        // Initialize interceptor for production-style testing
        $this->paginationHelper = new CursorPaginationHelper(
            gridFactory: self::createGridFactory(),
        );
    }

    /**
     * Create interceptor with custom request.
     * Call this in your tests when you need to test pagination with specific parameters.
     */
    protected function createInterceptor(ServerRequestInterface $request): CursorPaginationInterceptor
    {
        return new CursorPaginationInterceptor(
            paginationHelper: $this->paginationHelper,
            request: $request,
            container: $this->getContainer(),
            response: new JsonConnectionResponse(),
        );
    }

    /**
     * Execute a controller action through the interceptor.
     * This simulates the production flow where the interceptor processes the request.
     *
     * @return mixed For tests, unwraps ConnectionResponse to Connection for backward compatibility
     */
    protected function executeController(object $controller, string $method, ServerRequestInterface $request, array $parameters = []): mixed
    {
        $interceptor = $this->createInterceptor($request);

        // Create reflection and target
        $reflection = new \ReflectionMethod($controller, $method);
        $target = Target::fromReflectionMethod($reflection, $controller);

        // Create call context
        $context = new CallContext($target, $parameters);

        // Create handler that will call the controller method
        $handler = new CallableHandler();

        // Execute through interceptor
        $result = $interceptor->intercept($context, $handler);

        // For backward compatibility in tests, unwrap PSR-7 response to Connection
        // In production, the response stays as PSR-7 ResponseInterface
        if ($result instanceof \Psr\Http\Message\ResponseInterface) {
            // Check if this is a JSON response with connection data
            $contentType = $result->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                // Read JSON from response body
                $jsonBody = (string) $result->getBody();
                $jsonData = json_decode($jsonBody, true, 512, JSON_THROW_ON_ERROR);

                // Extract the connection data for tests (JSON:API structure)
                if (isset($jsonData['data']) && is_array($jsonData['data'])) {
                    return $this->connectionFromJson($jsonData);
                }
            }
        }

        return $result;
    }

    /**
     * Reconstruct Connection from JSON:API structure (for testing).
     *
     * JSON:API Cursor Pagination Profile structure:
     * {
     *   "data": [...items...],
     *   "meta": {
     *     "page": {
     *       "from": "...",      // Start cursor
     *       "to": "...",        // End cursor
     *       "hasMore": true,
     *       "hasPrevious": true,
     *       "total": 100
     *     }
     *   }
     * }
     */
    private function connectionFromJson(array $jsonData): \Cardyo\SpiralCursorPagination\Response\Connection
    {
        $nodes = $jsonData['data'] ?? [];
        $page = $jsonData['meta']['page'] ?? [];

        // Extract cursor and pagination info per JSON:API spec
        $startCursor = $page['from'] ?? null;
        $endCursor = $page['to'] ?? null;
        $hasMore = $page['hasMore'] ?? false;
        $hasPrevious = $page['hasPrevious'] ?? false;
        $totalCount = $page['total'] ?? null;

        // Build PageInfo
        $pageInfo = new \Cardyo\SpiralCursorPagination\Response\PageInfo(
            hasNextPage: $hasMore,
            hasPreviousPage: $hasPrevious,
            startCursor: $startCursor,
            endCursor: $endCursor,
        );

        // Build edges from nodes
        // Use start cursor for first node, end cursor for last node
        $edges = [];
        foreach ($nodes as $index => $node) {
            if ($index === 0) {
                $cursor = $startCursor;
            } elseif ($index === count($nodes) - 1) {
                $cursor = $endCursor;
            } else {
                // Middle nodes - use a placeholder (tests don't usually need these)
                $cursor = $endCursor ?? $startCursor;
            }

            if ($cursor === null) {
                // Fallback if we somehow don't have any cursors
                $cursor = base64_encode('test-cursor');
            }

            $edges[] = new \Cardyo\SpiralCursorPagination\Response\Edge(
                node: (object) $node,
                cursor: $cursor
            );
        }

        // Convert nodes to objects
        $nodes = array_map(fn($node) => (object) $node, $nodes);

        return new \Cardyo\SpiralCursorPagination\Response\Connection(
            edges: $edges,
            nodes: $nodes,
            pageInfo: $pageInfo,
            totalCount: $totalCount,
        );
    }

    abstract protected function defineSchema(): SchemaInterface;

    /**
     * Define database migrations (table structure).
     *
     * This method should use the Cycle Database schema builder to create tables.
     *
     * Example:
     * ```php
     * protected function defineMigrations(DatabaseManager $dbal): void
     * {
     *     $schema = $dbal->database()->table('customers')->getSchema();
     *     $schema->uuid('uuid');
     *     $schema->string('name');
     *     $schema->index(['uuid'])->unique();
     *     $schema->save();
     * }
     * ```
     */
    abstract protected function defineMigrations(DatabaseManager $dbal): void;

    #[\Override]
    public function defineDirectories(string $root): array
    {
        return [
            'root' => $root,
            'app' => $root . '/app',
            'runtime' => __DIR__ . '/../../runtime',
            'cache' => $root . '/../../runtime',
        ];
    }

    #[\Override]
    public function defineBootloaders(): array
    {
        return [
            ...parent::defineBootloaders(),

            CycleBridge\DatabaseBootloader::class,

            CycleBridge\CycleOrmBootloader::class,
        ];
    }

    public static function createGridFactory(): GridFactoryInterface
    {
        $compiler = new Compiler();

        $compiler->addWriter(new QueryWriter());
        $compiler->addWriter(new \Cardyo\SpiralCursorPagination\Writer\Cycle\PrimaryKeyTiebreakerWriter());
        $compiler->addWriter(new \Cardyo\SpiralCursorPagination\Writer\Cycle\KeysetFilterWriter());
        $compiler->addWriter(new \Cardyo\SpiralCursorPagination\Writer\Cycle\CursorLimitWriter());
        $compiler->addWriter(new \Cardyo\SpiralCursorPagination\Writer\Cycle\SortDirectionWriter());

        return new GridFactory($compiler);
    }
}
