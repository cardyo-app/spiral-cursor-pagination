<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Feature;

use Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity\User;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\EntityFactory;
use Cardyo\Tests\SpiralCursorPagination\Support\GridFactoryHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests cursor pagination with various sorting field combinations.
 *
 * Validates pagination behavior with different field types (DateTime, integers),
 * NULL value handling, multi-field sorting, and different page sizes.
 */
final class VariousSortingTest extends FeatureTestCase
{
    #[Test]
    public function itShouldSortByActivationDateWithNullHandling(): void
    {
        $baseDate = new \DateTimeImmutable('2024-01-01');

        $users = [
            EntityFactory::createUser([
                'name' => 'Alice',
                'createdAt' => $baseDate,
                'activatedAt' => $baseDate->modify('+1 hour'),
            ]),
            EntityFactory::createUser([
                'name' => 'Bob',
                'createdAt' => $baseDate->modify('+1 day'),
                'activatedAt' => $baseDate->modify('+1 day +2 hours'),
            ]),
            EntityFactory::createUser([
                'name' => 'Charlie',
                'createdAt' => $baseDate->modify('+2 days'),
                'activatedAt' => null, // Never activated
            ]),
        ];

        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $paginator = $paginator->withSortFields(['activatedAt', 'id']);

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 5]);
        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection(
            $results,
            ['first' => 5],
            ['activatedAt', 'id'],
            $paginator->cursorCoder,
        );

        // NULL activatedAt comes first
        $this->assertCount(3, $connection->edges);
        $this->assertSame('Charlie', $connection->edges[0]->node->getName());
        $this->assertNull($connection->edges[0]->node->getActivatedAt());

        // Activated users follow in chronological order
        $this->assertSame('Alice', $connection->edges[1]->node->getName());
        $this->assertSame('Bob', $connection->edges[2]->node->getName());
    }

    #[Test]
    public function itShouldSortByLastActivityDate(): void
    {
        $baseDate = new \DateTimeImmutable('2024-01-01');
        $now = new \DateTimeImmutable('2024-06-15');

        $users = [
            EntityFactory::createUser([
                'name' => 'Alice',
                'createdAt' => $baseDate,
                'lastActivityAt' => $now->modify('-1 day'),
            ]),
            EntityFactory::createUser([
                'name' => 'Bob',
                'createdAt' => $baseDate->modify('+1 day'),
                'lastActivityAt' => $now->modify('-2 days'),
            ]),
            EntityFactory::createUser([
                'name' => 'Charlie',
                'createdAt' => $baseDate->modify('+2 days'),
                'lastActivityAt' => null, // Never active
            ]),
        ];

        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $paginator = $paginator->withSortFields(['lastActivityAt', 'id']);

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 3]);
        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection(
            $results,
            ['first' => 3],
            ['lastActivityAt', 'id'],
            $paginator->cursorCoder,
        );

        $this->assertCount(3, $connection->edges);

        // NULL activity appears first
        $this->assertSame('Charlie', $connection->edges[0]->node->getName());

        // Then sorted by activity date (older first)
        $this->assertSame('Bob', $connection->edges[1]->node->getName());
        $this->assertSame('Alice', $connection->edges[2]->node->getName());
    }

    #[Test]
    public function itShouldSortByThreeFields(): void
    {
        $baseDate = new \DateTimeImmutable('2024-01-01');

        $users = [
            EntityFactory::createUser([
                'name' => 'Alice',
                'createdAt' => $baseDate,
                'loginCount' => 150,
            ]),
            EntityFactory::createUser([
                'name' => 'Bob',
                'createdAt' => $baseDate->modify('+1 day'),
                'loginCount' => 150, // Same login count
            ]),
            EntityFactory::createUser([
                'name' => 'Charlie',
                'createdAt' => $baseDate->modify('+2 days'),
                'loginCount' => 100,
            ]),
        ];

        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $paginator = $paginator->withSortFields(['loginCount', 'createdAt', 'id']);

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 3]);
        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection(
            $results,
            ['first' => 3],
            ['loginCount', 'createdAt', 'id'],
            $paginator->cursorCoder,
        );

        $this->assertCount(3, $connection->edges);

        // Sorted by loginCount first
        $this->assertSame('Charlie', $connection->edges[0]->node->getName());
        $this->assertSame(100, $connection->edges[0]->node->getLoginCount());

        // Then by createdAt for same loginCount
        $this->assertSame('Alice', $connection->edges[1]->node->getName());
        $this->assertSame(150, $connection->edges[1]->node->getLoginCount());

        $this->assertSame('Bob', $connection->edges[2]->node->getName());
        $this->assertSame(150, $connection->edges[2]->node->getLoginCount());
    }

    #[Test]
    public function itShouldHandleDifferentPageSizes(): void
    {
        $users = EntityFactory::createUsers(10);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $connectionFactory = $this->createConnectionFactory();

        // Test with limit=2
        $paginator2 = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $paginator2 = $paginator2->withSortFields(['createdAt', 'id']);

        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid2 = $this->createGrid($select2, $paginator2, ['first' => 2]);
        $results2 = iterator_to_array($grid2->getIterator());

        $connection2 = $connectionFactory->createConnection(
            $results2,
            ['first' => 2],
            ['createdAt', 'id'],
            $paginator2->cursorCoder,
        );

        $this->assertCount(2, $connection2->edges);
        $this->assertTrue($connection2->pageInfo->hasNextPage);

        // Test with limit=5
        $select5 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid5 = $this->createGrid($select5, $paginator2, ['first' => 5]);
        $results5 = iterator_to_array($grid5->getIterator());

        $connection5 = $connectionFactory->createConnection(
            $results5,
            ['first' => 5],
            ['createdAt', 'id'],
            $paginator2->cursorCoder,
        );

        $this->assertCount(5, $connection5->edges);
        $this->assertTrue($connection5->pageInfo->hasNextPage);
    }

    #[Test]
    public function itShouldSupportBackwardPaginationWithCustomSorting(): void
    {
        $baseDate = new \DateTimeImmutable('2024-01-01');

        $users = [
            EntityFactory::createUser([
                'name' => 'User 1',
                'createdAt' => $baseDate,
                'lastActivityAt' => $baseDate->modify('+1 hour'),
            ]),
            EntityFactory::createUser([
                'name' => 'User 2',
                'createdAt' => $baseDate->modify('+1 day'),
                'lastActivityAt' => $baseDate->modify('+1 day +2 hours'),
            ]),
            EntityFactory::createUser([
                'name' => 'User 3',
                'createdAt' => $baseDate->modify('+2 days'),
                'lastActivityAt' => $baseDate->modify('+2 days +3 hours'),
            ]),
        ];

        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $paginator = $paginator->withSortFields(['lastActivityAt', 'id']);

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['last' => 2]);
        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection(
            $results,
            ['last' => 2],
            ['lastActivityAt', 'id'],
            $paginator->cursorCoder,
        );

        // Last 2 users by activity
        $this->assertCount(2, $connection->edges);
        $this->assertSame('User 2', $connection->edges[0]->node->getName());
        $this->assertSame('User 3', $connection->edges[1]->node->getName());

        // Has previous page (User 1), no next page
        $this->assertTrue($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
    }

    #[Test]
    public function itShouldPaginateThroughMultiplePagesWithCursor(): void
    {
        // Create 5 users with sequential created dates
        $users = EntityFactory::createUsers(5);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();
        $this->clear();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $paginator = $paginator->withSortFields(['createdAt', 'id']);

        $connectionFactory = $this->createConnectionFactory();

        // Page 1: Get first 2 users
        $select1 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid1 = $this->createGrid($select1, $paginator, ['first' => 2]);
        $results1 = iterator_to_array($grid1->getIterator());

        $connection1 = $connectionFactory->createConnection(
            $results1,
            ['first' => 2],
            ['createdAt', 'id'],
            $paginator->cursorCoder,
        );

        $this->assertCount(2, $connection1->edges);
        $this->assertTrue($connection1->pageInfo->hasNextPage);
        $this->assertSame('User 1', $connection1->edges[0]->node->getName());
        $this->assertSame('User 2', $connection1->edges[1]->node->getName());

        // Page 2: Get next 2 users using cursor
        $this->clear();
        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid2 = $this->createGrid($select2, $paginator, ['first' => 2, 'after' => $connection1->pageInfo->endCursor]);
        $results2 = iterator_to_array($grid2->getIterator());

        $connection2 = $connectionFactory->createConnection(
            $results2,
            ['first' => 2, 'after' => $connection1->pageInfo->endCursor],
            ['createdAt', 'id'],
            $paginator->cursorCoder,
        );

        $this->assertCount(2, $connection2->edges);
        $this->assertTrue($connection2->pageInfo->hasNextPage);
        $this->assertTrue($connection2->pageInfo->hasPreviousPage);
        $this->assertSame('User 3', $connection2->edges[0]->node->getName());
        $this->assertSame('User 4', $connection2->edges[1]->node->getName());

        // Page 3: Get last user
        $this->clear();
        $select3 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid3 = $this->createGrid($select3, $paginator, ['first' => 2, 'after' => $connection2->pageInfo->endCursor]);
        $results3 = iterator_to_array($grid3->getIterator());

        $connection3 = $connectionFactory->createConnection(
            $results3,
            ['first' => 2, 'after' => $connection2->pageInfo->endCursor],
            ['createdAt', 'id'],
            $paginator->cursorCoder,
        );

        $this->assertCount(1, $connection3->edges);
        $this->assertFalse($connection3->pageInfo->hasNextPage);
        $this->assertTrue($connection3->pageInfo->hasPreviousPage);
        $this->assertSame('User 5', $connection3->edges[0]->node->getName());
    }
}
