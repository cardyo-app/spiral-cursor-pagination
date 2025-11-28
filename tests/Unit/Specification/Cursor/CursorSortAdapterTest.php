<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Tests\Unit\Specification\Cursor;

use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorSort;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorSortAdapter;
use PHPUnit\Framework\TestCase;
use Spiral\DataGrid\Specification\SorterInterface;
use Spiral\DataGrid\SpecificationInterface;

final class CursorSortAdapterTest extends TestCase
{
    public function testConstructorThrowsExceptionForInvalidUniqueDirection(): void
    {
        $sorter = $this->createMockSorter('field');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unique field direction must be "asc" or "desc"');

        new CursorSortAdapter($sorter, 'id', 'invalid');
    }

    public function testWithDirectionReturnsNullWhenBaseSorterReturnsNull(): void
    {
        // Create a mock that explicitly returns null
        $sorter = $this->createMock(SorterInterface::class);
        $sorter->method('withDirection')->willReturn(null);
        $sorter->method('getValue')->willReturn('field');

        $adapter = new CursorSortAdapter($sorter, 'id', 'asc');
        $result = $adapter->withDirection('asc');

        $this->assertNull($result);
    }

    public function testWithDirectionAppendsUniqueFieldInAscending(): void
    {
        $sorter = $this->createMockSorter('last_active_at');

        $adapter = new CursorSortAdapter($sorter, 'uuid', 'asc');
        $result = $adapter->withDirection('desc');

        $this->assertInstanceOf(CursorSort::class, $result);
        $this->assertEquals([
            'last_active_at' => 'desc',
            'uuid' => 'asc',
        ], $result->fields);
        $this->assertEquals('uuid', $result->getUniqueField());
    }

    public function testWithDirectionAppendsUniqueFieldInDescending(): void
    {
        $sorter = $this->createMockSorter('created_at');

        $adapter = new CursorSortAdapter($sorter, 'id', 'desc');
        $result = $adapter->withDirection('asc');

        $this->assertInstanceOf(CursorSort::class, $result);
        $this->assertEquals([
            'created_at' => 'asc',
            'id' => 'desc',
        ], $result->fields);
        $this->assertEquals('id', $result->getUniqueField());
    }

    public function testWithDirectionDoesNotDuplicateUniqueField(): void
    {
        // Sorter that returns a CursorSort already containing the unique field
        $existingSort = new CursorSort([
            'name' => 'asc',
            'uuid' => 'asc',
        ], 'uuid');

        $sorter = $this->createMock(SorterInterface::class);
        $sorter->method('withDirection')->willReturn($existingSort);

        $adapter = new CursorSortAdapter($sorter, 'uuid', 'asc');
        $result = $adapter->withDirection('asc');

        $this->assertInstanceOf(CursorSort::class, $result);
        $this->assertEquals([
            'name' => 'asc',
            'uuid' => 'asc',
        ], $result->fields);
    }

    public function testGetValueReturnsBaseSorterValue(): void
    {
        $sorter = $this->createMock(SorterInterface::class);
        $sorter->method('getValue')->willReturn('field_name');

        $adapter = new CursorSortAdapter($sorter, 'id', 'asc');

        $this->assertEquals('field_name', $adapter->getValue());
    }

    public function testWithDirectionHandlesIntegerDirection(): void
    {
        $sorter = $this->createMockSorter('score');

        $adapter = new CursorSortAdapter($sorter, 'id', 'asc');

        // Positive integer = ASC
        $result1 = $adapter->withDirection(1);
        $this->assertInstanceOf(CursorSort::class, $result1);
        $this->assertEquals([
            'score' => 'asc',
            'id' => 'asc',
        ], $result1->fields);

        // Negative integer = DESC
        $result2 = $adapter->withDirection(-1);
        $this->assertInstanceOf(CursorSort::class, $result2);
        $this->assertEquals([
            'score' => 'desc',
            'id' => 'asc',
        ], $result2->fields);
    }

    public function testWithDirectionHandlesMultipleFields(): void
    {
        $sorter = $this->createMockSorter(['field1', 'field2']);

        $adapter = new CursorSortAdapter($sorter, 'id', 'asc');
        $result = $adapter->withDirection('desc');

        $this->assertInstanceOf(CursorSort::class, $result);
        $this->assertEquals([
            'field1' => 'desc',
            'field2' => 'desc',
            'id' => 'asc',
        ], $result->fields);
    }

    public function testWithDirectionHandlesEmptyValue(): void
    {
        $sorter = $this->createMockSorter('');

        $adapter = new CursorSortAdapter($sorter, 'id', 'asc');
        $result = $adapter->withDirection('asc');

        $this->assertInstanceOf(CursorSort::class, $result);
        // Only unique field should be present
        $this->assertEquals([
            'id' => 'asc',
        ], $result->fields);
    }

    public function testWithDirectionNormalizesMixedCaseDirection(): void
    {
        $sorter = $this->createMockSorter('name');

        $adapter = new CursorSortAdapter($sorter, 'id', 'ASC');
        $result = $adapter->withDirection('DESC');

        $this->assertInstanceOf(CursorSort::class, $result);
        $this->assertEquals([
            'name' => 'desc',
            'id' => 'asc',
        ], $result->fields);
    }

    /**
     * Real-world scenario: UUID-based customer table with dynamic sorting
     */
    public function testRealWorldScenarioUuidWithLastActiveSorting(): void
    {
        // Simulate user-controlled sorter for "last_active_at"
        $lastActiveSorter = $this->createMockSorter('last_active_at');

        // Wrap with adapter to ensure UUID stability
        $adapter = new CursorSortAdapter(
            sorter: $lastActiveSorter,
            uniqueField: 'uuid',
            uniqueDirection: 'asc'
        );

        // User wants to see recently active users (DESC)
        $recentlyActive = $adapter->withDirection('desc');
        $this->assertInstanceOf(CursorSort::class, $recentlyActive);
        $this->assertEquals([
            'last_active_at' => 'desc',
            'uuid' => 'asc',
        ], $recentlyActive->fields);

        // User wants to see least active users (ASC)
        $leastActive = $adapter->withDirection('asc');
        $this->assertInstanceOf(CursorSort::class, $leastActive);
        $this->assertEquals([
            'last_active_at' => 'asc',
            'uuid' => 'asc',
        ], $leastActive->fields);
    }

    /**
     * Real-world scenario: Multiple sorters on the same schema
     */
    public function testMultipleSortersWithDifferentFields(): void
    {
        $uniqueField = 'uuid';

        // Sorter 1: last_active_at
        $lastActiveSorter = $this->createMockSorter('last_active_at');
        $adapter1 = new CursorSortAdapter($lastActiveSorter, $uniqueField, 'asc');

        // Sorter 2: created_at
        $createdSorter = $this->createMockSorter('created_at');
        $adapter2 = new CursorSortAdapter($createdSorter, $uniqueField, 'asc');

        // Sorter 3: name
        $nameSorter = $this->createMockSorter('name');
        $adapter3 = new CursorSortAdapter($nameSorter, $uniqueField, 'asc');

        $result1 = $adapter1->withDirection('desc');
        $this->assertEquals(['last_active_at' => 'desc', 'uuid' => 'asc'], $result1->fields);

        $result2 = $adapter2->withDirection('asc');
        $this->assertEquals(['created_at' => 'asc', 'uuid' => 'asc'], $result2->fields);

        $result3 = $adapter3->withDirection('asc');
        $this->assertEquals(['name' => 'asc', 'uuid' => 'asc'], $result3->fields);
    }

    /**
     * Create a mock sorter that returns a specification with the given field(s)
     */
    private function createMockSorter(string|array $field, mixed $returnValue = null): SorterInterface
    {
        $sorter = $this->createMock(SorterInterface::class);

        if ($returnValue === null) {
            // Auto-create a mock specification with getValue() returning the field
            $spec = $this->createMock(SpecificationInterface::class);
            $spec->method('getValue')->willReturn($field);
            $returnValue = $spec;
        }

        $sorter->method('withDirection')->willReturn($returnValue);
        $sorter->method('getValue')->willReturn($field);

        return $sorter;
    }
}
