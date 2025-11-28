<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Tests\Unit\Cursor;

use Cardyo\Spiral\DataGrid\Cursor\PageInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PageInfo::class)]
final class PageInfoTest extends TestCase
{
    #[Test]
    public function itShouldCreatePageInfoWithAllParameters(): void
    {
        $pageInfo = new PageInfo(
            hasNextPage: true,
            hasPreviousPage: true,
            startCursor: 'start_cursor',
            endCursor: 'end_cursor',
            totalCount: 1000
        );

        self::assertTrue($pageInfo->hasNextPage);
        self::assertTrue($pageInfo->hasPreviousPage);
        self::assertSame('start_cursor', $pageInfo->startCursor);
        self::assertSame('end_cursor', $pageInfo->endCursor);
        self::assertSame(1000, $pageInfo->totalCount);
    }

    #[Test]
    public function itShouldCreatePageInfoWithMinimalParameters(): void
    {
        $pageInfo = new PageInfo(
            hasNextPage: false,
            hasPreviousPage: false
        );

        self::assertFalse($pageInfo->hasNextPage);
        self::assertFalse($pageInfo->hasPreviousPage);
        self::assertNull($pageInfo->startCursor);
        self::assertNull($pageInfo->endCursor);
        self::assertNull($pageInfo->totalCount);
    }

    #[Test]
    public function itShouldCreateEmptyPageInfo(): void
    {
        $pageInfo = PageInfo::empty();

        self::assertFalse($pageInfo->hasNextPage);
        self::assertFalse($pageInfo->hasPreviousPage);
        self::assertNull($pageInfo->startCursor);
        self::assertNull($pageInfo->endCursor);
        self::assertSame(0, $pageInfo->totalCount);
        self::assertTrue($pageInfo->isEmpty());
    }

    #[Test]
    public function itShouldDetectEmptyPage(): void
    {
        $emptyPageInfo = new PageInfo(
            hasNextPage: false,
            hasPreviousPage: false
        );

        self::assertTrue($emptyPageInfo->isEmpty());

        $nonEmptyPageInfo = new PageInfo(
            hasNextPage: true,
            hasPreviousPage: false,
            startCursor: 'cursor',
            endCursor: 'cursor'
        );

        self::assertFalse($nonEmptyPageInfo->isEmpty());
    }

    #[Test]
    public function itShouldConvertToJsonApiArray(): void
    {
        $pageInfo = new PageInfo(
            hasNextPage: true,
            hasPreviousPage: false,
            startCursor: 'start',
            endCursor: 'end',
            totalCount: 500
        );

        $array = $pageInfo->toJsonApiArray();

        self::assertSame([
            'hasNext' => true,
            'hasPrevious' => false,
            'startCursor' => 'start',
            'endCursor' => 'end',
            'totalCount' => 500,
        ], $array);
    }

    #[Test]
    public function itShouldConvertToJsonApiArrayWithoutOptionalFields(): void
    {
        $pageInfo = new PageInfo(
            hasNextPage: false,
            hasPreviousPage: true
        );

        $array = $pageInfo->toJsonApiArray();

        self::assertSame([
            'hasNext' => false,
            'hasPrevious' => true,
        ], $array);

        self::assertArrayNotHasKey('startCursor', $array);
        self::assertArrayNotHasKey('endCursor', $array);
        self::assertArrayNotHasKey('totalCount', $array);
    }

    #[Test]
    public function itShouldConvertToGraphQLArray(): void
    {
        $pageInfo = new PageInfo(
            hasNextPage: true,
            hasPreviousPage: false,
            startCursor: 'start',
            endCursor: 'end',
            totalCount: 500
        );

        $array = $pageInfo->toGraphQLArray();

        self::assertSame([
            'hasNextPage' => true,
            'hasPreviousPage' => false,
            'startCursor' => 'start',
            'endCursor' => 'end',
            'totalCount' => 500,
        ], $array);
    }

    #[Test]
    public function itShouldConvertToGraphQLArrayWithNullCursors(): void
    {
        $pageInfo = new PageInfo(
            hasNextPage: false,
            hasPreviousPage: true
        );

        $array = $pageInfo->toGraphQLArray();

        self::assertSame([
            'hasNextPage' => false,
            'hasPreviousPage' => true,
            'startCursor' => null,
            'endCursor' => null,
        ], $array);

        self::assertArrayHasKey('startCursor', $array);
        self::assertArrayHasKey('endCursor', $array);
        self::assertArrayNotHasKey('totalCount', $array);
    }
}
