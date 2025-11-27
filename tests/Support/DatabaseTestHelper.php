<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Support;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManager;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;

trait DatabaseTestHelper
{
    private DatabaseManager $dbal;

    private ORM $orm;

    private EntityManager $entityManager;

    /**
     * Initialize in-memory SQLite database with Cycle ORM.
     */
    protected function setUpDatabase(): void
    {
        $this->dbal = new DatabaseManager(
            new DatabaseConfig([
                'default' => 'default',
                'databases' => [
                    'default' => ['driver' => 'sqlite'],
                ],
                'drivers' => [
                    'sqlite' => new SQLiteDriverConfig(
                        connection: new MemoryConnectionConfig(),
                        queryCache: true,
                    ),
                ],
            ]),
        );

        $this->orm = new ORM(new Factory($this->dbal), new Schema([]));
        $this->entityManager = new EntityManager($this->orm);
    }

    /**
     * Initialize ORM schema for entities.
     */
    protected function initializeSchema(array $schemaArray): void
    {
        $this->orm = $this->orm->with(new Schema($schemaArray));
        $this->entityManager = new EntityManager($this->orm);
    }

    /**
     * Create database tables from schema.
     */
    protected function runMigrations(): void
    {
        $schema = $this->dbal->database()->table('users')->getSchema();
        $schema->primary('id');
        $schema->string('name');
        $schema->string('email');
        $schema->datetime('created_at');
        $schema->datetime('updated_at')->nullable();
        $schema->datetime('activated_at')->nullable();
        $schema->datetime('last_activity_at')->nullable();
        $schema->integer('login_count')->defaultValue(0);
        $schema->save();
    }

    /**
     * Clean up database.
     */
    protected function tearDownDatabase(): void
    {
        try {
            if ($this->dbal->database()->hasTable('users')) {
                $schema = $this->dbal->database()->table('users')->getSchema();
                $schema->declareDropped();
                $schema->save();
            }
        } catch (\Throwable) {
            // Ignore errors during teardown
        }
    }

    /**
     * Get the database manager instance.
     */
    protected function getDbal(): DatabaseManager
    {
        return $this->dbal;
    }

    /**
     * Get the ORM instance.
     */
    protected function getOrm(): ORM
    {
        return $this->orm;
    }

    /**
     * Get the entity manager instance.
     */
    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    /**
     * Persist an entity to the database.
     */
    protected function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
    }

    /**
     * Flush all pending changes to the database.
     */
    protected function flush(): void
    {
        $this->entityManager->run();
    }

    /**
     * Clear the entity manager.
     */
    protected function clear(): void
    {
        $this->entityManager = new EntityManager($this->orm);
    }
}
