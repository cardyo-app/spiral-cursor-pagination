<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Feature;

use Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity\User;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\EntityFactory;
use Cardyo\Tests\SpiralCursorPagination\Support\GridFactoryHelper;
use PHPUnit\Framework\Attributes\Test;

final class BackwardPaginationTest extends FeatureTestCase
{
    #[Test]
    public function itShouldPaginateBackwardWithLastParameter(): void
    {
        $users = EntityFactory::createUsers(25);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['last' => 10]);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection($results, ['last' => 10], ['createdAt', 'id'], $paginator->cursorCoder);

        $this->assertCount(10, $connection->edges);
        $this->assertSame('User 16', $connection->edges[0]->node->getName());
        $this->assertSame('User 25', $connection->edges[9]->node->getName());
    }

    #[Test]
    public function itShouldPaginateBackwardWithBeforeCursor(): void
    {
        $users = EntityFactory::createUsers(25);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();
        $this->clear();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $connectionFactory = $this->createConnectionFactory();

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['last' => 10]);

        $firstPageResults = iterator_to_array($grid->getIterator());
        $firstConnection = $connectionFactory->createConnection($firstPageResults, ['last' => 10], ['createdAt', 'id'], $paginator->cursorCoder);

        $this->assertCount(10, $firstConnection->edges);

        $firstItemCursor = $firstConnection->edges[0]->cursor;

        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid2 = $this->createGrid($select2, $paginator, ['last' => 10, 'before' => $firstItemCursor]);

        $secondPageResults = iterator_to_array($grid2->getIterator());
        $secondConnection = $connectionFactory->createConnection($secondPageResults, ['last' => 10, 'before' => $firstItemCursor], ['createdAt', 'id'], $paginator->cursorCoder);

        $this->assertCount(10, $secondConnection->edges);
        $this->assertSame('User 6', $secondConnection->edges[0]->node->getName());
        $this->assertSame('User 15', $secondConnection->edges[9]->node->getName());
    }

    #[Test]
    public function itShouldReturnCorrectHasPreviousPageFlag(): void
    {
        $users = EntityFactory::createUsers(15);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['last' => 10]);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection($results, ['last' => 10], ['createdAt', 'id'], $paginator->cursorCoder);

        // Backward pagination returns results in reverse order, Grid fetches limit+1
        // The flags are based on the forward direction after reverse
        $this->assertTrue($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
        $this->assertCount(10, $connection->edges);
    }

    #[Test]
    public function itShouldReturnFalseHasPreviousPageWhenNoMoreResults(): void
    {
        $users = EntityFactory::createUsers(10);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['last' => 10]);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection($results, ['last' => 10], ['createdAt', 'id'], $paginator->cursorCoder);

        $this->assertFalse($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
        $this->assertCount(10, $connection->edges);
    }

    #[Test]
    public function itShouldReverseSortDirectionForBackwardPagination(): void
    {
        $users = EntityFactory::createUsers(20);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['last' => 5]);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection($results, ['last' => 5], ['createdAt', 'id'], $paginator->cursorCoder);

        $this->assertCount(5, $connection->edges);
        $this->assertSame('User 16', $connection->edges[0]->node->getName());
        $this->assertSame('User 20', $connection->edges[4]->node->getName());
    }

    #[Test]
    public function itShouldHandleEmptyResultsForBackwardPagination(): void
    {
        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['last' => 10]);

        $results = iterator_to_array($grid->getIterator());

        $this->assertCount(0, $results);

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection($results, ['last' => 10], ['createdAt', 'id'], $paginator->cursorCoder);

        $this->assertFalse($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
        $this->assertNull($connection->pageInfo->startCursor);
        $this->assertNull($connection->pageInfo->endCursor);
    }
}
