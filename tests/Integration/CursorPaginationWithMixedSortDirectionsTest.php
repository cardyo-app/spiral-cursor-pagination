<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Integration;

use Cardyo\SpiralCursorPagination\Attribute\CursorPaginate;
use Cardyo\SpiralCursorPagination\Response\Connection;
use Cardyo\Tests\SpiralCursorPagination\Fixtures;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Sorter\Sorter;

/**
 * Test cursor pagination with mixed sort directions (ASC and DESC on different columns).
 *
 * This test verifies the fix for the bug where all fields used the same comparison operator.
 * Each field should respect its own sort direction when building keyset filter conditions.
 *
 * Example: ORDER BY created_at DESC, id ASC
 * - Forward pagination: created_at < cursor_date OR (created_at = cursor_date AND id > cursor_id)
 * - Each field uses its appropriate operator based on its direction
 */
class CursorPaginationWithMixedSortDirectionsTest extends AbstractTestCase
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

    #[\Override]
    protected function defineMigrations(DatabaseManager $dbal): void
    {
        $schema = $dbal->database()->table('customers')->getSchema();

        $schema->uuid('uuid');
        $schema->string('name');
        $schema->string('email');
        $schema->datetime('created_at');
        $schema->datetime('updated_at')->nullable();
        $schema->datetime('last_activity_at')->nullable();
        $schema->integer('login_count')->default(0);

        $schema->index(['uuid'])->unique();
        $schema->index(['created_at', 'login_count']); // Composite index for mixed sorting

        $schema->save();
    }

    /**
     * Test DESC + ASC mixed sorting (common pattern: newest first, then by ID)
     *
     * ORDER BY created_at DESC, login_count ASC
     * - created_at DESC means newer dates first (2024-01-05 before 2024-01-01)
     * - login_count ASC means within same date, lower counts first
     */
    #[Test]
    public function testDescAscMixedSorting(): void
    {
        $this->seedCustomersWithSameDate();

        // Create schema with DESC + ASC sorting
        $gridSchema = new GridSchema();
        $gridSchema->addSorter('created', new Sorter('created_at'));
        $gridSchema->addSorter('logins', new Sorter('login_count'));
        $gridSchema->setPaginator($this->paginationHelper->createPaginator(2));

        $this->getContainer()->bindSingleton(MixedSortGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: MixedSortGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select();
            }
        };

        // First page: ORDER BY created_at DESC, login_count ASC LIMIT 2
        $request = new ServerRequest('GET', '/customers?sort[created]=desc&sort[logins]=asc&sort[uuid]=asc&paginate[first]=2');
        $page1 = $this->executeController($controller, 'index', $request);

        // Should get 2024-01-03 customers first (newest), ordered by login_count ASC
        $this->assertInstanceOf(Connection::class, $page1);
        $this->assertCount(2, $page1->nodes);
        $this->assertEquals('Customer 3A', $page1->nodes[0]->name); // 2024-01-03, login 10
        $this->assertEquals('Customer 3B', $page1->nodes[1]->name); // 2024-01-03, login 20
        $this->assertTrue($page1->pageInfo->hasNextPage);

        // Second page: should continue with 2024-01-03 (login 30) then move to 2024-01-02
        $cursor = $page1->pageInfo->endCursor;
        $request2 = new ServerRequest('GET', "/customers?sort[created]=desc&sort[logins]=asc&sort[uuid]=asc&paginate[first]=2&paginate[after]={$cursor}");
        $page2 = $this->executeController($controller, 'index', $request2);

        $this->assertInstanceOf(Connection::class, $page2);
        $this->assertCount(2, $page2->nodes);
        $this->assertEquals('Customer 3C', $page2->nodes[0]->name); // 2024-01-03, login 30
        $this->assertEquals('Customer 2A', $page2->nodes[1]->name); // 2024-01-02, login 10
        $this->assertTrue($page2->pageInfo->hasNextPage);

        // Verify cursor navigation maintains sort order
        $this->assertTrue($page2->pageInfo->hasPreviousPage);
    }

    /**
     * Test ASC + DESC mixed sorting
     *
     * ORDER BY login_count ASC, created_at DESC
     * - login_count ASC means lower counts first
     * - created_at DESC means within same count, newer dates first
     */
    #[Test]
    public function testAscDescMixedSorting(): void
    {
        $this->seedCustomersWithSameLoginCount();

        $gridSchema = new GridSchema();
        $gridSchema->addSorter('logins', new Sorter('login_count'));
        $gridSchema->addSorter('created', new Sorter('created_at'));
        $gridSchema->setPaginator($this->paginationHelper->createPaginator(2));

        $this->getContainer()->bindSingleton(AscDescSortGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: AscDescSortGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select();
            }
        };

        // First page: ORDER BY login_count ASC, created_at DESC LIMIT 2
        $request = new ServerRequest('GET', '/customers?sort[logins]=asc&sort[created]=desc&sort[uuid]=asc&paginate[first]=2');
        $page1 = $this->executeController($controller, 'index', $request);

        // Should get login_count=10 first, ordered by created_at DESC (newest first)
        $this->assertInstanceOf(Connection::class, $page1);
        $this->assertCount(2, $page1->nodes);
        $this->assertEquals('Customer C', $page1->nodes[0]->name); // login 10, 2024-01-03
        $this->assertEquals('Customer B', $page1->nodes[1]->name); // login 10, 2024-01-02
        $this->assertTrue($page1->pageInfo->hasNextPage);

        // Second page
        $cursor = $page1->pageInfo->endCursor;
        $request2 = new ServerRequest('GET', "/customers?sort[logins]=asc&sort[created]=desc&sort[uuid]=asc&paginate[first]=2&paginate[after]={$cursor}");
        $page2 = $this->executeController($controller, 'index', $request2);

        $this->assertInstanceOf(Connection::class, $page2);
        $this->assertCount(2, $page2->nodes);
        $this->assertEquals('Customer A', $page2->nodes[0]->name); // login 10, 2024-01-01
        $this->assertEquals('Customer F', $page2->nodes[1]->name); // login 20, 2024-01-03
    }

    /**
     * Test backward pagination with mixed sort directions
     */
    #[Test]
    public function testBackwardPaginationWithMixedSorting(): void
    {
        $this->seedCustomersWithSameDate();

        $gridSchema = new GridSchema();
        $gridSchema->addSorter('created', new Sorter('created_at'));
        $gridSchema->addSorter('logins', new Sorter('login_count'));
        $gridSchema->setPaginator($this->paginationHelper->createPaginator(2));

        $this->getContainer()->bindSingleton(MixedSortGridSchema::class, fn() => $gridSchema);

        $controller = new class($this->getContainer()->get(ORM::class)) {
            public function __construct(private readonly ORM $orm) {}

            #[CursorPaginate(schema: MixedSortGridSchema::class)]
            public function index(): \Cycle\ORM\Select
            {
                return $this->orm
                    ->getRepository(Fixtures\Entity\Customer::class)
                    ->select();
            }
        };

        // Get to middle page
        $request = new ServerRequest('GET', '/customers?sort[created]=desc&sort[logins]=asc&sort[uuid]=asc&paginate[first]=3');
        $middlePage = $this->executeController($controller, 'index', $request);
        $startCursor = $middlePage->pageInfo->startCursor;

        // Go backward
        $request2 = new ServerRequest('GET', "/customers?sort[created]=desc&sort[logins]=asc&sort[uuid]=asc&paginate[last]=2&paginate[before]={$startCursor}");
        $backPage = $this->executeController($controller, 'index', $request2);

        // When going backward with DESC+ASC, should still maintain proper order
        $this->assertInstanceOf(Connection::class, $backPage);
        $this->assertFalse($backPage->pageInfo->hasPreviousPage); // We're at the start
    }

    /**
     * Seed customers with same created_at but different login_counts
     * Tests that second field (login_count ASC) works correctly
     */
    private function seedCustomersWithSameDate(): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);

        $customers = [
            // 2024-01-03 (newest date)
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000001',
                name: 'Customer 3A',
                email: 'customer3a@example.com',
                createdAt: new DateTimeImmutable('2024-01-03T10:00:00Z'),
                loginCount: 10,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000002',
                name: 'Customer 3B',
                email: 'customer3b@example.com',
                createdAt: new DateTimeImmutable('2024-01-03T10:00:00Z'),
                loginCount: 20,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000003',
                name: 'Customer 3C',
                email: 'customer3c@example.com',
                createdAt: new DateTimeImmutable('2024-01-03T10:00:00Z'),
                loginCount: 30,
            ),
            // 2024-01-02
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000004',
                name: 'Customer 2A',
                email: 'customer2a@example.com',
                createdAt: new DateTimeImmutable('2024-01-02T10:00:00Z'),
                loginCount: 10,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000005',
                name: 'Customer 2B',
                email: 'customer2b@example.com',
                createdAt: new DateTimeImmutable('2024-01-02T10:00:00Z'),
                loginCount: 20,
            ),
            // 2024-01-01 (oldest date)
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000006',
                name: 'Customer 1A',
                email: 'customer1a@example.com',
                createdAt: new DateTimeImmutable('2024-01-01T10:00:00Z'),
                loginCount: 10,
            ),
        ];

        foreach ($customers as $customer) {
            $em->persist($customer);
        }

        $em->run();
    }

    /**
     * Seed customers with same login_count but different created_at
     * Tests that second field (created_at DESC) works correctly
     */
    private function seedCustomersWithSameLoginCount(): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);

        $customers = [
            // login_count = 10
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000001',
                name: 'Customer A',
                email: 'customerA@example.com',
                createdAt: new DateTimeImmutable('2024-01-01T10:00:00Z'),
                loginCount: 10,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000002',
                name: 'Customer B',
                email: 'customerB@example.com',
                createdAt: new DateTimeImmutable('2024-01-02T10:00:00Z'),
                loginCount: 10,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000003',
                name: 'Customer C',
                email: 'customerC@example.com',
                createdAt: new DateTimeImmutable('2024-01-03T10:00:00Z'),
                loginCount: 10,
            ),
            // login_count = 20
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000004',
                name: 'Customer D',
                email: 'customerD@example.com',
                createdAt: new DateTimeImmutable('2024-01-01T10:00:00Z'),
                loginCount: 20,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000005',
                name: 'Customer E',
                email: 'customerE@example.com',
                createdAt: new DateTimeImmutable('2024-01-02T10:00:00Z'),
                loginCount: 20,
            ),
            new Fixtures\Entity\Customer(
                uuid: '00000000-0000-0000-0000-000000000006',
                name: 'Customer F',
                email: 'customerF@example.com',
                createdAt: new DateTimeImmutable('2024-01-03T10:00:00Z'),
                loginCount: 20,
            ),
        ];

        foreach ($customers as $customer) {
            $em->persist($customer);
        }

        $em->run();
    }
}

// Dummy classes for testing mixed sort GridSchemas
class MixedSortGridSchema extends GridSchema {}
class AscDescSortGridSchema extends GridSchema {}
