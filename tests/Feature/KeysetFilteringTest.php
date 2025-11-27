<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Feature;

use Cardyo\SpiralCursorPagination\Service\CursorGenerator;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity\User;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\EntityFactory;
use Cardyo\Tests\SpiralCursorPagination\Support\GridFactoryHelper;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;

final class KeysetFilteringTest extends FeatureTestCase
{
    #[Test]
    public function itShouldGenerateCorrectKeysetWhereClauseForForwardPagination(): void
    {
        $baseDate = new DateTimeImmutable('2024-01-01 00:00:00');
        $users = [
            EntityFactory::createUser(['createdAt' => $baseDate]),
            EntityFactory::createUser(['createdAt' => $baseDate->modify('+1 second')]),
            EntityFactory::createUser(['createdAt' => $baseDate->modify('+2 seconds')]),
        ];

        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();
        $this->clear();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $cursorGenerator = new CursorGenerator();

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 2]);
        $firstPage = iterator_to_array($grid->getIterator());

        $cursor = $cursorGenerator->generate($firstPage[1], ['createdAt', 'id'], $paginator->cursorCoder);

        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $this->createGrid($select2, $paginator, ['first' => 10, 'after' => $cursor]);

        $sql = $this->getSql($select2);

        $this->assertStringContainsString('WHERE', $sql);
        $this->assertStringContainsString('created_at', $sql);
    }

    #[Test]
    public function itShouldGenerateCorrectKeysetWhereClauseForBackwardPagination(): void
    {
        $baseDate = new DateTimeImmutable('2024-01-01 00:00:00');
        $users = [
            EntityFactory::createUser(['createdAt' => $baseDate]),
            EntityFactory::createUser(['createdAt' => $baseDate->modify('+1 second')]),
            EntityFactory::createUser(['createdAt' => $baseDate->modify('+2 seconds')]),
        ];

        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();
        $this->clear();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $cursorGenerator = new CursorGenerator();

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['last' => 2]);
        $lastPage = iterator_to_array($grid->getIterator());

        $cursor = $cursorGenerator->generate($lastPage[0], ['createdAt', 'id'], $paginator->cursorCoder);

        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $this->createGrid($select2, $paginator, ['last' => 10, 'before' => $cursor]);

        $sql = $this->getSql($select2);

        $this->assertStringContainsString('WHERE', $sql);
        $this->assertStringContainsString('created_at', $sql);
    }

    #[Test]
    public function itShouldApplyCorrectOrderByClause(): void
    {
        $users = EntityFactory::createUsers(5);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $this->createGrid($select, $paginator, ['first' => 10]);

        $sql = $this->getSql($select);

        $this->assertStringContainsString('ORDER BY', $sql);
        $this->assertStringContainsString('created_at', $sql);
    }

    #[Test]
    public function itShouldReverseOrderByForBackwardPagination(): void
    {
        $users = EntityFactory::createUsers(5);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);

        $forwardSelect = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $this->createGrid($forwardSelect, $paginator, ['first' => 10]);
        $forwardSql = $this->getSql($forwardSelect);

        $backwardSelect = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $this->createGrid($backwardSelect, $paginator, ['last' => 10]);
        $backwardSql = $this->getSql($backwardSelect);

        $this->assertNotSame($forwardSql, $backwardSql);
        $this->assertStringContainsString('ORDER BY', $forwardSql);
        $this->assertStringContainsString('ORDER BY', $backwardSql);
    }

    #[Test]
    public function itShouldApplyLimitClauseWithFetchExtra(): void
    {
        $users = EntityFactory::createUsers(15);
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
        $this->assertLessThanOrEqual(11, count($results));
    }

    #[Test]
    public function itShouldHandleCompositeKeysetWithMultipleFields(): void
    {
        $baseDate = new DateTimeImmutable('2024-01-01 00:00:00');

        $users = [
            EntityFactory::createUser(['createdAt' => $baseDate]),
            EntityFactory::createUser(['createdAt' => $baseDate]),
            EntityFactory::createUser(['createdAt' => $baseDate->modify('+1 second')]),
        ];

        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();
        $this->clear();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $cursorGenerator = new CursorGenerator();

        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid = $this->createGrid($select, $paginator, ['first' => 2]);
        $firstPage = iterator_to_array($grid->getIterator());

        $cursor = $cursorGenerator->generate($firstPage[1], ['createdAt', 'id'], $paginator->cursorCoder);

        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid2 = $this->createGrid($select2, $paginator, ['first' => 10, 'after' => $cursor]);

        $results = iterator_to_array($grid2->getIterator());

        $this->assertCount(1, $results);
        $this->assertSame('User 3', $results[0]->getName());
    }
}
