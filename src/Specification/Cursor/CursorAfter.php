<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Specification\Cursor;

use Cardyo\Spiral\DataGrid\Cursor\CursorData;
use Spiral\DataGrid\SpecificationInterface;

/**
 * Specification for forward pagination using a cursor.
 *
 * Represents the 'after' cursor parameter from GraphQL Relay Connection spec
 * and the 'page[after]' parameter from JSON:API cursor pagination profile.
 *
 * When applied, this specification filters the result set to only include
 * items that come after the specified cursor position in the sort order.
 *
 * @see https://relay.dev/graphql/connections.htm#sec-Forward-pagination-arguments
 * @see https://jsonapi.org/profiles/ethanresnick/cursor-pagination/#query-parameters
 */
final readonly class CursorAfter implements SpecificationInterface
{
    /**
     * @param CursorData $cursor The decoded cursor data representing the position to paginate after
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
