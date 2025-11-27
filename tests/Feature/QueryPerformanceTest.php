<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Feature;

use Cardyo\SpiralCursorPagination\Service\CursorGenerator;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity\User;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\EntityFactory;
use Cardyo\Tests\SpiralCursorPagination\Support\GridFactoryHelper;
use PHPUnit\Framework\Attributes\Test;

final class QueryPerformanceTest extends FeatureTestCase
{
    #[Test]
    public function itShouldNotUseOffsetInGeneratedQuery(): void
    {
        $users = EntityFactory::createUsers(50);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();
        $this->clear();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $cursorGenerator = new CursorGenerator();

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 10]);
        $firstPage = iterator_to_array($grid->getIterator());

        $cursor = $cursorGenerator->generate($firstPage[9], ['createdAt', 'id'], $paginator->cursorCoder);

        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $this->createGrid($select2, $paginator, ['first' => 10, 'after' => $cursor]);

        $sql = $this->getSql($select2);

        $this->assertStringNotContainsString('OFFSET', $sql);
    }

    #[Test]
    public function itShouldUseKeysetFilteringInsteadOfOffset(): void
    {
        $users = EntityFactory::createUsers(30);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();
        $this->clear();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $cursorGenerator = new CursorGenerator();

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 10]);
        $firstPage = iterator_to_array($grid->getIterator());

        $cursor = $cursorGenerator->generate($firstPage[9], ['createdAt', 'id'], $paginator->cursorCoder);

        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $this->createGrid($select2, $paginator, ['first' => 10, 'after' => $cursor]);

        $sql = $this->getSql($select2);

        $this->assertStringContainsString('WHERE', $sql);
        $this->assertStringContainsString('created_at', $sql);
    }

    #[Test]
    public function itShouldGenerateEfficientQueryForLargeDatasets(): void
    {
        $users = EntityFactory::createUsers(1000);
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

        $this->assertCount(10, $firstPageConnection->edges);

        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid2 = $this->createGrid($select2, $paginator, ['first' => 10, 'after' => $firstPageConnection->pageInfo->endCursor]);

        $startTime = microtime(true);
        $secondPageResults = iterator_to_array($grid2->getIterator());
        $endTime = microtime(true);

        $executionTime = ($endTime - $startTime) * 1000;

        $secondPageConnection = $connectionFactory->createConnection(
            $secondPageResults,
            ['first' => 10, 'after' => $firstPageConnection->pageInfo->endCursor],
            ['createdAt', 'id'],
            $paginator->cursorCoder,
        );

        $this->assertCount(10, $secondPageConnection->edges);
        $this->assertLessThan(100, $executionTime, 'Query should execute in less than 100ms');
    }

    #[Test]
    public function itShouldApplyLimitPlusOneOptimization(): void
    {
        $users = EntityFactory::createUsers(11);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 10]);

        $sql = $this->getSql($select);

        $this->assertStringContainsString('LIMIT', $sql);

        $results = iterator_to_array($grid->getIterator());

        $this->assertCount(11, $results);
    }

    #[Test]
    public function itShouldMaintainPerformanceAcrossMultiplePages(): void
    {
        $users = EntityFactory::createUsers(100);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();
        $this->clear();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $cursorGenerator = new CursorGenerator();

        $executionTimes = [];

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 10]);
        $results = iterator_to_array($grid->getIterator());
        $cursor = $cursorGenerator->generate($results[9], ['createdAt', 'id'], $paginator->cursorCoder);

        for ($i = 0; $i < 5; ++$i) {
            $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
            $grid = $this->createGrid($select, $paginator, ['first' => 10, 'after' => $cursor]);

            $startTime = microtime(true);
            $results = iterator_to_array($grid->getIterator());
            $endTime = microtime(true);

            $executionTimes[] = ($endTime - $startTime) * 1000;

            if ($results !== []) {
                $cursor = $cursorGenerator->generate($results[min(9, count($results) - 1)], ['createdAt', 'id'], $paginator->cursorCoder);
            }
        }

        $avgTime = array_sum($executionTimes) / count($executionTimes);
        $this->assertLessThan(100, $avgTime, 'Average query time should be less than 100ms');

        $variance = 0;
        foreach ($executionTimes as $time) {
            $variance += ($time - $avgTime) ** 2;
        }

        $variance /= count($executionTimes);
        $stdDev = sqrt($variance);

        $this->assertLessThan($avgTime, $stdDev, 'Performance should be consistent across pages');
    }

    #[Test]
    public function itShouldGenerateIndexFriendlyQueries(): void
    {
        $users = EntityFactory::createUsers(20);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();
        $this->clear();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $cursorGenerator = new CursorGenerator();

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 10]);
        $firstPage = iterator_to_array($grid->getIterator());

        $cursor = $cursorGenerator->generate($firstPage[9], ['createdAt', 'id'], $paginator->cursorCoder);

        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $this->createGrid($select2, $paginator, ['first' => 10, 'after' => $cursor]);

        $sql = $this->getSql($select2);

        $this->assertStringContainsString('ORDER BY', $sql);

        $orderByPos = strpos($sql, 'ORDER BY');
        $wherePos = strpos($sql, 'WHERE');

        $this->assertNotFalse($orderByPos);
        if ($wherePos !== false) {
            $this->assertLessThan($orderByPos, $wherePos);
        }
    }
}
