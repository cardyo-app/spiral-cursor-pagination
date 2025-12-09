<?php

namespace Cardyo\Tests\SpiralCursorPagination\Unit\Specification\Pagination;

use Cardyo\SpiralCursorPagination\CursorEncoder\CursorDecoderInterface;
use Cardyo\SpiralCursorPagination\CursorEncoder\CursorEncoderInterface;
use Cardyo\SpiralCursorPagination\Specification\Pagination\CursorPaginator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Spiral\DataGrid\Specification\Value\IntValue;
use Spiral\DataGrid\Specification\Value\RangeValue;
use Spiral\DataGrid\Specification\ValueInterface;

#[CoversClass(CursorPaginator::class)]
class CursorPaginatorTest extends TestCase
{
    #[Test]
    #[TestWith([0])]
    #[TestWith([-1])]
    public function itDoesNotAllowInvalidDefaultLimit(int $defaultLimit): void
    {
        $limitValue = $this->createMock(ValueInterface::class);

        /**
         * @var CursorEncoderInterface&CursorDecoderInterface&MockObject $cursorCoder
         */
        $cursorCoder = $this->createMockForIntersectionOfInterfaces([
            CursorEncoderInterface::class,
            CursorDecoderInterface::class,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        new CursorPaginator(
            defaultLimit: $defaultLimit,
            limitValue: $limitValue,
            cursorCoder: $cursorCoder,
        );
    }

    #[Test]
    public function itDoesNotAllowNotAcceptedDefaultLimit(): void
    {
        $limitValue = $this->createMock(ValueInterface::class);
        $limitValue->method('accepts')->willReturn(false);

        /**
         * @var CursorEncoderInterface&CursorDecoderInterface&MockObject $cursorCoder
         */
        $cursorCoder = $this->createMockForIntersectionOfInterfaces([
            CursorEncoderInterface::class,
            CursorDecoderInterface::class,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        new CursorPaginator(
            defaultLimit: 10,
            limitValue: $limitValue,
            cursorCoder: $cursorCoder,
        );
    }

    #[Test]
    #[DataProvider('provideLimitValues')]
    public function itParsesLimitValues(array $value, array $expected): void
    {
        $limitValue = $this->createMock(ValueInterface::class);
        $limitValue->method('accepts')->willReturn(true);
        $limitValue->method('convert')->willReturnCallback(static fn (mixed $value): int => (int)$value);

        /**
         * @var CursorEncoderInterface&CursorDecoderInterface&MockObject $cursorCoder
         */
        $cursorCoder = $this->createMockForIntersectionOfInterfaces([
            CursorEncoderInterface::class,
            CursorDecoderInterface::class,
        ]);

        $cursorPaginator = new CursorPaginator(
            defaultLimit: 10,
            limitValue: $limitValue,
            cursorCoder: $cursorCoder,
        );

        $cursorPaginator = $cursorPaginator->withValue($value);

        $this->assertEqualsCanonicalizing($expected, $cursorPaginator->getValue());
    }

    public static function provideLimitValues(): iterable
    {
        yield [
            'input' => [
                'first' => 5,
            ],
            'expected' => [
                'size' => 5,
            ],
        ];

        yield [
            'input' => [
                'last' => 3,
            ],
            'expected' => [
                'size' => 3,
            ],
        ];

        yield [
            'input' => [
                'size' => 7,
            ],
            'expected' => [
                'size' => 7,
            ],
        ];

        yield [
            'input' => [],
            'expected' => [
                'size' => 10,
            ],
        ];
    }

    #[Test]
    #[DataProvider('provideInvalidValues')]
    public function itDoesNotAcceptInvalidValues(array $values, string $expectedException): void
    {
        /**
         * @var CursorEncoderInterface&CursorDecoderInterface&MockObject $cursorCoder
         */
        $cursorCoder = $this->createMockForIntersectionOfInterfaces([
            CursorEncoderInterface::class,
            CursorDecoderInterface::class,
        ]);

        $cursorPaginator = new CursorPaginator(
            defaultLimit: 10,
            limitValue: new RangeValue(
                new IntValue(),
                RangeValue\Boundary::including(1),
                RangeValue\Boundary::including(100),
            ),
            cursorCoder: $cursorCoder,
        );

        $this->expectException($expectedException);

        $cursorPaginator->withValue($values);
    }

    public static function provideInvalidValues(): iterable
    {
        foreach (['size', 'first', 'last'] as $key) {
            yield sprintf("non-number %s", $key) => [
                'values' => [
                    $key => 'invalid',
                ],
                'expectedException' => \InvalidArgumentException::class,
            ];

            yield sprintf("negative %s", $key) => [
                'values' => [
                    $key => -5,
                ],
                'expectedException' => \InvalidArgumentException::class,
            ];

            yield sprintf("zero %s", $key) => [
                'values' => [
                    $key => 0,
                ],
                'expectedException' => \InvalidArgumentException::class,
            ];

            yield sprintf("exceeding max %s", $key) => [
                'values' => [
                    $key => 150,
                ],
                'expectedException' => \InvalidArgumentException::class,
            ];
        }

        yield 'both first and last' => [
            'values' => [
                'first' => 5,
                'last' => 3,
            ],
            'expectedException' => \InvalidArgumentException::class,
        ];

        yield 'both first and size' => [
            'values' => [
                'first' => 5,
                'size' => 10,
            ],
            'expectedException' => \InvalidArgumentException::class,
        ];

        yield 'both last and size' => [
            'values' => [
                'last' => 3,
                'size' => 10,
            ],
            'expectedException' => \InvalidArgumentException::class,
        ];
    }
}
