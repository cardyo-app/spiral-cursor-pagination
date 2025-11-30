<?php

use Cardyo\SpiralCursorPagination\CursorEncoder\CursorCoder;
use Cardyo\SpiralCursorPagination\Specification\Pagination\CursorPaginator;
use Cardyo\Tests\SpiralCursorPagination\Integration\AbstractTestCase;
use Cardyo\Tests\SpiralCursorPagination\Fixtures;
use Cycle\Database\DatabaseProviderInterface;
use Cycle\Database\Schema\AbstractTable;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use Cycle\ORM\Select;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Value\IntValue;
use Spiral\DataGrid\Specification\Value\RangeValue;
use Spiral\DataGrid\Specification\Value\RangeValue\Boundary;
use PHPUnit\Framework\Attributes\DataProvider;

class WithoutIncrementalIdentifierTest extends AbstractTestCase
{
    #[Override]
    public function setUp(): void
    {
        parent::setUp();

        $this->defineSchema();

        $this->setUpDatabase();
    }

    #[Override]
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
        /** @var AbstractTable $schema */
        $schema = $this->getContainer()->get(DatabaseProviderInterface::class)->database()->table('customers')->getSchema();

        $schema->uuid('uuid');
        $schema->string('name');
        $schema->string('email');
        $schema->datetime('created_at');
        $schema->datetime('updated_at')->nullable();
        $schema->datetime('last_activity_at')->nullable();
        $schema->integer('login_count')->default(0);

        $schema->index(['uuid'])->unique();

        $schema->save();
    }

    #[DataProvider('paginationScenarioProvider')]
    public function testDefaultPagination(array $customers, CursorPaginator $paginator, int $expectedCount): void
    {
        $this->seedCustomers($customers);

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);

        $gridSchema = new GridSchema();
        $gridSchema->setPaginator($paginator);

        $grid = self::createGridFactory()
            ->create($select, $gridSchema);

        $results = iterator_to_array($grid->getIterator());

        $this->assertCount($expectedCount, $results);
    }

    public static function paginationScenarioProvider(): iterable
    {
        yield 'uuid primary key with default limit' => [
            'customers' => self::buildUuidCustomers(),
            'paginator' => self::createDefaultPaginator(limit: 5),
            'expectedCount' => 5,
        ];
    }

    /**
     * Creates predictable UUID customers so future test cases (incremental IDs, UUIDv7, etc.)
     * can reuse the same seeding logic with different factories.
     */
    private static function buildUuidCustomers(int $count = 6): array
    {
        $customers = [];

        for ($index = 1; $index <= $count; ++$index) {
            $customers[] = new Fixtures\Entity\Customer(
                uuid: sprintf('00000000-0000-0000-0000-%012d', $index),
                name: sprintf('Customer %d', $index),
                email: sprintf('customer.%d@example.com', $index),
                createdAt: new \DateTimeImmutable(sprintf('2024-01-15T10:30:00Z +%d days', $index - 1)),
            );
        }

        return $customers;
    }

    private static function createDefaultPaginator(int $limit): CursorPaginator
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

    private function seedCustomers(array $customers): void
    {
        foreach ($customers as $customer) {
            $this->persist($customer);
        }

        $this->flush();
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
