<?php

namespace Cardyo\Tests\SpiralCursorPagination\Integration;

use Cardyo\SpiralCursorPagination\Interceptor\CursorPaginationInterceptor;
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
use Spiral\Core\Core;
use Spiral\Cycle\DataGrid\Writer\QueryWriter;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\GridFactory;
use Spiral\DataGrid\GridFactoryInterface;
use Spiral\Testing\TestCase as SpiralTestCase;
use Spiral\Cycle\Bootloader as CycleBridge;

abstract class AbstractTestCase extends SpiralTestCase
{
    protected CursorPaginationInterceptor $interceptor;
    protected Core $core;
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

        $this->core = new Core($this->getContainer());
    }

    /**
     * Create interceptor with custom request.
     * Call this in your tests when you need to test pagination with specific parameters.
     */
    protected function createInterceptor(ServerRequestInterface $request): CursorPaginationInterceptor
    {
        return new CursorPaginationInterceptor(
            paginationHelper: $this->paginationHelper,
            orm: $this->getContainer()->get(ORM::class),
            request: $request,
            container: $this->getContainer(),
        );
    }

    /**
     * Execute a controller action through the interceptor.
     * This simulates the production flow where the interceptor processes the request.
     */
    protected function executeController(object $controller, string $method, ServerRequestInterface $request, array $parameters = []): mixed
    {
        $interceptor = $this->createInterceptor($request);
        return $interceptor->process($controller::class, $method, $parameters, $this->core);
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
        $compiler->addWriter(new \Cardyo\SpiralCursorPagination\Writer\Cycle\KeysetFilterWriter());
        $compiler->addWriter(new \Cardyo\SpiralCursorPagination\Writer\Cycle\CursorLimitWriter());
        $compiler->addWriter(new \Cardyo\SpiralCursorPagination\Writer\Cycle\SortDirectionWriter());

        return new GridFactory($compiler);
    }
}
