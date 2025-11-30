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

        // Only reorder if we have existing orderBy clauses
        if (!empty($tokens['orderBy'])) {
            // Get existing order clauses
            $existingOrders = $tokens['orderBy'];

            // Clear the order
            $builder->getQuery()->orderBy([]);

            // Reapply with reversed directions for backward pagination
            foreach ($existingOrders as [$field, $originalDirection]) {
                // Reverse the direction for backward pagination
                $newDirection = $originalDirection === 'ASC' ? 'DESC' : 'ASC';
                $source = $source->orderBy($field, $newDirection);
            }
        }

        return $source;
    }
}
