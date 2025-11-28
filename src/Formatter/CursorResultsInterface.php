<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Formatter;

use Cardyo\Spiral\DataGrid\Cursor\PageInfo;

/**
 * Interface for result sets that support cursor pagination.
 *
 * This interface allows result processors to extract pagination metadata
 * and format results according to different standards (JSON:API, GraphQL, etc.).
 */
interface CursorResultsInterface
{
    /**
     * Get the items in the current page.
     *
     * @return iterable<mixed>
     */
    public function getItems(): iterable;

    /**
     * Get pagination metadata.
     */
    public function getPageInfo(): PageInfo;

    /**
     * Get cursor for a specific item.
     *
     * @param mixed $item The item to get cursor for
     * @return string|null The encoded cursor string, or null if cursor cannot be generated
     */
    public function getCursorForItem(mixed $item): ?string;
}
