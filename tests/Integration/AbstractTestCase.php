<?php

namespace Cardyo\Tests\SpiralCursorPagination\Integration;

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
use Spiral\Cycle\DataGrid\Writer\QueryWriter;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\GridFactory;
use Spiral\DataGrid\GridFactoryInterface;
use Spiral\Testing\TestCase as SpiralTestCase;
use Spiral\Cycle\Bootloader as CycleBridge;

abstract class AbstractTestCase extends SpiralTestCase
{
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
    }

    abstract protected function defineSchema(): SchemaInterface;

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
        // todo: Register cursor pagination writers

        return new GridFactory($compiler);
    }
}
