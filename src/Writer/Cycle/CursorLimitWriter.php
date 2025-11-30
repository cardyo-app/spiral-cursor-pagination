<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Writer\Cycle;

use Cardyo\SpiralCursorPagination\Specification\Cursor\CursorLimit;
use Cycle\ORM\Select;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\SpecificationInterface;
use Spiral\DataGrid\WriterInterface;

/**
 * Writer for applying CursorLimit to Cycle ORM queries.
 *
 * Applies the LIMIT clause with +1 for hasMore detection.
 * The extra record is later removed from results to detect if more pages exist.
 *
 * @requires cycle/orm ^2.0
 * @psalm-suppress UndefinedClass - Cycle ORM is an optional dependency
 */
final class CursorLimitWriter implements WriterInterface
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

        if (!$specification instanceof CursorLimit) {
            return null;
        }

        return $source->limit($specification->getLimit());
    }
}
