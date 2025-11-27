<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Unit\Cursor;

use Cardyo\SpiralCursorPagination\Cursor\CursorData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CursorDataTest extends TestCase
{
    public function testConstructorAcceptsValidData(): void
    {
        $data = new CursorData(['id' => 123, 'created_at' => '2024-01-01']);

        $this->assertSame(123, $data->get('id'));
        $this->assertSame('2024-01-01', $data->get('created_at'));
    }

    public function testConstructorThrowsOnEmptyData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CursorData cannot be empty');

        new CursorData([]);
    }

    public function testGetReturnsFieldValue(): void
    {
        $data = new CursorData(['name' => 'John', 'age' => 30]);

        $this->assertSame('John', $data->get('name'));
        $this->assertSame(30, $data->get('age'));
    }

    public function testGetThrowsOnNonExistentField(): void
    {
        $data = new CursorData(['id' => 1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "name" does not exist');

        $data->get('name');
    }

    public function testHasReturnsTrueForExistingField(): void
    {
        $data = new CursorData(['id' => 1, 'name' => 'John']);

        $this->assertTrue($data->has('id'));
        $this->assertTrue($data->has('name'));
    }

    public function testHasReturnsFalseForNonExistentField(): void
    {
        $data = new CursorData(['id' => 1]);

        $this->assertFalse($data->has('name'));
        $this->assertFalse($data->has('age'));
    }

    public function testToArrayReturnsAllFields(): void
    {
        $fields = ['id' => 123, 'created_at' => '2024-01-01', 'score' => 99.5];
        $data = new CursorData($fields);

        $this->assertSame($fields, $data->toArray());
    }

    public function testGetFieldsReturnsFieldNames(): void
    {
        $data = new CursorData(['id' => 1, 'name' => 'John', 'age' => 30]);

        $this->assertSame(['id', 'name', 'age'], $data->getFields());
    }

    public function testSupportsNullValues(): void
    {
        $data = new CursorData(['id' => 1, 'deleted_at' => null]);

        $this->assertTrue($data->has('deleted_at'));
        $this->assertNull($data->get('deleted_at'));
    }
}
