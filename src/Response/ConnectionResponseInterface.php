<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Response;

use Psr\Http\Message\ResponseInterface;

/**
 * Interface for creating HTTP responses from Connection objects.
 */
interface ConnectionResponseInterface
{
    /**
     * Create PSR-7 response with Connection result.
     *
     * @param Connection $connection The paginated connection data
     * @param array<string, mixed> $options Optional response configuration:
     *   - status: HTTP status code (default: 200)
     *   - headers: Additional HTTP headers
     * @return ResponseInterface PSR-7 HTTP response
     */
    public function withConnection(Connection $connection, array $options = []): ResponseInterface;
}
