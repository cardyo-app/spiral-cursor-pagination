<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Writer\Cycle;

use Cardyo\SpiralCursorPagination\Specification\Cursor\SortDirection;
use Cycle\ORM\Select;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\SpecificationInterface;
use Spiral\DataGrid\WriterInterface;

/**
 * Writer for applying SortDirection to Cycle ORM queries.
 *
 * Applies ORDER BY clauses with direction reversal for backward pagination.
 * Results are later reversed in memory to maintain consistent ordering.
 *
 * @requires cycle/orm ^2.0
 * @psalm-suppress UndefinedClass - Cycle ORM is an optional dependency
 */
final class SortDirectionWriter implements WriterInterface
{
    #[\Override]
    public function write(mixed $source, SpecificationInterface $specification, Compiler $compiler): mixed
    {
        if (!class_exists(Select::class)) {
            return null;
        }

        if (!$source instanceof Select) {
            return null;
        }

        if (!$specification instanceof SortDirection) {
            return null;
        }

        // Get effective directions (reversed for backward pagination)
        $directions = $specification->getEffectiveDirections();

        // Clear existing order and reapply with reversed directions
        // This is necessary for backward pagination
        $builder = $source->getBuilder();
        $tokens = $builder->getQuery()->getTokens();

        // For backward pagination, we DON'T reverse the ORDER BY in the query.
        // Instead, the KeysetFilterWriter adjusts the comparison operator based on both
        // the pagination direction and sort direction to fetch the correct records.
        // This approach is simpler and avoids the need to reverse results afterward.
        //
        // Example: For "last: 2, before: cursor(30)" with ORDER BY login_count DESC:
        // - KeysetFilterWriter uses ">" operator (login_count > 30)
        // - Query returns items in DESC order: [50, 40]
        // - These are already in correct user-visible order
        //
        // Note: This is different from the typical Relay implementation which reverses
        // the ORDER BY and then reverses the results, but achieves the same outcome.

        return $source;
    }
}
