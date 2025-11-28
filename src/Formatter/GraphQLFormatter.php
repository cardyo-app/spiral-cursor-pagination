<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Formatter;

/**
 * Formats cursor pagination results according to GraphQL Relay Connection specification.
 *
 * Produces responses with:
 * - edges: Array of edge objects, each containing a node and cursor
 * - pageInfo: Pagination metadata with hasNextPage, hasPreviousPage, startCursor, endCursor
 * - totalCount: Optional total number of items
 *
 * Example output:
 * ```json
 * {
 *   "edges": [
 *     {
 *       "node": {
 *         "id": "1",
 *         "name": "John Doe"
 *       },
 *       "cursor": "eyJzb3J0X3ZhbHVlcyI6eyJpZCI6MX19"
 *     }
 *   ],
 *   "pageInfo": {
 *     "hasNextPage": true,
 *     "hasPreviousPage": false,
 *     "startCursor": "eyJzb3J0X3ZhbHVlcyI6eyJpZCI6MX19",
 *     "endCursor": "eyJzb3J0X3ZhbHVlcyI6eyJpZCI6MTB9fQ"
 *   },
 *   "totalCount": 1000
 * }
 * ```
 *
 * @see https://relay.dev/graphql/connections.htm
 * @see https://graphql.org/learn/pagination/
 */
final class GraphQLFormatter
{
    /**
     * @param bool $includeTotalCount Whether to include totalCount in the response
     */
    public function __construct(
        private readonly bool $includeTotalCount = false,
    ) {
    }

    /**
     * Format cursor pagination results.
     *
     * @param CursorResultsInterface $results The cursor pagination results
     * @return array{edges: array<array{node: mixed, cursor: string}>, pageInfo: array, totalCount?: int}
     */
    public function format(CursorResultsInterface $results): array
    {
        $pageInfo = $results->getPageInfo();
        $edges = [];

        foreach ($results->getItems() as $item) {
            $cursor = $results->getCursorForItem($item);

            $edges[] = [
                'node' => $item,
                'cursor' => $cursor ?? '',
            ];
        }

        $response = [
            'edges' => $edges,
            'pageInfo' => $pageInfo->toGraphQLArray(),
        ];

        if ($this->includeTotalCount && $pageInfo->totalCount !== null) {
            $response['totalCount'] = $pageInfo->totalCount;
        }

        return $response;
    }

    /**
     * Format results with a custom node transformer.
     *
     * This allows transforming each node before including it in the edge.
     *
     * @param CursorResultsInterface $results The cursor pagination results
     * @param callable(mixed): mixed $nodeTransformer Function to transform each node
     * @return array{edges: array<array{node: mixed, cursor: string}>, pageInfo: array, totalCount?: int}
     */
    public function formatWithTransformer(
        CursorResultsInterface $results,
        callable $nodeTransformer,
    ): array {
        $pageInfo = $results->getPageInfo();
        $edges = [];

        foreach ($results->getItems() as $item) {
            $cursor = $results->getCursorForItem($item);
            $transformedNode = $nodeTransformer($item);

            $edges[] = [
                'node' => $transformedNode,
                'cursor' => $cursor ?? '',
            ];
        }

        $response = [
            'edges' => $edges,
            'pageInfo' => $pageInfo->toGraphQLArray(),
        ];

        if ($this->includeTotalCount && $pageInfo->totalCount !== null) {
            $response['totalCount'] = $pageInfo->totalCount;
        }

        return $response;
    }
}
