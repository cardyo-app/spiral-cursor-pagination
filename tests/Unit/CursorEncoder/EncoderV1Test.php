<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Unit\CursorEncoder;

use Cardyo\SpiralCursorPagination\CursorEncoder\EncoderV1;
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

        $originalData = '2024-06-01T12:34:56Z';

        $encoded = $encoder->encodeCursor($originalData);
        $this->assertIsString($encoded, 'Encoded data should be a string.');

        $decoded = $encoder->decodeCursor($encoded);
        $this->assertEquals($originalData, $decoded, 'Decoded data should match the original data.');
    }

    #[Test]
    #[DataProvider('fuzzyDataProvider')]
    public function itShouldEncodeUrlSafe(string $data): void
    {
        $encoder = new EncoderV1();

        $encoded = $encoder->encodeCursor($data);
        $this->assertIsString($encoded, 'Encoded data should be a string.');
        $this->assertDoesNotMatchRegularExpression('/[+=\/]/', $encoded, 'Encoded data should be URL-safe.');
    }

    public static function fuzzyDataProvider(): \Iterator
    {
        yield ['simple-string'];
        yield ['string with spaces'];
        yield ['string_with_underscores'];
        yield ['string-with-dashes'];
        yield ['string/with/slashes'];
        yield ['string+with+pluses'];
        yield ['string=with=equals'];
        yield ['特殊字符字符串']; // String with special characters
        yield [str_repeat('a', 1000)]; // Very long string
        yield [EncoderV1::PREFIX]; // String that matches the prefix
        yield [sprintf('%s%s', EncoderV1::PREFIX, 'data')]; // String that starts with the prefix
        yield [sprintf('%s%s', 'data', EncoderV1::PREFIX)]; // String that ends with the prefix
        yield ['']; // Empty string

        // Base64 padding edge cases (different lengths to test % 4 modulo operation)
        yield ['a']; // Results in different base64 padding
        yield ['ab']; // Results in different base64 padding
        yield ['abc']; // Results in different base64 padding
        yield ['abcd']; // Results in different base64 padding
        yield ['test']; // 4 chars
        yield ['test1']; // 5 chars
        yield ['test12']; // 6 chars
    }

    #[Test]
    public function itShouldHandleCursorWithPrefixInValue(): void
    {
        $encoder = new EncoderV1();

        // Test data that contains the prefix within the actual cursor value
        // This tests that explode with limit=2 works correctly
        $dataWithPrefix = sprintf('%ssome-data', EncoderV1::PREFIX);

        $encoded = $encoder->encodeCursor($dataWithPrefix);
        $decoded = $encoder->decodeCursor($encoded);

        $this->assertEquals($dataWithPrefix, $decoded, 'Should handle cursor values containing the prefix.');
    }

    #[Test]
    #[DataProvider('malformedCursorProvider')]
    public function itShouldReturnNullForMalformedCursors(string $malformedCursor): void
    {
        $encoder = new EncoderV1();

        $decoded = $encoder->decodeCursor($malformedCursor);

        $this->assertNull($decoded, 'Malformed cursor should return null.');
    }

    public static function malformedCursorProvider(): \Iterator
    {
        yield 'invalid base64 - exclamation mark' => ['YWJj!']; // Invalid base64 character
        yield 'invalid base64 - hash' => ['abc#def']; // Invalid base64 character
        yield 'invalid base64 - space' => ['abc def']; // Invalid base64 character
        yield 'invalid base64 - multiple invalids' => ['!!!invalid!!!']; // Multiple invalid characters
        yield 'no prefix' => ['dGVzdC1kYXRh']; // 'test-data' in base64, but no cursor:v1: prefix
        yield 'empty string' => [''];  // Empty cursor (though this might decode successfully)
    }
}
