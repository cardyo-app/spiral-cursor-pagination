<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Unit\Response;

use Cardyo\SpiralCursorPagination\Response\Connection;
use Cardyo\SpiralCursorPagination\Response\Edge;
use Cardyo\SpiralCursorPagination\Response\PageInfo;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $edges = [
            new Edge(node: ['id' => 1], cursor: 'cursor1'),
            new Edge(node: ['id' => 2], cursor: 'cursor2'),
        ];
        $nodes = [['id' => 1], ['id' => 2]];
        $pageInfo = new PageInfo(
            hasNextPage: true,
            hasPreviousPage: false,
            startCursor: 'cursor1',
            endCursor: 'cursor2',
        );

        $connection = new Connection($edges, $nodes, $pageInfo, 100);

        $this->assertSame($edges, $connection->edges);
        $this->assertSame($nodes, $connection->nodes);
        $this->assertSame($pageInfo, $connection->pageInfo);
        $this->assertSame(100, $connection->totalCount);
    }

    public function testCountReturnsEdgeCount(): void
    {
        $edges = [
            new Edge(node: ['id' => 1], cursor: 'c1'),
            new Edge(node: ['id' => 2], cursor: 'c2'),
            new Edge(node: ['id' => 3], cursor: 'c3'),
        ];
        $pageInfo = new PageInfo(false, false, null, null);

        $connection = new Connection($edges, [], $pageInfo);

        $this->assertCount(3, $connection);
        $this->assertCount(3, $connection);
    }

    public function testIteratorIteratesOverEdges(): void
    {
        $edges = [
            new Edge(node: ['id' => 1], cursor: 'c1'),
            new Edge(node: ['id' => 2], cursor: 'c2'),
        ];
        $pageInfo = new PageInfo(false, false, null, null);

        $connection = new Connection($edges, [], $pageInfo);

        $iterated = [];
        foreach ($connection as $edge) {
            $iterated[] = $edge;
        }

        $this->assertSame($edges, $iterated);
    }

    public function testEmptyConnection(): void
    {
        $pageInfo = new PageInfo(false, false, null, null);
        $connection = new Connection([], [], $pageInfo);

        $this->assertCount(0, $connection);
        $this->assertSame([], $connection->edges);
        $this->assertSame([], $connection->nodes);
    }
}
