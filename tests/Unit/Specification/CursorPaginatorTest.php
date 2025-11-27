<?php

declare(strict_types=1);

namespace Specification;

use Cardyo\SpiralCursorPagination\Specification\Cursor\CursorLimit;
use Cardyo\SpiralCursorPagination\Specification\Cursor\SortDirection;
use Iterator;
use Spiral\DataGrid\Specification\Value\IntValue;
use Spiral\DataGrid\Specification\Value\RangeValue\Boundary;
use Cardyo\SpiralCursorPagination\Specification\CursorPaginator;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\DummyCursorCoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spiral\DataGrid\Specification\Value\RangeValue;

#[CoversClass(CursorPaginator::class)]
final class CursorPaginatorTest extends TestCase
{
    #[Test]
    #[DataProvider('validGetValueProvider')]
    public function itShouldReturnCorrectValue(
        CursorPaginator $paginator,
        array $value,
        array $expected,
    ): void {
        $this->assertSame($expected, $paginator->withValue($value)->getValue());
    }

    public static function validGetValueProvider(): Iterator
    {
        $cursorCoder = new DummyCursorCoder();

        $paginator = new CursorPaginator(
            defaultLimit: 10,
            limitValue: new RangeValue(
                new IntValue(),
                Boundary::including(1),
                Boundary::including(50),
            ),
            cursorCoder: $cursorCoder,
        );

        yield 'valid first & after' => [
            $paginator,
            ['first' => 20, 'after' => $cursorCoder->encodeCursor('cursor123')],
            ['first' => 20, 'after' => $cursorCoder->encodeCursor('cursor123')],
        ];
        yield 'valid first' => [
            $paginator,
            ['first' => 10],
            ['first' => 10],
        ];
        yield 'valid first - boundary min' => [
            $paginator,
            ['first' => 1],
            ['first' => 1],
        ];
        yield 'valid first - boundary max' => [
            $paginator,
            ['first' => 50],
            ['first' => 50],
        ];
        yield 'valid last & before' => [
            $paginator,
            ['last' => 20, 'before' => $cursorCoder->encodeCursor('cursor456')],
            ['last' => 20, 'before' => $cursorCoder->encodeCursor('cursor456')],
        ];
        yield 'valid last' => [
            $paginator,
            ['last' => 10],
            ['last' => 10],
        ];
        yield 'valid last - boundary min' => [
            $paginator,
            ['last' => 1],
            ['last' => 1],
        ];
        yield 'valid last - boundary max' => [
            $paginator,
            ['last' => 50],
            ['last' => 50],
        ];

        yield 'no values' => [
            $paginator,
            [],
            ['first' => 10],
        ];

        yield 'only after cursor - defaults to first' => [
            $paginator,
            ['after' => $cursorCoder->encodeCursor('cursor789')],
            ['first' => 10, 'after' => $cursorCoder->encodeCursor('cursor789')],
        ];

        yield 'only before cursor - defaults to last' => [
            $paginator,
            ['before' => $cursorCoder->encodeCursor('cursor789')],
            ['last' => 10, 'before' => $cursorCoder->encodeCursor('cursor789')],
        ];

        yield 'out of bounds - first - too low' => [
            $paginator,
            ['first' => 0],
            ['first' => 10],
        ];

        yield 'out of bounds - first - too high' => [
            $paginator,
            ['first' => 51],
            ['first' => 10],
        ];

        yield 'out of bounds - first - negative' => [
            $paginator,
            ['first' => -5],
            ['first' => 10],
        ];

        yield 'out of bounds - last - too low' => [
            $paginator,
            ['last' => 0],
            ['first' => 10],
        ];

        yield 'out of bounds - last - too high' => [
            $paginator,
            ['last' => 100],
            ['first' => 10],
        ];

        yield 'out of bounds - last - negative' => [
            $paginator,
            ['last' => -10],
            ['first' => 10],
        ];

        yield 'invalid combination - first and last' => [
            $paginator,
            ['first' => 20, 'last' => 20],
            ['first' => 10],
        ];

        yield 'invalid combination - after and before' => [
            $paginator,
            ['after' => $cursorCoder->encodeCursor('c1'), 'before' => $cursorCoder->encodeCursor('c2')],
            ['first' => 10],
        ];

        yield 'invalid combination - first and before' => [
            $paginator,
            ['first' => 20, 'before' => $cursorCoder->encodeCursor('cursor')],
            ['first' => 10],
        ];

        yield 'invalid combination - last and after' => [
            $paginator,
            ['last' => 20, 'after' => $cursorCoder->encodeCursor('cursor')],
            ['first' => 10],
        ];

        yield 'out of bounds first with valid after cursor' => [
            $paginator,
            ['first' => 100, 'after' => $cursorCoder->encodeCursor('cursor')],
            ['first' => 10, 'after' => $cursorCoder->encodeCursor('cursor')],
        ];

        yield 'out of bounds last with valid before cursor' => [
            $paginator,
            ['last' => 0, 'before' => $cursorCoder->encodeCursor('cursor')],
            ['last' => 10, 'before' => $cursorCoder->encodeCursor('cursor')],
        ];
    }

    #[Test]
    #[DataProvider('nonArrayValueProvider')]
    public function itShouldReturnUnmodifiedPaginatorForNonArrayValue(mixed $value): void
    {
        $cursorCoder = new DummyCursorCoder();

        $paginator = new CursorPaginator(
            defaultLimit: 10,
            limitValue: new RangeValue(
                new IntValue(),
                Boundary::including(1),
                Boundary::including(50),
            ),
            cursorCoder: $cursorCoder,
        );

        $result = $paginator->withValue($value);

        $this->assertInstanceOf(CursorPaginator::class, $result);
        $this->assertNotSame($paginator, $result);

        $this->assertSame($paginator->getValue(), $result->getValue());
    }

    public static function nonArrayValueProvider(): Iterator
    {
        yield 'string value' => ['invalid'];
        yield 'integer value' => [42];
        yield 'null value' => [null];
        yield 'boolean value' => [true];
        yield 'float value' => [3.14];
    }

    #[Test]
    public function itShouldReturnEmptySpecifications(): void
    {
        $cursorCoder = new DummyCursorCoder();

        $paginator = new CursorPaginator(
            defaultLimit: 10,
            limitValue: new RangeValue(
                new IntValue(),
                Boundary::including(1),
                Boundary::including(50),
            ),
            cursorCoder: $cursorCoder,
        );

        // Now returns CursorLimit and SortDirection specifications
        $specs = $paginator->getSpecifications();
        $this->assertCount(2, $specs);
        $this->assertInstanceOf(CursorLimit::class, $specs[0]);
        $this->assertInstanceOf(SortDirection::class, $specs[1]);
    }

    #[Test]
    public function itShouldClonePaginatorWhenCallingWithValue(): void
    {
        $cursorCoder = new DummyCursorCoder();

        $paginator = new CursorPaginator(
            defaultLimit: 10,
            limitValue: new RangeValue(
                new IntValue(),
                Boundary::including(1),
                Boundary::including(50),
            ),
            cursorCoder: $cursorCoder,
        );

        $newPaginator = $paginator->withValue(['first' => 20]);

        $this->assertNotSame($paginator, $newPaginator);
    }
}
