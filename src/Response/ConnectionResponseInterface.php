<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Response;

use Psr\Http\Message\ResponseInterface;

/**
 * Interface for creating HTTP responses from Connection objects.
 *
 * Extends PSR-7 ResponseInterface for full compatibility with Spiral's HTTP layer.
 * Similar to Spiral's GridResponseInterface pattern.
 *
 * Implementations should:
 * - Serialize Connection data to JSON
 * - Set appropriate Content-Type header
 * - Allow customization via options
 */
interface ConnectionResponseInterface extends ResponseInterface
{
    /**
     * Create response configured with Connection result.
     *
     * @param Connection $connection The paginated connection data
     * @param array<string, mixed> $options Optional response configuration:
     *   - status: HTTP status code (default: 200)
     *   - property: Root property name (default: 'data')
     *   - includeNodes: Include nodes array (default: true)
     *   - includeTotalCount: Include totalCount if available (default: true)
     *   - headers: Additional HTTP headers
     * @return self
     */
    public function withConnection(Connection $connection, array $options = []): self;
}
