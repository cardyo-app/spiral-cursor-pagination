<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Support;

use Cardyo\SpiralCursorPagination\CursorEncoder\EncoderV2;
use Cardyo\SpiralCursorPagination\Cursor\JsonCursorSerializer;
use Cardyo\SpiralCursorPagination\Specification\CursorPaginator;
use Cardyo\SpiralCursorPagination\Writer\Cycle\CursorLimitWriter;
use Cardyo\SpiralCursorPagination\Writer\Cycle\KeysetFilterWriter;
use Cardyo\SpiralCursorPagination\Writer\Cycle\SortDirectionWriter;
use Cycle\ORM\ORM;
use Cycle\ORM\Select;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\GridFactory;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Value\IntValue;
use Spiral\DataGrid\Specification\Value\RangeValue\Boundary;
use Spiral\DataGrid\Specification\Value\RangeValue;

final class GridFactoryHelper
{
    /**
     * Create a GridFactory configured with cursor pagination Writers.
     */
    public static function createGridFactory(): GridFactory
    {
        $compiler = new Compiler();
        $compiler->addWriter(new KeysetFilterWriter());
        $compiler->addWriter(new CursorLimitWriter());
        $compiler->addWriter(new SortDirectionWriter());
        return new GridFactory($compiler);
    }

    /**
     * Create a CursorPaginator with default configuration.
     *
     * @param array<string> $sortFields
     */
    public static function createCursorPaginator(
        int $defaultLimit = 10,
        int $minLimit = 1,
        int $maxLimit = 100,
        array $sortFields = ['createdAt', 'id'],
    ): CursorPaginator {
        $cursorCoder = new EncoderV2(new JsonCursorSerializer());

        $paginator = new CursorPaginator(
            defaultLimit: $defaultLimit,
            limitValue: new RangeValue(
                new IntValue(),
                Boundary::including($minLimit),
                Boundary::including($maxLimit),
            ),
            cursorCoder: $cursorCoder,
        );

        return $paginator->withSortFields($sortFields);
    }

    /**
     * Create a Cycle Select query for a specific entity.
     */
    public static function createSelect(ORM $orm, string $entity): Select
    {
        return new Select($orm, $entity);
    }

    /**
     * Create a GridSchema with a CursorPaginator.
     */
    public static function createGridSchema(CursorPaginator $paginator): GridSchema
    {
        $schema = new GridSchema();
        $schema->setPaginator($paginator);
        return $schema;
    }
}
