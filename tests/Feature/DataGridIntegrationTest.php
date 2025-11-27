<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Feature;

use Spiral\DataGrid\GridInterface;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity\User;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\EntityFactory;
use Cardyo\Tests\SpiralCursorPagination\Support\GridFactoryHelper;
use PHPUnit\Framework\Attributes\Test;

final class DataGridIntegrationTest extends FeatureTestCase
{
    #[Test]
    public function itShouldIntegrateWithSpiralDataGrid(): void
    {
        $users = EntityFactory::createUsers(20);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator);

        $this->assertNotNull($grid);
        $this->assertInstanceOf(GridInterface::class, $grid);
    }

    #[Test]
    public function itShouldApplySpecificationsToDataSource(): void
    {
        $users = EntityFactory::createUsers(20);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['first' => 5]);

        $results = iterator_to_array($grid->getIterator());

        // Grid fetches limit+1 for hasNextPage detection
        $this->assertCount(6, $results);
    }

    #[Test]
    public function itShouldWorkWithMultipleGridInstances(): void
    {
        $users = EntityFactory::createUsers(30);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);

        $select1 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid1 = $this->createGrid($select1, $paginator, ['first' => 10]);

        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid2 = $this->createGrid($select2, $paginator, ['last' => 10]);

        $results1 = iterator_to_array($grid1->getIterator());
        $results2 = iterator_to_array($grid2->getIterator());

        // Grid fetches limit+1 for hasNextPage detection
        $this->assertCount(11, $results1);
        $this->assertCount(11, $results2);

        // Forward pagination: starts from User 1
        $this->assertSame('User 1', $results1[0]->getName());

        // Backward pagination: sorted in reverse, last 10 items starting from User 30
        $this->assertSame('User 30', $results2[0]->getName());
    }

    #[Test]
    public function itShouldSupportIteratorReuse(): void
    {
        $users = EntityFactory::createUsers(10);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['first' => 10]);

        $results1 = iterator_to_array($grid->getIterator());
        $results2 = iterator_to_array($grid->getIterator());

        $this->assertCount(10, $results1);
        $this->assertCount(10, $results2);
        $this->assertEquals($results1, $results2);
    }

    #[Test]
    public function itShouldApplyWritersInCorrectOrder(): void
    {
        $users = EntityFactory::createUsers(20);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);
        $select = GridFactoryHelper::createSelect($this->getOrm(), User::class);

        $grid = $this->createGrid($select, $paginator, ['first' => 5]);

        // Consume iterator to build the query
        iterator_to_array($grid->getIterator());

        // Check the SQL from the Select object
        $sql = $this->getSql($select);

        $this->assertStringContainsString('ORDER BY', $sql);
        $this->assertStringContainsString('LIMIT', $sql);

        $orderByPos = strpos($sql, 'ORDER BY');
        $limitPos = strpos($sql, 'LIMIT');

        $this->assertNotFalse($orderByPos);
        $this->assertNotFalse($limitPos);
        $this->assertLessThan($limitPos, $orderByPos);
    }

    #[Test]
    public function itShouldHandleGridRecreationWithDifferentValues(): void
    {
        $users = EntityFactory::createUsers(30);
        foreach ($users as $user) {
            $this->persist($user);
        }

        $this->flush();

        $paginator = GridFactoryHelper::createCursorPaginator(defaultLimit: 10);

        // Create first grid with first=10
        $select1 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid1 = $this->createGrid($select1, $paginator, ['first' => 10]);
        $results1 = iterator_to_array($grid1->getIterator());

        // Create second grid with first=5
        $select2 = GridFactoryHelper::createSelect($this->getOrm(), User::class);
        $grid2 = $this->createGrid($select2, $paginator, ['first' => 5]);
        $results2 = iterator_to_array($grid2->getIterator());

        // Grid fetches limit+1 for hasNextPage detection
        $this->assertCount(11, $results1);
        $this->assertCount(6, $results2);
    }
}
