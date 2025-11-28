<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Formatter;

use Cardyo\Spiral\DataGrid\Cursor\Direction;

/**
 * Formats cursor pagination results according to JSON:API cursor pagination profile.
 *
 * Produces responses with:
 * - data: Array of resource objects
 * - links: Object with 'prev' and 'next' pagination links
 * - meta: Optional metadata including page info and per-item cursors
 *
 * Example output:
 * ```json
 * {
 *   "data": [
 *     {
 *       "type": "users",
 *       "id": "1",
 *       "attributes": {...},
 *       "meta": {
 *         "page": {
 *           "cursor": "eyJzb3J0X3ZhbHVlcyI6eyJpZCI6MX19"
 *         }
 *       }
 *     }
 *   ],
 *   "links": {
 *     "prev": "/api/users?page[before]=...&page[size]=10",
 *     "next": "/api/users?page[after]=...&page[size]=10"
 *   },
 *   "meta": {
 *     "page": {
 *       "hasNext": true,
 *       "hasPrevious": false
 *     }
 *   }
 * }
 * ```
 *
 * @see https://jsonapi.org/profiles/ethanresnick/cursor-pagination/
 */
final class JsonApiFormatter
{
    /**
     * @param string $baseUrl Base URL for pagination links (e.g., '/api/users')
     * @param bool $includePerItemCursors Whether to include cursor in each item's meta
     * @param bool $includePageMeta Whether to include page metadata in response meta
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly bool $includePerItemCursors = false,
        private readonly bool $includePageMeta = true,
    ) {
    }

    /**
     * Format cursor pagination results.
     *
     * @param CursorResultsInterface $results The cursor pagination results
     * @param int $pageSize The requested page size
     * @return array{data: array<mixed>, links: array{prev: string|null, next: string|null}, meta?: array}
     */
    public function format(CursorResultsInterface $results, int $pageSize): array
    {
        $pageInfo = $results->getPageInfo();
        $data = [];

        foreach ($results->getItems() as $item) {
            $formattedItem = $this->formatItem($item, $results);
            $data[] = $formattedItem;
        }

        $response = [
            'data' => $data,
            'links' => $this->buildLinks($pageInfo, $pageSize),
        ];

        if ($this->includePageMeta) {
            $response['meta'] = [
                'page' => $pageInfo->toJsonApiArray(),
            ];
        }

        return $response;
    }

    /**
     * Format a single item, optionally including cursor in meta.
     *
     * @param mixed $item The item to format
     * @param CursorResultsInterface $results The results container for cursor generation
     * @return mixed The formatted item (unchanged unless per-item cursors are enabled)
     */
    private function formatItem(mixed $item, CursorResultsInterface $results): mixed
    {
        if (!$this->includePerItemCursors) {
            return $item;
        }

        $cursor = $results->getCursorForItem($item);

        // If item is an array, add cursor to meta
        if (\is_array($item)) {
            if ($cursor !== null) {
                $item['meta'] = $item['meta'] ?? [];
                $item['meta']['page'] = ['cursor' => $cursor];
            }
            return $item;
        }

        // If item is an object, return as-is (application should handle cursor injection)
        return $item;
    }

    /**
     * Build pagination links.
     *
     * @param \Cardyo\Spiral\DataGrid\Cursor\PageInfo $pageInfo Pagination metadata
     * @param int $pageSize The page size
     * @return array{prev: string|null, next: string|null}
     */
    private function buildLinks(\Cardyo\Spiral\DataGrid\Cursor\PageInfo $pageInfo, int $pageSize): array
    {
        return [
            'prev' => $pageInfo->hasPreviousPage && $pageInfo->startCursor !== null
                ? $this->buildLink($pageInfo->startCursor, Direction::BACKWARD, $pageSize)
                : null,
            'next' => $pageInfo->hasNextPage && $pageInfo->endCursor !== null
                ? $this->buildLink($pageInfo->endCursor, Direction::FORWARD, $pageSize)
                : null,
        ];
    }

    /**
     * Build a pagination link URL.
     *
     * @param string $cursor The cursor value
     * @param Direction $direction The pagination direction
     * @param int $pageSize The page size
     * @return string The pagination link URL
     */
    private function buildLink(string $cursor, Direction $direction, int $pageSize): string
    {
        $params = [
            'page[size]' => $pageSize,
        ];

        if ($direction === Direction::FORWARD) {
            $params['page[after]'] = $cursor;
        } else {
            $params['page[before]'] = $cursor;
        }

        $queryString = \http_build_query($params);

        return $this->baseUrl . '?' . $queryString;
    }
}
