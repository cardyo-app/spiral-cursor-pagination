<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Service;

use Cardyo\SpiralCursorPagination\CursorEncoder\CursorEncoderInterface;
use Cardyo\SpiralCursorPagination\Response\Connection;
use Cardyo\SpiralCursorPagination\Response\Edge;

/**
 * Factory for creating GraphQL Connection objects from query results.
 *
 * Transforms paginated query results into the GraphQL Complete Connection Model
 * structure with edges, nodes, and pageInfo.
 */
final class ConnectionFactory
{
    public function __construct(
        private readonly CursorGenerator $cursorGenerator,
        private readonly PaginationMetadataCalculator $metadataCalculator,
    ) {}

    /**
     * Create a Connection from query results.
     *
     * @param iterable<mixed> $results Query results (may include extra record)
     * @param array{first?: int, last?: int, after?: string, before?: string} $paginatorState State from CursorPaginator::getValue()
     * @param array<string> $sortFields Fields used for cursor generation
     * @param CursorEncoderInterface $encoder Encoder for generating cursors
     * @param int|null $totalCount Optional total count of all items
     */
    public function createConnection(
        iterable $results,
        array $paginatorState,
        array $sortFields,
        CursorEncoderInterface $encoder,
        ?int $totalCount = null,
    ): Connection {
        $results = is_array($results) ? $results : iterator_to_array($results);

        $requestedLimit = $paginatorState['first'] ?? $paginatorState['last'] ?? 0;
        $isBackward = isset($paginatorState['last']);
        $hasCursor = isset($paginatorState['after']) || isset($paginatorState['before']);

        $hasMore = count($results) > $requestedLimit;

        if ($isBackward) {
            $results = array_reverse($results);
        }

        if ($hasMore) {
            if ($isBackward) {
                array_shift($results);
            } else {
                array_pop($results);
            }
        }

        $edges = array_map(
            fn($node): Edge => new Edge(
                node: $node,
                cursor: $this->cursorGenerator->generate($node, $sortFields, $encoder),
            ),
            $results,
        );

        $startCursor = $edges[0]->cursor ?? null;
        $endCursor = $edges[count($edges) - 1]->cursor ?? null;

        $pageInfo = $this->metadataCalculator->calculatePageInfo(
            resultCount: count($results) + ($hasMore ? 1 : 0),
            requestedLimit: $requestedLimit,
            isBackward: $isBackward,
            hasCursor: $hasCursor,
            startCursor: $startCursor,
            endCursor: $endCursor,
        );

        $nodes = array_map(fn(Edge $edge): mixed => $edge->node, $edges);

        return new Connection(
            edges: $edges,
            nodes: $nodes,
            pageInfo: $pageInfo,
            totalCount: $totalCount,
        );
    }
}
