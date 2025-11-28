<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Tests\Unit\Cursor;

use Cardyo\Spiral\DataGrid\Cursor\CursorData;
use Cardyo\Spiral\DataGrid\Cursor\Direction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CursorData::class)]
final class CursorDataTest extends TestCase
{
    #[Test]
    public function itShouldCreateCursorDataWithDefaults(): void
    {
        $sortValues = ['id' => 123, 'created_at' => '2024-01-15'];
        $cursor = new CursorData($sortValues);

        self::assertSame($sortValues, $cursor->sortValues);
        self::assertSame(Direction::FORWARD, $cursor->direction);
        self::assertSame('v1', $cursor->version);
    }

    #[Test]
    public function itShouldCreateCursorDataWithCustomValues(): void
    {
        $sortValues = ['id' => 456];
        $cursor = new CursorData(
            sortValues: $sortValues,
            direction: Direction::BACKWARD,
            version: 'v2'
        );

        self::assertSame($sortValues, $cursor->sortValues);
        self::assertSame(Direction::BACKWARD, $cursor->direction);
        self::assertSame('v2', $cursor->version);
    }

    #[Test]
    public function itShouldCreateFromArray(): void
    {
        $data = [
            'sort_values' => ['id' => 789, 'name' => 'test'],
            'direction' => 'backward',
            'version' => 'v2',
        ];

        $cursor = CursorData::fromArray($data);

        self::assertSame(['id' => 789, 'name' => 'test'], $cursor->sortValues);
        self::assertSame(Direction::BACKWARD, $cursor->direction);
        self::assertSame('v2', $cursor->version);
    }

    #[Test]
    public function itShouldCreateFromArrayWithDefaults(): void
    {
        $data = [
            'sort_values' => ['id' => 100],
        ];

        $cursor = CursorData::fromArray($data);

        self::assertSame(['id' => 100], $cursor->sortValues);
        self::assertSame(Direction::FORWARD, $cursor->direction);
        self::assertSame('v1', $cursor->version);
    }

    #[Test]
    public function itShouldConvertToArray(): void
    {
        $cursor = new CursorData(
            sortValues: ['id' => 123, 'timestamp' => '2024-01-15'],
            direction: Direction::BACKWARD,
            version: 'v1'
        );

        $array = $cursor->toArray();

        self::assertSame([
            'sort_values' => ['id' => 123, 'timestamp' => '2024-01-15'],
            'direction' => 'backward',
            'version' => 'v1',
        ], $array);
    }

    #[Test]
    public function itShouldGetSortValue(): void
    {
        $cursor = new CursorData(['id' => 123, 'name' => 'John']);

        self::assertSame(123, $cursor->getSortValue('id'));
        self::assertSame('John', $cursor->getSortValue('name'));
        self::assertNull($cursor->getSortValue('nonexistent'));
    }

    #[Test]
    public function itShouldCheckIfSortValueExists(): void
    {
        $cursor = new CursorData(['id' => 123, 'name' => 'John']);

        self::assertTrue($cursor->hasSortValue('id'));
        self::assertTrue($cursor->hasSortValue('name'));
        self::assertFalse($cursor->hasSortValue('nonexistent'));
    }

    #[Test]
    public function itShouldGetSortFields(): void
    {
        $cursor = new CursorData(['id' => 123, 'created_at' => '2024-01-15', 'name' => 'Test']);

        self::assertSame(['id', 'created_at', 'name'], $cursor->getSortFields());
    }

    #[Test]
    public function itShouldHandleEmptySortValues(): void
    {
        $cursor = new CursorData([]);

        self::assertSame([], $cursor->sortValues);
        self::assertSame([], $cursor->getSortFields());
        self::assertNull($cursor->getSortValue('any'));
        self::assertFalse($cursor->hasSortValue('any'));
    }
}
