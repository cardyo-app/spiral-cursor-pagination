<?php

namespace Cardyo\Tests\SpiralCursorPagination\Unit\CursorEncoder;

use Cardyo\SpiralCursorPagination\CursorEncoder\EncoderV1;
use PHPUnit\Framework\TestCase;

class EncoderV1Test extends TestCase
{
    public function testEncodeDecode(): void
    {
        $encoder = new EncoderV1();

        $originalData = '2024-06-01T12:34:56Z';

        $encoded = $encoder->encodeCursor($originalData);
        $this->assertIsString($encoded, 'Encoded data should be a string.');

        $decoded = $encoder->decodeCursor($encoded);
        $this->assertEquals($originalData, $decoded, 'Decoded data should match the original data.');
    }
}
