<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Tests\Unit\CursorEncoder;

use Cardyo\Spiral\DataGrid\CursorEncoder\EncoderV1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EncoderV1::class)]
final class EncoderV1Test extends TestCase
{
    #[Test]
    public function itShouldDecodeEncodedValue(): void
    {
        $encoder = new EncoderV1();

        $originalData = ['id' => 123, 'timestamp' => '2024-06-01T12:34:56Z'];

        $encoded = $encoder->encode($originalData);
        self::assertIsString($encoded);

        $decoded = $encoder->decode($encoded);
        self::assertEquals($originalData, $decoded);
    }

    #[Test]
    #[DataProvider('fuzzyDataProvider')]
    public function itShouldEncodeUrlSafe(mixed $data): void
    {
        $encoder = new EncoderV1();

        $encoded = $encoder->encode($data);
        self::assertIsString($encoded);
        self::assertDoesNotMatchRegularExpression('/[+=\/]/', $encoded, 'Encoded data should be URL-safe.');
    }

    public static function fuzzyDataProvider(): \Iterator
    {
        yield 'simple array' => [['id' => 1]];
        yield 'array with string' => [['name' => 'John Doe']];
        yield 'nested array' => [['user' => ['id' => 1, 'name' => 'John']]];
        yield 'array with special chars' => [['text' => 'string/with+special=chars']];
        yield 'array with unicode' => [['text' => '特殊字符字符串']];
        yield 'large array' => [['data' => \str_repeat('a', 1000)]];
        yield 'empty array' => [[]];
        yield 'array with null' => [['value' => null]];
        yield 'array with boolean' => [['flag' => true]];
        yield 'array with float' => [['price' => 19.99]];
        yield 'simple string' => ['test'];
        yield 'empty string' => [''];
        yield 'integer' => [123];
        yield 'float' => [45.67];
        yield 'boolean' => [true];
        yield 'null' => [null];
    }

    #[Test]
    #[DataProvider('malformedCursorProvider')]
    public function itShouldReturnNullForMalformedCursors(string $malformedCursor): void
    {
        $encoder = new EncoderV1();

        $decoded = $encoder->decode($malformedCursor);

        self::assertNull($decoded);
    }

    public static function malformedCursorProvider(): \Iterator
    {
        yield 'invalid base64 - exclamation mark' => ['YWJj!'];
        yield 'invalid base64 - hash' => ['abc#def'];
        yield 'invalid base64 - space' => ['abc def'];
        yield 'invalid base64 - multiple invalids' => ['!!!invalid!!!'];
        yield 'no prefix' => ['dGVzdC1kYXRh']; // 'test-data' in base64, but no cursor:v1: prefix
        yield 'empty string' => [''];
        yield 'just prefix' => [\base64_encode(EncoderV1::PREFIX)];
        yield 'invalid JSON after prefix' => [\base64_encode(EncoderV1::PREFIX . '{invalid json}')];
    }

    #[Test]
    public function itShouldHandleComplexCursorData(): void
    {
        $encoder = new EncoderV1();

        $complexData = [
            'sort_values' => [
                'created_at' => '2024-01-15 10:30:00',
                'id' => 12345,
            ],
            'direction' => 'forward',
            'version' => 'v1',
        ];

        $encoded = $encoder->encode($complexData);
        $decoded = $encoder->decode($encoded);

        self::assertEquals($complexData, $decoded);
    }

    #[Test]
    public function itShouldProduceConsistentEncodings(): void
    {
        $encoder = new EncoderV1();

        $data = ['id' => 100, 'name' => 'test'];

        $encoded1 = $encoder->encode($data);
        $encoded2 = $encoder->encode($data);

        self::assertSame($encoded1, $encoded2);
    }
}
