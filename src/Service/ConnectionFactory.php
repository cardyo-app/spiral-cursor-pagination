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
     * @param mixed $query The query object (e.g., Cycle\ORM\Select) to extract sort fields from
     * @param array{first?: int, last?: int, after?: string, before?: string} $paginatorState State from CursorPaginator::getValue()
     * @param CursorEncoderInterface $encoder Encoder for generating cursors
     * @param int|null $totalCount Optional total count of all items
     * @param iterable<mixed>|null $originalEntities Original entities for cursor generation (if results are already mapped)
     *
     * @psalm-suppress UndefinedClass
     */
    public function createConnection(
        iterable $results,
        mixed $query,
        array $paginatorState,
        CursorEncoderInterface $encoder,
        ?int $totalCount = null,
        ?iterable $originalEntities = null,
    ): Connection {
        $results = is_array($results) ? $results : iterator_to_array($results);

        // Use original entities for cursor generation if provided, otherwise use results
        $entitiesForCursors = $originalEntities !== null
            ? (is_array($originalEntities) ? $originalEntities : iterator_to_array($originalEntities))
            : $results;

        $requestedLimit = $paginatorState['first'] ?? $paginatorState['last'] ?? 0;
        $isBackward = isset($paginatorState['last']);
        $hasCursor = isset($paginatorState['after']) || isset($paginatorState['before']);

        $hasMore = count($results) > $requestedLimit;

        // Remove the extra record used for hasMore detection
        // Note: We don't reverse results for backward pagination since the query
        // ORDER BY is not reversed (KeysetFilterWriter handles the correct operator)
        if ($hasMore) {
            // For both forward and backward, remove the last item
            // since we always order in the user-requested direction
            array_pop($results);
            array_pop($entitiesForCursors);
        }

        $edges = [];
        foreach ($results as $index => $node) {
            $entity = $entitiesForCursors[$index] ?? $node;
            $edges[] = new Edge(
                node: $node,
                cursor: $this->cursorGenerator->generateFromQuery($entity, $query, $encoder),
            );
        }

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
