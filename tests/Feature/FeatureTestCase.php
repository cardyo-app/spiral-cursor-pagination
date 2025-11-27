<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Feature;

use Cycle\ORM\Select;
use Cardyo\SpiralCursorPagination\Specification\CursorPaginator;
use Spiral\DataGrid\GridInterface;
use Spiral\DataGrid\Input\ArrayInput;
use Cardyo\SpiralCursorPagination\Service\ConnectionFactory;
use Cardyo\SpiralCursorPagination\Service\CursorGenerator;
use Cardyo\SpiralCursorPagination\Service\PaginationMetadataCalculator;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity\User;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\EntityFactory;
use Cardyo\Tests\SpiralCursorPagination\Support\DatabaseTestHelper;
use Cardyo\Tests\SpiralCursorPagination\Support\GridFactoryHelper;
use Cycle\ORM\SchemaInterface;
use PHPUnit\Framework\TestCase;
use Spiral\DataGrid\GridFactory;

abstract class FeatureTestCase extends TestCase
{
    use DatabaseTestHelper;

    protected GridFactory $gridFactory;

    protected function setUp(): void
    {
        parent::setUp();

        EntityFactory::reset();
        $this->setUpDatabase();
        $this->initializeSchema($this->getUserSchema());
        $this->runMigrations();

        $this->gridFactory = GridFactoryHelper::createGridFactory();
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
        parent::tearDown();
    }

    /**
     * Get the Cycle ORM schema for the User entity.
     *
     * @return array<string, mixed>
     */
    private function getUserSchema(): array
    {
        return [
            User::class => [
                SchemaInterface::ROLE => 'user',
                SchemaInterface::DATABASE => 'default',
                SchemaInterface::TABLE => 'users',
                SchemaInterface::PRIMARY_KEY => 'id',
                SchemaInterface::COLUMNS => [
                    'id' => 'id',
                    'name' => 'name',
                    'email' => 'email',
                    'createdAt' => 'created_at',
                    'updatedAt' => 'updated_at',
                    'activatedAt' => 'activated_at',
                    'lastActivityAt' => 'last_activity_at',
                    'loginCount' => 'login_count',
                ],
                SchemaInterface::TYPECAST => [
                    'id' => 'int',
                    'createdAt' => 'datetime',
                    'updatedAt' => 'datetime',
                    'activatedAt' => 'datetime',
                    'lastActivityAt' => 'datetime',
                    'loginCount' => 'int',
                ],
                SchemaInterface::RELATIONS => [],
            ],
        ];
    }

    /**
     * Extract SQL query from Cycle Select for debugging.
     */
    protected function getSql(Select $select): string
    {
        return $select->buildQuery()->sqlStatement();
    }

    /**
     * Get SQL parameters from Cycle Select for debugging.
     *
     * @return array<mixed>
     */
    protected function getSqlParameters(Select $select): array
    {
        return $select->buildQuery()->getParameters();
    }

    /**
     * Create a Grid with a CursorPaginator.
     *
     * @param array<string, mixed>|null $paginatorValue
     */
    protected function createGrid(
        Select $select,
        CursorPaginator $paginator,
        ?array $paginatorValue = null,
    ): GridInterface {
        $schema = GridFactoryHelper::createGridSchema($paginator);

        if ($paginatorValue !== null) {
            $input = new ArrayInput(['paginate' => $paginatorValue]);
            return $this->gridFactory->withInput($input)->create($select, $schema);
        }

        return $this->gridFactory->create($select, $schema);
    }

    /**
     * Create a ConnectionFactory with proper dependencies.
     */
    protected function createConnectionFactory(): ConnectionFactory
    {
        $cursorGenerator = new CursorGenerator();
        $metadataCalculator = new PaginationMetadataCalculator();

        return new ConnectionFactory(
            $cursorGenerator,
            $metadataCalculator,
        );
    }
}
