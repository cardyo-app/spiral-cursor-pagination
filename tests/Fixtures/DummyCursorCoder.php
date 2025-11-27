<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Fixtures;

use Cardyo\SpiralCursorPagination\Cursor\CursorData;
use Cardyo\SpiralCursorPagination\CursorEncoder\CursorDecoderInterface;
use Cardyo\SpiralCursorPagination\CursorEncoder\CursorEncoderInterface;

final class DummyCursorCoder implements CursorDecoderInterface, CursorEncoderInterface
{
    public function encodeCursor(mixed $cursor): string
    {
        // Accept both string and CursorData for test convenience
        if ($cursor instanceof CursorData) {
            // Encode CursorData to a simple string representation
            return 'cursor:' . json_encode($cursor->toArray());
        }

        if (!is_string($cursor)) {
            throw new \InvalidArgumentException('Cursor must be a string or CursorData');
        }

        return $cursor;
    }

    public function decodeCursor(string $cursor): CursorData
    {
        // Decode string cursor to CursorData
        if (str_starts_with($cursor, 'cursor:')) {
            $json = substr($cursor, 7);
            $data = json_decode($json, true);
            return new CursorData($data ?? []);
        }

        // For simple test strings without prefix, create a simple CursorData
        return new CursorData(['cursor' => $cursor]);
    }
}
