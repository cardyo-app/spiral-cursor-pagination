<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Response;

use Nyholm\Psr7\Response;

/**
 * PSR-7 HTTP response for Connection objects.
 *
 * Wraps Nyholm PSR-7 Response to provide GraphQL Relay-compatible JSON structure.
 *
 * Default JSON structure:
 * ```json
 * {
 *   "data": {
 *     "edges": [...],
 *     "pageInfo": {...},
 *     "nodes": [...],
 *     "totalCount": 100
 *   }
 * }
 * ```
 *
 * Options:
 * - status: HTTP status code (default: 200)
 * - property: Root property name (default: 'data')
 * - includeNodes: Include nodes array (default: true)
 * - includeTotalCount: Include totalCount (default: true if available)
 * - headers: Additional HTTP headers (merged with default Content-Type)
 */
final class ConnectionResponse implements ConnectionResponseInterface
{
    private Response $response;

    /**
     * Create empty response.
     * Use withConnection() to populate with data.
     */
    public function __construct()
    {
        $this->response = new Response(
            status: 200,
            headers: ['Content-Type' => 'application/json'],
            body: '{}'
        );
    }

    #[\Override]
    public function withConnection(Connection $connection, array $options = []): ConnectionResponseInterface
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

        // Create new instance with populated response
        $instance = new self();
        $instance->response = new Response($statusCode, $headers, $jsonBody);

        return $instance;
    }

    /**
     * Build JSON data structure from Connection.
     *
     * @param Connection $connection
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildJsonData(Connection $connection, array $options): array
    {
        $data = [
            'edges' => $this->serializeEdges($connection->edges),
            'pageInfo' => $this->serializePageInfo($connection->pageInfo),
        ];

        // Include nodes array if requested (default: true)
        if ($this->option($options, 'includeNodes', true)) {
            $data['nodes'] = $connection->nodes;
        }

        // Include totalCount if available and requested (default: true)
        if ($this->option($options, 'includeTotalCount', true) && $connection->totalCount !== null) {
            $data['totalCount'] = $connection->totalCount;
        }

        // Wrap in root property
        $property = $this->option($options, 'property', 'data');

        return [$property => $data];
    }

    /**
     * Serialize edges to array format.
     *
     * @param array<Edge> $edges
     * @return array<int, array{cursor: string, node: mixed}>
     */
    private function serializeEdges(array $edges): array
    {
        return array_map(
            fn(Edge $edge): array => [
                'cursor' => $edge->cursor,
                'node' => $edge->node,
            ],
            $edges
        );
    }

    /**
     * Serialize pageInfo to array format.
     *
     * @param PageInfo $pageInfo
     * @return array{hasNextPage: bool, hasPreviousPage: bool, startCursor: string|null, endCursor: string|null}
     */
    private function serializePageInfo(PageInfo $pageInfo): array
    {
        return [
            'hasNextPage' => $pageInfo->hasNextPage,
            'hasPreviousPage' => $pageInfo->hasPreviousPage,
            'startCursor' => $pageInfo->startCursor,
            'endCursor' => $pageInfo->endCursor,
        ];
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

    // ========================================================================
    // PSR-7 ResponseInterface delegation
    // ========================================================================

    #[\Override]
    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    #[\Override]
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $instance = clone $this;
        $instance->response = $this->response->withStatus($code, $reasonPhrase);
        return $instance;
    }

    #[\Override]
    public function getReasonPhrase(): string
    {
        return $this->response->getReasonPhrase();
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return $this->response->getProtocolVersion();
    }

    #[\Override]
    public function withProtocolVersion(string $version): static
    {
        $instance = clone $this;
        $instance->response = $this->response->withProtocolVersion($version);
        return $instance;
    }

    #[\Override]
    public function getHeaders(): array
    {
        return $this->response->getHeaders();
    }

    #[\Override]
    public function hasHeader(string $name): bool
    {
        return $this->response->hasHeader($name);
    }

    #[\Override]
    public function getHeader(string $name): array
    {
        return $this->response->getHeader($name);
    }

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    #[\Override]
    public function withHeader(string $name, $value): static
    {
        $instance = clone $this;
        $instance->response = $this->response->withHeader($name, $value);
        return $instance;
    }

    #[\Override]
    public function withAddedHeader(string $name, $value): static
    {
        $instance = clone $this;
        $instance->response = $this->response->withAddedHeader($name, $value);
        return $instance;
    }

    #[\Override]
    public function withoutHeader(string $name): static
    {
        $instance = clone $this;
        $instance->response = $this->response->withoutHeader($name);
        return $instance;
    }

    #[\Override]
    public function getBody(): \Psr\Http\Message\StreamInterface
    {
        return $this->response->getBody();
    }

    #[\Override]
    public function withBody(\Psr\Http\Message\StreamInterface $body): static
    {
        $instance = clone $this;
        $instance->response = $this->response->withBody($body);
        return $instance;
    }
}
