<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Feature;

use Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity\User;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\EntityFactory;
use Cardyo\Tests\SpiralCursorPagination\Support\GridFactoryHelper;
use PHPUnit\Framework\Attributes\Test;

final class ForwardPaginationTest extends FeatureTestCase
{
    #[Test]
    public function itShouldPaginateForwardWithFirstParameter(): void
    {
        $users = EntityFactory::createUsers(25);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['first' => 10]);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection(
            $results,
            ['first' => 10],
            ['createdAt', 'id'],
            $paginator->cursorCoder,
        );

        $this->assertCount(10, $connection->nodes);
        $this->assertSame('User 1', $connection->nodes[0]->getName());
        $this->assertSame('User 10', $connection->nodes[9]->getName());
    }

    #[Test]
    public function itShouldPaginateForwardWithAfterCursor(): void
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
        $grid = $this->createGrid($select, $paginator, ['first' => 10]);

        $firstPageResults = iterator_to_array($grid->getIterator());
        // Grid fetches limit+1 for hasNextPage detection
        $this->assertCount(11, $firstPageResults);

        $firstPageConnection = $connectionFactory->createConnection(
            $firstPageResults,
            ['first' => 10],
            ['createdAt', 'id'],
            $paginator->cursorCoder,
        );

        // Connection should have exactly 10 nodes (extra item trimmed)
        $this->assertCount(10, $firstPageConnection->nodes);

        $lastItemCursor = $firstPageConnection->pageInfo->endCursor;

        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid2 = $this->createGrid($select2, $paginator, ['first' => 10, 'after' => $lastItemCursor]);

        $secondPageResults = iterator_to_array($grid2->getIterator());
        // Grid fetches limit+1 for hasNextPage detection
        $this->assertCount(11, $secondPageResults);

        $secondPageConnection = $connectionFactory->createConnection(
            $secondPageResults,
            ['first' => 10, 'after' => $lastItemCursor],
            ['createdAt', 'id'],
            $paginator->cursorCoder,
        );

        // Connection should have exactly 10 nodes
        $this->assertCount(10, $secondPageConnection->nodes);
        $this->assertSame('User 11', $secondPageConnection->nodes[0]->getName());
        $this->assertSame('User 20', $secondPageConnection->nodes[9]->getName());
    }

    #[Test]
    public function itShouldReturnCorrectHasNextPageFlag(): void
    {
        $users = EntityFactory::createUsers(15);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['first' => 10]);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection($results, ['first' => 10], ['createdAt', 'id'], $paginator->cursorCoder);

        $this->assertTrue($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
        $this->assertCount(10, $connection->edges);
    }

    #[Test]
    public function itShouldReturnFalseHasNextPageWhenNoMoreResults(): void
    {
        $users = EntityFactory::createUsers(10);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['first' => 10]);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection($results, ['first' => 10], ['createdAt', 'id'], $paginator->cursorCoder);

        $this->assertFalse($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
        $this->assertCount(10, $connection->edges);
    }

    #[Test]
    public function itShouldRespectCustomFirstParameter(): void
    {
        $users = EntityFactory::createUsers(50);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(
            defaultLimit: 10,
            minLimit: 1,
            maxLimit: 50,
        );
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['first' => 25]);

        $results = iterator_to_array($grid->getIterator());

        // Grid fetches limit+1 for hasNextPage detection
        $this->assertCount(26, $results);
        $this->assertSame('User 1', $results[0]->getName());
        $this->assertSame('User 25', $results[24]->getName());
    }

    #[Test]
    public function itShouldHandleEmptyResults(): void
    {
        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['first' => 10]);

        $results = iterator_to_array($grid->getIterator());

        $this->assertCount(0, $results);

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection($results, ['first' => 10], ['createdAt', 'id'], $paginator->cursorCoder);

        $this->assertFalse($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
        $this->assertNull($connection->pageInfo->startCursor);
        $this->assertNull($connection->pageInfo->endCursor);
    }
}
