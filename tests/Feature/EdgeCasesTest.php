<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Feature;

use Cardyo\SpiralCursorPagination\Cursor\CursorData;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity\User;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\EntityFactory;
use Cardyo\Tests\SpiralCursorPagination\Support\GridFactoryHelper;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;

final class EdgeCasesTest extends FeatureTestCase
{
    #[Test]
    public function itShouldHandleSingleResult(): void
    {
        $user = EntityFactory::createUser();
        $this->persist($user);
        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['first' => 10]);

        $results = iterator_to_array($grid->getIterator());

        $this->assertCount(1, $results);

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection($results, ['first' => 10], ['createdAt', 'id'], $paginator->cursorCoder);

        $this->assertFalse($connection->pageInfo->hasNextPage);
        $this->assertFalse($connection->pageInfo->hasPreviousPage);
        $this->assertNotNull($connection->pageInfo->startCursor);
        $this->assertNotNull($connection->pageInfo->endCursor);
        $this->assertSame($connection->pageInfo->startCursor, $connection->pageInfo->endCursor);
    }

    #[Test]
    public function itShouldHandleIdenticalTimestamps(): void
    {
        $baseDate = new DateTimeImmutable('2024-01-01 00:00:00');

        $users = [
            EntityFactory::createUser(['createdAt' => $baseDate]),
            EntityFactory::createUser(['createdAt' => $baseDate]),
            EntityFactory::createUser(['createdAt' => $baseDate]),
        ];

        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();
        $this->clear();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $connectionFactory = $this->createConnectionFactory();

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 2]);
        $firstPageResults = iterator_to_array($grid->getIterator());

        // Grid fetches limit+1 for hasNextPage detection
        $this->assertCount(3, $firstPageResults);

        $firstPageConnection = $connectionFactory->createConnection(
            $firstPageResults,
            ['first' => 2],
            ['createdAt', 'id'],
            $paginator->cursorCoder,
        );

        $cursor = $firstPageConnection->pageInfo->endCursor;

        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid2 = $this->createGrid($select2, $paginator, ['first' => 10, 'after' => $cursor]);
        $secondPageResults = iterator_to_array($grid2->getIterator());

        $this->assertCount(1, $secondPageResults);
        $this->assertNotEquals($firstPageConnection->nodes[1]->getId(), $secondPageResults[0]->getId());
    }

    #[Test]
    public function itShouldHandleBoundaryLimits(): void
    {
        $users = EntityFactory::createUsers(100);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(
            defaultLimit: 10,
            minLimit: 1,
            maxLimit: 50,
        );

        $selectMin = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $gridMin = $this->createGrid($selectMin, $paginator, ['first' => 1]);
        $resultsMin = iterator_to_array($gridMin->getIterator());

        // Grid fetches limit+1 for hasNextPage detection
        $this->assertCount(2, $resultsMin);

        $selectMax = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $gridMax = $this->createGrid($selectMax, $paginator, ['first' => 50]);
        $resultsMax = iterator_to_array($gridMax->getIterator());

        // Grid fetches limit+1 for hasNextPage detection
        $this->assertCount(51, $resultsMax);
    }

    #[Test]
    public function itShouldHandleOutOfBoundsLimits(): void
    {
        $users = EntityFactory::createUsers(20);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(
            defaultLimit: 10,
            minLimit: 1,
            maxLimit: 50,
        );

        $selectTooLow = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $gridTooLow = $this->createGrid($selectTooLow, $paginator, ['first' => 0]);
        $resultsTooLow = iterator_to_array($gridTooLow->getIterator());

        // Invalid value falls back to defaultLimit (10), Grid fetches limit+1
        $this->assertCount(11, $resultsTooLow);

        $selectTooHigh = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $gridTooHigh = $this->createGrid($selectTooHigh, $paginator, ['first' => 100]);
        $resultsTooHigh = iterator_to_array($gridTooHigh->getIterator());

        // Invalid value falls back to defaultLimit (10), Grid fetches limit+1
        $this->assertCount(11, $resultsTooHigh);
    }

    #[Test]
    public function itShouldHandleMalformedCursorPrefix(): void
    {
        $users = EntityFactory::createUsers(10);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 5, 'after' => 'cursor:v1:abc123']);

        $results = iterator_to_array($grid->getIterator());

        // Malformed cursor is ignored, Grid fetches limit+1 for hasNextPage detection
        $this->assertCount(6, $results);
        $this->assertSame('User 1', $results[0]->getName());
    }

    #[Test]
    public function itShouldHandleNonExistentCursorData(): void
    {
        $users = EntityFactory::createUsers(10);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);

        $invalidCursorData = new CursorData(['nonexistent_field' => 'value']);
        $invalidCursor = $paginator->cursorCoder->encodeCursor($invalidCursorData);

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 5, 'after' => $invalidCursor]);

        $results = iterator_to_array($grid->getIterator());

        // Invalid cursor fields are ignored, starts from beginning
        // Grid fetches limit+1 for hasNextPage detection
        $this->assertCount(6, $results);
    }

    #[Test]
    public function itShouldHandleExactPageSizeMatch(): void
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
        $this->assertCount(10, $connection->edges);
    }

    #[Test]
    public function itShouldHandleLastPageWithFewerResults(): void
    {
        $users = EntityFactory::createUsers(23);
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

        $firstPageConnection = $connectionFactory->createConnection(
            $firstPageResults,
            ['first' => 10],
            ['createdAt', 'id'],
            $paginator->cursorCoder,
        );
        $cursor1 = $firstPageConnection->pageInfo->endCursor;

        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid2 = $this->createGrid($select2, $paginator, ['first' => 10, 'after' => $cursor1]);
        $secondPageResults = iterator_to_array($grid2->getIterator());

        $secondPageConnection = $connectionFactory->createConnection(
            $secondPageResults,
            ['first' => 10, 'after' => $cursor1],
            ['createdAt', 'id'],
            $paginator->cursorCoder,
        );
        $cursor2 = $secondPageConnection->pageInfo->endCursor;

        $select3 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid3 = $this->createGrid($select3, $paginator, ['first' => 10, 'after' => $cursor2]);
        $thirdPageResults = iterator_to_array($grid3->getIterator());

        // Last page has only 3 items (23 total - 10 - 10 = 3)
        $this->assertCount(3, $thirdPageResults);

        $thirdPageConnection = $connectionFactory->createConnection(
            $thirdPageResults,
            ['first' => 10, 'after' => $cursor2],
            ['createdAt', 'id'],
            $paginator->cursorCoder,
        );

        $this->assertFalse($thirdPageConnection->pageInfo->hasNextPage);
    }
}
