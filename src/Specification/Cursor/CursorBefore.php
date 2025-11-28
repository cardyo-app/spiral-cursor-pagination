<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Specification\Cursor;

use Cardyo\Spiral\DataGrid\Cursor\CursorData;
use Spiral\DataGrid\SpecificationInterface;

/**
 * Specification for backward pagination using a cursor.
 *
 * Represents the 'before' cursor parameter from GraphQL Relay Connection spec
 * and the 'page[before]' parameter from JSON:API cursor pagination profile.
 *
 * When applied, this specification filters the result set to only include
 * items that come before the specified cursor position in the sort order.
 *
 * @see https://relay.dev/graphql/connections.htm#sec-Backward-pagination-arguments
 * @see https://jsonapi.org/profiles/ethanresnick/cursor-pagination/#query-parameters
 */
final readonly class CursorBefore implements SpecificationInterface
{
    /**
     * @param CursorData $cursor The decoded cursor data representing the position to paginate before
     */
    public function __construct(
        public CursorData $cursor,
    ) {
    }

    public function getValue(): CursorData
    {
        return $this->cursor;
    }
}
