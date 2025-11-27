<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Feature;

use Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity\User;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\EntityFactory;
use Cardyo\Tests\SpiralCursorPagination\Support\GridFactoryHelper;
use PHPUnit\Framework\Attributes\Test;

final class CursorEncodingIntegrationTest extends FeatureTestCase
{
    #[Test]
    public function itShouldEncodeCursorsInConnectionEdges(): void
    {
        $users = EntityFactory::createUsers(5);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['first' => 5]);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection($results, ['first' => 5], ['createdAt', 'id'], $paginator->cursorCoder);

        $this->assertCount(5, $connection->edges);

        foreach ($connection->edges as $edge) {
            $this->assertNotEmpty($edge->cursor);
            // Cursors are base64-encoded, decode to check format
            $decoded = base64_decode($edge->cursor, strict: true);
            $this->assertNotFalse($decoded, 'Cursor should be valid base64');
            $this->assertStringStartsWith('cursor:v2:', $decoded);
        }
    }

    #[Test]
    public function itShouldSetStartAndEndCursorsInPageInfo(): void
    {
        $users = EntityFactory::createUsers(5);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['first' => 5]);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection($results, ['first' => 5], ['createdAt', 'id'], $paginator->cursorCoder);

        $this->assertNotNull($connection->pageInfo->startCursor);
        $this->assertNotNull($connection->pageInfo->endCursor);

        // Cursors are base64-encoded, decode to check format
        $decodedStart = base64_decode($connection->pageInfo->startCursor, strict: true);
        $decodedEnd = base64_decode($connection->pageInfo->endCursor, strict: true);
        $this->assertNotFalse($decodedStart, 'Start cursor should be valid base64');
        $this->assertNotFalse($decodedEnd, 'End cursor should be valid base64');
        $this->assertStringStartsWith('cursor:v2:', $decodedStart);
        $this->assertStringStartsWith('cursor:v2:', $decodedEnd);
        $this->assertNotSame($connection->pageInfo->startCursor, $connection->pageInfo->endCursor);
    }

    #[Test]
    public function itShouldDecodeEncodedCursorsCorrectly(): void
    {
        $users = EntityFactory::createUsers(10);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();
        $this->clear();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 5]);

        $firstPageResults = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $firstPageConnection = $connectionFactory->createConnection(
            $firstPageResults,
            ['first' => 5],
            ['createdAt', 'id'],
            $paginator->cursorCoder,
        );

        $endCursor = $firstPageConnection->pageInfo->endCursor;

        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid2 = $this->createGrid($select2, $paginator, ['first' => 5, 'after' => $endCursor]);

        $secondPageResults = iterator_to_array($grid2->getIterator());

        // Grid fetches limit+1 for hasNextPage detection (5 + 1 = 6, but only 5 remain)
        // But since we have 10 total and already showed 5, only 5 remain, so we get 5
        $this->assertCount(5, $secondPageResults);
        $this->assertSame('User 6', $secondPageResults[0]->getName());
        $this->assertSame('User 10', $secondPageResults[4]->getName());
    }

    #[Test]
    public function itShouldHandleInvalidCursorGracefully(): void
    {
        $users = EntityFactory::createUsers(10);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 5, 'after' => 'invalid-cursor']);

        $results = iterator_to_array($grid->getIterator());

        // Grid fetches limit+1 for hasNextPage detection
        // Invalid cursor is ignored, so it returns first 5+1 items
        $this->assertCount(6, $results);
        $this->assertSame('User 1', $results[0]->getName());
    }

    #[Test]
    public function itShouldPreserveCursorDataIntegrity(): void
    {
        $users = EntityFactory::createUsers(3);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 3]);

        $results = iterator_to_array($grid->getIterator());

        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createConnection($results, ['first' => 3], ['createdAt', 'id'], $paginator->cursorCoder);

        foreach ($connection->edges as $index => $edge) {
            $this->assertSame($results[$index]->getId(), $edge->node->getId());
            $this->assertSame($results[$index]->getName(), $edge->node->getName());
        }
    }
}
