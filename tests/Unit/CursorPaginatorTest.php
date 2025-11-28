<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Tests\Unit;

use Cardyo\Spiral\DataGrid\Cursor\Direction;
use Cardyo\Spiral\DataGrid\CursorEncoder\EncoderV1;
use Cardyo\Spiral\DataGrid\CursorPaginator;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorAfter;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorBefore;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorDirection;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorLimit;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorSort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CursorPaginator::class)]
final class CursorPaginatorTest extends TestCase
{
    private EncoderV1 $encoder;

    protected function setUp(): void
    {
        $this->encoder = new EncoderV1();
    }

    #[Test]
    public function itShouldCreateWithDefaults(): void
    {
        $paginator = new CursorPaginator(
            decoder: $this->encoder,
            sortFields: ['id' => 'asc'],
            uniqueField: 'id'
        );

        self::assertInstanceOf(CursorPaginator::class, $paginator);
    }

    #[Test]
    public function itShouldProcessForwardPaginationWithJsonApiFormat(): void
    {
        $paginator = new CursorPaginator(
            decoder: $this->encoder,
            defaultLimit: 10,
            sortFields: ['id' => 'asc'],
            uniqueField: 'id'
        );

        $result = $paginator->withValue(['size' => 25]);

        self::assertNotNull($result);
        self::assertInstanceOf(CursorPaginator::class, $result);

        $specs = $result->getSpecifications();
        self::assertCount(3, $specs); // Limit, Direction, Sort

        // Find specifications
        $limit = $this->findSpecification($specs, CursorLimit::class);
        $direction = $this->findSpecification($specs, CursorDirection::class);
        $sort = $this->findSpecification($specs, CursorSort::class);

        self::assertInstanceOf(CursorLimit::class, $limit);
        self::assertSame(25, $limit->limit);

        self::assertInstanceOf(CursorDirection::class, $direction);
        self::assertTrue($direction->isForward());

        self::assertInstanceOf(CursorSort::class, $sort);
    }

    #[Test]
    public function itShouldProcessBackwardPaginationWithGraphQLFormat(): void
    {
        $paginator = new CursorPaginator(
            decoder: $this->encoder,
            defaultLimit: 10,
            sortFields: ['id' => 'asc'],
            uniqueField: 'id'
        );

        $result = $paginator->withValue(['last' => 15]);

        self::assertNotNull($result);

        $specs = $result->getSpecifications();

        $limit = $this->findSpecification($specs, CursorLimit::class);
        $direction = $this->findSpecification($specs, CursorDirection::class);

        self::assertInstanceOf(CursorLimit::class, $limit);
        self::assertSame(15, $limit->limit);

        self::assertInstanceOf(CursorDirection::class, $direction);
        self::assertTrue($direction->isBackward());
    }

    #[Test]
    public function itShouldProcessCursorAfter(): void
    {
        $paginator = new CursorPaginator(
            decoder: $this->encoder,
            defaultLimit: 10,
            sortFields: ['id' => 'asc'],
            uniqueField: 'id'
        );

        $cursorData = ['sort_values' => ['id' => 100], 'direction' => 'forward', 'version' => 'v1'];
        $cursor = $this->encoder->encode($cursorData);

        $result = $paginator->withValue(['first' => 10, 'after' => $cursor]);

        self::assertNotNull($result);

        $specs = $result->getSpecifications();
        $cursorAfter = $this->findSpecification($specs, CursorAfter::class);

        self::assertInstanceOf(CursorAfter::class, $cursorAfter);
        self::assertSame(100, $cursorAfter->cursor->getSortValue('id'));
    }

    #[Test]
    public function itShouldProcessCursorBefore(): void
    {
        $paginator = new CursorPaginator(
            decoder: $this->encoder,
            defaultLimit: 10,
            sortFields: ['id' => 'asc'],
            uniqueField: 'id'
        );

        $cursorData = ['sort_values' => ['id' => 50], 'direction' => 'backward', 'version' => 'v1'];
        $cursor = $this->encoder->encode($cursorData);

        $result = $paginator->withValue(['last' => 10, 'before' => $cursor]);

        self::assertNotNull($result);

        $specs = $result->getSpecifications();
        $cursorBefore = $this->findSpecification($specs, CursorBefore::class);

        self::assertInstanceOf(CursorBefore::class, $cursorBefore);
        self::assertSame(50, $cursorBefore->cursor->getSortValue('id'));
    }

    #[Test]
    public function itShouldEnforceMaxLimit(): void
    {
        $paginator = new CursorPaginator(
            decoder: $this->encoder,
            defaultLimit: 10,
            maxLimit: 50,
            sortFields: ['id' => 'asc'],
            uniqueField: 'id'
        );

        $result = $paginator->withValue(['size' => 100]);

        self::assertNotNull($result);

        $specs = $result->getSpecifications();
        $limit = $this->findSpecification($specs, CursorLimit::class);

        self::assertInstanceOf(CursorLimit::class, $limit);
        self::assertSame(50, $limit->limit); // Capped at maxLimit
    }

    #[Test]
    public function itShouldEnforceAllowedLimits(): void
    {
        $paginator = new CursorPaginator(
            decoder: $this->encoder,
            defaultLimit: 10,
            allowedLimits: [10, 25, 50],
            sortFields: ['id' => 'asc'],
            uniqueField: 'id'
        );

        $result = $paginator->withValue(['size' => 30]); // Not in allowed list

        self::assertNotNull($result);

        $specs = $result->getSpecifications();
        $limit = $this->findSpecification($specs, CursorLimit::class);

        self::assertInstanceOf(CursorLimit::class, $limit);
        self::assertSame(10, $limit->limit); // Falls back to default
    }

    #[Test]
    public function itShouldReturnNullForInvalidValue(): void
    {
        $paginator = new CursorPaginator(
            decoder: $this->encoder,
            sortFields: ['id' => 'asc'],
            uniqueField: 'id'
        );

        $result = $paginator->withValue('invalid');

        self::assertNull($result);
    }

    #[Test]
    public function itShouldThrowExceptionForInvalidDefaultLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Default limit must be a positive integer');

        new CursorPaginator(
            decoder: $this->encoder,
            defaultLimit: 0,
            sortFields: ['id' => 'asc'],
            uniqueField: 'id'
        );
    }

    #[Test]
    public function itShouldThrowExceptionWhenDefaultLimitNotInAllowedLimits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Default limit must be in the list of allowed limits');

        new CursorPaginator(
            decoder: $this->encoder,
            defaultLimit: 10,
            allowedLimits: [25, 50, 100],
            sortFields: ['id' => 'asc'],
            uniqueField: 'id'
        );
    }

    #[Test]
    public function itShouldThrowExceptionWhenUniqueFieldNotInSortFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sort fields must include unique field "id"');

        new CursorPaginator(
            decoder: $this->encoder,
            sortFields: ['name' => 'asc'],
            uniqueField: 'id'
        );
    }

    private function findSpecification(array $specs, string $class): ?object
    {
        foreach ($specs as $spec) {
            if ($spec instanceof $class) {
                return $spec;
            }
        }
        return null;
    }
}
