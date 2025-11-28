<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Tests\Unit\Specification;

use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorSort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CursorSort::class)]
final class CursorSortTest extends TestCase
{
    #[Test]
    public function itShouldCreateWithValidFields(): void
    {
        $fields = ['created_at' => 'desc', 'id' => 'asc'];
        $sort = new CursorSort($fields, 'id');

        self::assertSame($fields, $sort->getValue());
        self::assertSame('id', $sort->getUniqueField());
    }

    #[Test]
    public function itShouldGetFieldNames(): void
    {
        $sort = new CursorSort(['name' => 'asc', 'id' => 'asc'], 'id');

        self::assertSame(['name', 'id'], $sort->getFieldNames());
    }

    #[Test]
    public function itShouldGetDirection(): void
    {
        $sort = new CursorSort(['name' => 'desc', 'id' => 'asc'], 'id');

        self::assertSame('desc', $sort->getDirection('name'));
        self::assertSame('asc', $sort->getDirection('id'));
        self::assertNull($sort->getDirection('nonexistent'));
    }

    #[Test]
    public function itShouldCheckAscending(): void
    {
        $sort = new CursorSort(['name' => 'asc', 'id' => 'desc'], 'id');

        self::assertTrue($sort->isAscending('name'));
        self::assertFalse($sort->isAscending('id'));
        self::assertFalse($sort->isAscending('nonexistent'));
    }

    #[Test]
    public function itShouldCheckDescending(): void
    {
        $sort = new CursorSort(['name' => 'asc', 'id' => 'desc'], 'id');

        self::assertFalse($sort->isDescending('name'));
        self::assertTrue($sort->isDescending('id'));
        self::assertFalse($sort->isDescending('nonexistent'));
    }

    #[Test]
    public function itShouldReverseSortOrder(): void
    {
        $sort = new CursorSort(['created_at' => 'desc', 'id' => 'asc'], 'id');
        $reversed = $sort->reverse();

        self::assertSame(['created_at' => 'asc', 'id' => 'desc'], $reversed->getValue());
        self::assertSame('id', $reversed->getUniqueField());
    }

    #[Test]
    public function itShouldThrowExceptionForEmptyFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sort fields cannot be empty');

        new CursorSort([], 'id');
    }

    #[Test]
    public function itShouldThrowExceptionWhenUniqueFieldNotIncluded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unique field "id" must be included in sort fields');

        new CursorSort(['name' => 'asc'], 'id');
    }

    #[Test]
    public function itShouldThrowExceptionForInvalidDirection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sort direction for field "name" must be "asc" or "desc"');

        new CursorSort(['name' => 'invalid', 'id' => 'asc'], 'id');
    }

    #[Test]
    public function itShouldThrowExceptionForEmptyFieldName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sort field name must be a non-empty string');

        new CursorSort(['' => 'asc', 'id' => 'asc'], 'id');
    }

    #[Test]
    public function itShouldHandleCaseInsensitiveDirections(): void
    {
        $sort = new CursorSort(['name' => 'ASC', 'id' => 'DESC'], 'id');

        self::assertTrue($sort->isAscending('name'));
        self::assertTrue($sort->isDescending('id'));
    }
}
