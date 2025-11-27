<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Writer\Cycle;

use Cycle\ORM\Select;
use Cardyo\SpiralCursorPagination\Specification\Cursor\SortDirection;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\SpecificationInterface;
use Spiral\DataGrid\WriterInterface;

/**
 * Writer for applying SortDirection to Cycle ORM queries.
 *
 * Applies ORDER BY clause to Cycle\ORM\Select queries based on the configured
 * sort fields and direction. Automatically reverses direction for backward pagination.
 *
 * @requires cycle/orm ^2.0
 */
final class SortDirectionWriter implements WriterInterface
{
    #[\Override]
    public function write(mixed $source, SpecificationInterface $specification, Compiler $compiler): mixed
    {
        // Check if Cycle ORM is available
        if (!class_exists(Select::class)) {
            return null;
        }

        if (!$source instanceof Select) {
            return null;
        }

        if (!$specification instanceof SortDirection) {
            return null;
        }

        $direction = $specification->getDirection();

        foreach ($specification->getFields() as $field) {
            $source = $source->orderBy($field, $direction);
        }

        return $source;
    }
}
