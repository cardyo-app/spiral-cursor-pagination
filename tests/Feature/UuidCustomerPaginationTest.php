<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Feature;

use Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity\Customer;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\EntityFactory;
use Cardyo\Tests\SpiralCursorPagination\Support\GridFactoryHelper;
use Cycle\ORM\SchemaInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests cursor pagination with UUID-based sorting (non-sequential identifiers).
 *
 * Demonstrates that cursor pagination works correctly when sorting by
 * non-incrementing fields like UUIDs, validating cursor stability and ordering.
 */
final class UuidCustomerPaginationTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize Customer schema
        $this->initializeSchema([
            Customer::class => [
                SchemaInterface::ROLE => 'customer',
                SchemaInterface::DATABASE => 'default',
                SchemaInterface::TABLE => 'customers',
                SchemaInterface::PRIMARY_KEY => 'id',
                SchemaInterface::COLUMNS => [
                    'id' => 'id',
                    'uuid' => 'uuid',
                    'name' => 'name',
                    'email' => 'email',
                    'createdAt' => 'created_at',
                    'updatedAt' => 'updated_at',
                    'lastActivityAt' => 'last_activity_at',
                ],
                SchemaInterface::TYPECAST => [
                    'id' => 'int',
                    'createdAt' => 'datetime',
                    'updatedAt' => 'datetime',
                    'lastActivityAt' => 'datetime',
                ],
                SchemaInterface::RELATIONS => [],
            ],
        ]);

        // Create customers table
        $schema = $this->getDbal()->database()->table('customers')->getSchema();
        $schema->primary('id');
        $schema->string('uuid', 36);
        $schema->string('name');
        $schema->string('email');
        $schema->datetime('created_at');
        $schema->datetime('updated_at')->nullable();
        $schema->datetime('last_activity_at')->nullable();
        $schema->save();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->getDbal()->database()->hasTable('customers')) {
                $schema = $this->getDbal()->database()->table('customers')->getSchema();
                $schema->declareDropped();
                $schema->save();
            }
        } catch (\Throwable) {
            // Ignore errors during teardown
        }

        parent::tearDown();
    }

    #[Test]
    public function itShouldPaginateByLastActivityAndUuid(): void
    {
        $baseDate = new \DateTimeImmutable('2024-01-01');
        $now = new \DateTimeImmutable('2024-06-15');

        $customers = [
            EntityFactory::createCustomer([
                'uuid' => '550e8400-e29b-41d4-a716-446655440001',
                'name' => 'Alice',
                'createdAt' => $baseDate,
                'updatedAt' => $now->modify('-1 day'),
                'lastActivityAt' => $now->modify('-1 hour'),
            ]),
            EntityFactory::createCustomer([
                'uuid' => '550e8400-e29b-41d4-a716-446655440002',
                'name' => 'Bob',
                'createdAt' => $baseDate->modify('+1 day'),
                'updatedAt' => $now->modify('-2 days'),
                'lastActivityAt' => $now->modify('-2 hours'),
            ]),
            EntityFactory::createCustomer([
                'uuid' => '550e8400-e29b-41d4-a716-446655440003',
                'name' => 'Charlie',
                'createdAt' => $baseDate->modify('+2 days'),
                'lastActivityAt' => null, // Never active
            ]),
        ];

        foreach ($customers as $customer) {
            $this->persist($customer);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $paginator = $paginator->withSortFields(['lastActivityAt', 'uuid']);

        $select = GridFactoryHelper::createSelect($this->getOrm(), Customer::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 2]);
        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection(
            $results,
            ['first' => 2],
            ['lastActivityAt', 'uuid'],
            $paginator->cursorCoder,
        );

        // NULL lastActivityAt appears first, then sorted by UUID
        $this->assertCount(2, $connection->edges);
        $this->assertSame('Charlie', $connection->edges[0]->node->getName());
        $this->assertNull($connection->edges[0]->node->getLastActivityAt());

        // Second customer has activity
        $this->assertNotNull($connection->edges[1]->node->getLastActivityAt());

        // Has next page
        $this->assertTrue($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
    }

    #[Test]
    public function itShouldPaginateByCreatedAtAndUuid(): void
    {
        $customers = EntityFactory::createCustomers(5);
        foreach ($customers as $customer) {
            $this->persist($customer);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $paginator = $paginator->withSortFields(['createdAt', 'uuid']);

        $select = GridFactoryHelper::createSelect($this->getOrm(), Customer::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 5]);
        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection(
            $results,
            ['first' => 5],
            ['createdAt', 'uuid'],
            $paginator->cursorCoder,
        );

        $this->assertCount(5, $connection->edges);
        $this->assertSame('Customer 1', $connection->edges[0]->node->getName());
        $this->assertSame('Customer 5', $connection->edges[4]->node->getName());

        // All customers fetched, no more pages
        $this->assertFalse($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
    }

    #[Test]
    public function itShouldHandleNullUpdatedAtWithUuid(): void
    {
        $baseDate = new \DateTimeImmutable('2024-01-01');
        $now = new \DateTimeImmutable('2024-06-15');

        $customers = [
            // Never updated
            EntityFactory::createCustomer([
                'uuid' => '550e8400-e29b-41d4-a716-446655440001',
                'name' => 'Customer 1',
                'createdAt' => $baseDate,
                'updatedAt' => null,
            ]),
            EntityFactory::createCustomer([
                'uuid' => '550e8400-e29b-41d4-a716-446655440002',
                'name' => 'Customer 2',
                'createdAt' => $baseDate->modify('+1 day'),
                'updatedAt' => null,
            ]),
            // Recently updated
            EntityFactory::createCustomer([
                'uuid' => '550e8400-e29b-41d4-a716-446655440003',
                'name' => 'Customer 3',
                'createdAt' => $baseDate->modify('+2 days'),
                'updatedAt' => $now,
            ]),
        ];

        foreach ($customers as $customer) {
            $this->persist($customer);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $paginator = $paginator->withSortFields(['updatedAt', 'uuid']);

        $select = GridFactoryHelper::createSelect($this->getOrm(), Customer::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 3]);
        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection(
            $results,
            ['first' => 3],
            ['updatedAt', 'uuid'],
            $paginator->cursorCoder,
        );

        $this->assertCount(3, $connection->edges);

        // NULL values come first
        $this->assertNull($connection->edges[0]->node->getUpdatedAt());
        $this->assertNull($connection->edges[1]->node->getUpdatedAt());

        // Non-NULL value comes last
        $this->assertNotNull($connection->edges[2]->node->getUpdatedAt());
    }

    #[Test]
    public function itShouldSupportBackwardPaginationWithUuid(): void
    {
        $customers = EntityFactory::createCustomers(10);
        foreach ($customers as $customer) {
            $this->persist($customer);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $paginator = $paginator->withSortFields(['createdAt', 'uuid']);

        $select = GridFactoryHelper::createSelect($this->getOrm(), Customer::class);
        $grid = $this->createGrid($select, $paginator, ['last' => 3]);
        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection(
            $results,
            ['last' => 3],
            ['createdAt', 'uuid'],
            $paginator->cursorCoder,
        );

        // Last 3 customers
        $this->assertCount(3, $connection->edges);
        $this->assertSame('Customer 8', $connection->edges[0]->node->getName());
        $this->assertSame('Customer 10', $connection->edges[2]->node->getName());

        // Has previous page (customers 1-7), no next page
        $this->assertTrue($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
    }

    #[Test]
    public function itShouldMaintainCursorStabilityWithUuid(): void
    {
        // Create customers with same lastActivityAt to test UUID as tiebreaker
        $baseDate = new \DateTimeImmutable('2024-01-01');
        $sameActivity = new \DateTimeImmutable('2024-06-15 12:00:00');

        $customers = [
            EntityFactory::createCustomer([
                'uuid' => '550e8400-e29b-41d4-a716-446655440001',
                'name' => 'Customer A',
                'createdAt' => $baseDate,
                'lastActivityAt' => $sameActivity,
            ]),
            EntityFactory::createCustomer([
                'uuid' => '550e8400-e29b-41d4-a716-446655440002',
                'name' => 'Customer B',
                'createdAt' => $baseDate->modify('+1 day'),
                'lastActivityAt' => $sameActivity,
            ]),
            EntityFactory::createCustomer([
                'uuid' => '550e8400-e29b-41d4-a716-446655440003',
                'name' => 'Customer C',
                'createdAt' => $baseDate->modify('+2 days'),
                'lastActivityAt' => $sameActivity,
            ]),
        ];

        foreach ($customers as $customer) {
            $this->persist($customer);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $paginator = $paginator->withSortFields(['lastActivityAt', 'uuid']);

        $connectionFactory = $this->createConnectionFactory();

        // Page through all customers with limit=2
        $allCustomers = [];
        $currentCursor = null;

        while (true) {
            $select = GridFactoryHelper::createSelect($this->getOrm(), Customer::class);
            $paginationParams = ['first' => 2];
            if ($currentCursor) {
                $paginationParams['after'] = $currentCursor;
            }

            $grid = $this->createGrid($select, $paginator, $paginationParams);
            $results = iterator_to_array($grid->getIterator());

            if ($results === []) {
                break;
            }

            $connection = $connectionFactory->createConnection(
                $results,
                $paginationParams,
                ['lastActivityAt', 'uuid'],
                $paginator->cursorCoder,
            );

            foreach ($connection->nodes as $customer) {
                $allCustomers[] = $customer;
            }

            if (!$connection->pageInfo->hasNextPage) {
                break;
            }

            $currentCursor = $connection->pageInfo->endCursor;
        }

        // Verify all customers retrieved exactly once
        $this->assertCount(3, $allCustomers);

        // Verify no duplicates by UUID
        $uuids = array_map(fn($c) => $c->getUuid(), $allCustomers);
        $uniqueUuids = array_unique($uuids);
        $this->assertCount(3, $uniqueUuids);

        // Verify correct ordering by UUID (since lastActivityAt is same for all)
        $this->assertSame('550e8400-e29b-41d4-a716-446655440001', $allCustomers[0]->getUuid());
        $this->assertSame('550e8400-e29b-41d4-a716-446655440002', $allCustomers[1]->getUuid());
        $this->assertSame('550e8400-e29b-41d4-a716-446655440003', $allCustomers[2]->getUuid());
    }
}
