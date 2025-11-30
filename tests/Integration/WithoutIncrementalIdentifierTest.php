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

    public function testDefaultPagination(): void
    {
        $customers = [
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000001',
                name: 'Customer 1',
                email: 'customer.1@example.com',
                createdAt: new DateTimeImmutable('2024-01-15T10:30:00Z'),
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000002',
                name: 'Customer 2',
                email: 'customer.2@example.com',
                createdAt: new DateTimeImmutable('2024-01-16T11:00:00Z'),
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000003',
                name: 'Customer 3',
                email: 'customer.3@example.com',
                createdAt: new DateTimeImmutable('2024-01-17T09:15:00Z'),
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000004',
                name: 'Customer 4',
                email: 'customer.4@example.com',
                createdAt: new DateTimeImmutable('2024-01-18T14:45:00Z'),
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000005',
                name: 'Customer 5',
                email: 'customer.5@example.com',
                createdAt: new DateTimeImmutable('2024-01-19T08:20:00Z'),
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000006',
                name: 'Customer 6',
                email: 'customer.6@example.com',
                createdAt: new DateTimeImmutable('2024-01-20T12:10:00Z'),
            ),
        ];

        foreach ($customers as $customer) {
            $this->persist($customer);
        }

        $this->flush();

        $paginator = new CursorPaginator(
            defaultLimit: 5,
            limitValue: new RangeValue(
                new IntValue(),
                Boundary::including(1),
                Boundary::including(100),
            ),
            cursorCoder: new CursorCoder(),
        );

        $select = new Select($this->getContainer()->get(ORM::class), Fixtures\Entity\Customer::class);

        $gridSchema = new GridSchema();
        $gridSchema->setPaginator($paginator);

        $grid = self::createGridFactory()->create($select, $gridSchema);

        $results = iterator_to_array($grid->getIterator());

        $this->assertCount(5, $results);
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
