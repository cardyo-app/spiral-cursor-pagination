<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Writer\Cycle;

use Cycle\ORM\Select;
use Cardyo\SpiralCursorPagination\Specification\Cursor\CursorLimit;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\SpecificationInterface;
use Spiral\DataGrid\WriterInterface;

/**
 * Writer for applying CursorLimit to Cycle ORM queries.
 *
 * Applies LIMIT clause to Cycle\ORM\Select queries, using the query limit
 * (which includes the extra fetch if configured).
 *
 * @requires cycle/orm ^2.0
 */
final class CursorLimitWriter implements WriterInterface
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

        if (!$specification instanceof CursorLimit) {
            return null;
        }

        return $source->limit($specification->getQueryLimit());
    }
}
