<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Unit\CursorEncoder;

use Cardyo\SpiralCursorPagination\Cursor\CursorData;
use Cardyo\SpiralCursorPagination\CursorEncoder\EncoderV2;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EncoderV2Test extends TestCase
{
    private EncoderV2 $encoder;

    protected function setUp(): void
    {
        $this->encoder = new EncoderV2();
    }

    public function testEncodeCursorDataWithSingleField(): void
    {
        $cursorData = new CursorData(['id' => 123]);
        $encoded = $this->encoder->encodeCursor($cursorData);

        $this->assertIsString($encoded);
        $this->assertNotEmpty($encoded);
        // Should be URL-safe (no +, /, or =)
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
    }

    public function testEncodeCursorDataWithMultipleFields(): void
    {
        $cursorData = new CursorData(['created_at' => '2024-01-01', 'id' => 42]);
        $encoded = $this->encoder->encodeCursor($cursorData);

        $this->assertIsString($encoded);
        $this->assertNotEmpty($encoded);
    }

    public function testEncodeStringForBackwardCompatibility(): void
    {
        $encoded = $this->encoder->encodeCursor('simple-cursor-value');

        $this->assertIsString($encoded);
        $this->assertNotEmpty($encoded);
    }

    public function testEncodeThrowsOnInvalidType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cursor must be CursorData or string');

        $this->encoder->encodeCursor(123);
    }

    public function testDecodeCursorDataWithSingleField(): void
    {
        $original = new CursorData(['id' => 999]);
        $encoded = $this->encoder->encodeCursor($original);
        $decoded = $this->encoder->decodeCursor($encoded);

        $this->assertInstanceOf(CursorData::class, $decoded);
        $this->assertSame(999, $decoded->get('id'));
    }

    public function testDecodeCursorDataWithMultipleFields(): void
    {
        $original = new CursorData(['created_at' => '2024-12-25 12:00:00', 'id' => 500, 'score' => 95.5]);
        $encoded = $this->encoder->encodeCursor($original);
        $decoded = $this->encoder->decodeCursor($encoded);

        $this->assertInstanceOf(CursorData::class, $decoded);
        $this->assertEquals($original->toArray(), $decoded->toArray());
    }

    public function testDecodeStringForBackwardCompatibility(): void
    {
        $encoded = $this->encoder->encodeCursor('my-cursor-value');
        $decoded = $this->encoder->decodeCursor($encoded);

        $this->assertSame('my-cursor-value', $decoded);
    }

    public function testDecodeReturnsNullForInvalidCursor(): void
    {
        $result = $this->encoder->decodeCursor('invalid-base64!!!');

        $this->assertNull($result);
    }

    public function testDecodeReturnsNullForMissingPrefix(): void
    {
        // Valid base64 but missing cursor:v2: prefix
        $invalidCursor = base64_encode('no-prefix-here');
        $result = $this->encoder->decodeCursor($invalidCursor);

        $this->assertNull($result);
    }

    public function testRoundTripPreservesData(): void
    {
        $testCases = [
            new CursorData(['id' => 1]),
            new CursorData(['created_at' => '2024-01-01', 'id' => 123]),
            new CursorData(['a' => 'value', 'b' => 42, 'c' => null, 'd' => 3.14]),
        ];

        foreach ($testCases as $original) {
            $encoded = $this->encoder->encodeCursor($original);
            $decoded = $this->encoder->decodeCursor($encoded);

            $this->assertInstanceOf(CursorData::class, $decoded);
            $this->assertEquals($original->toArray(), $decoded->toArray());
        }
    }

    public function testEncodedCursorIsUrlSafe(): void
    {
        $cursorData = new CursorData(['id' => 999, 'name' => 'Test+User/Name=']);
        $encoded = $this->encoder->encodeCursor($cursorData);

        // Should not contain URL-unsafe characters
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);

        // Should decode correctly
        $decoded = $this->encoder->decodeCursor($encoded);
        $this->assertInstanceOf(CursorData::class, $decoded);
        $this->assertSame('Test+User/Name=', $decoded->get('name'));
    }
}
