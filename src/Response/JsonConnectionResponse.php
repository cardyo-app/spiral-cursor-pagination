<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Response;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final class JsonConnectionResponse implements ConnectionResponseInterface
{
    #[\Override]
    public function withConnection(Connection $connection, array $options = []): ResponseInterface
    {
        // Build JSON data
        $jsonData = $this->buildJsonData($connection, $options);
        $jsonBody = json_encode($jsonData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        // Get status code from options
        $statusCode = (int) ($options['status'] ?? 200);

        // Merge headers
        $headers = array_merge(
            ['Content-Type' => 'application/json'],
            $options['headers'] ?? []
        );

        // Return PSR-7 response
        return new Response($statusCode, $headers, $jsonBody);
    }

    /**
     * Build JSON:API compliant response structure from Connection.
     *
     * Follows the JSON:API Cursor Pagination Profile:
     * @see https://jsonapi.org/profiles/ethanresnick/cursor-pagination/
     *
     * Example response:
     * {
     *   "data": [
     *     {
     *       "id": "123",
     *       "name": "Customer 1",
     *       "email": "customer1@example.com"
     *     },
     *     {
     *       "id": "456",
     *       "name": "Customer 2",
     *       "email": "customer2@example.com"
     *     }
     *   ],
     *   "meta": {
     *     "page": {
     *       "from": "Y3Vyc29yOnYyOnsiY3JlYXRlZF9hdCI6IjIwMjUtMTEtMjYifQ",
     *       "to": "Y3Vyc29yOnYyOnsiY3JlYXRlZF9hdCI6IjIwMjUtMTEtMjcifQ",
     *       "hasMore": true,
     *       "hasPrevious": false,
     *       "total": 150
     *     }
     *   }
     * }
     *
     * Field meanings:
     * - from: Start cursor of current page (use with page[before] to get previous page)
     * - to: End cursor of current page (use with page[after] to get next page)
     * - hasMore: Whether there are more results after current page
     * - hasPrevious: Whether there are results before current page
     * - total: Total count of all items (optional, if countTotal was requested)
     *
     * @param Connection $connection
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildJsonData(Connection $connection, array $options): array
    {
        $response = [
            'data' => $connection->nodes,
        ];

        // Build page metadata (JSON:API spec uses "page" not "pagination")
        $page = [];

        // Add cursors for current page boundaries
        // "from" = startCursor (use with page[before] to get previous page)
        // "to" = endCursor (use with page[after] to get next page)
        if ($connection->pageInfo->startCursor !== null) {
            $page['from'] = $connection->pageInfo->startCursor;
        }
        if ($connection->pageInfo->endCursor !== null) {
            $page['to'] = $connection->pageInfo->endCursor;
        }

        // Add hasMore flag (indicates hasNextPage)
        $page['hasMore'] = $connection->pageInfo->hasNextPage;

        // Add hasPrevious flag (indicates hasPreviousPage)
        if ($connection->pageInfo->hasPreviousPage) {
            $page['hasPrevious'] = true;
        }

        // Add total count if available
        if ($connection->totalCount !== null) {
            $page['total'] = $connection->totalCount;
        }

        // Add meta.page per JSON:API spec
        if (!empty($page)) {
            $response['meta'] = ['page' => $page];
        }

        return $response;
    }

    /**
     * Get option value with default.
     *
     * @template T
     * @param array<string, mixed> $options
     * @param string $name
     * @param T $default
     * @return T|mixed
     */
    private function option(array $options, string $name, mixed $default): mixed
    {
        return $options[$name] ?? $default;
    }
}
