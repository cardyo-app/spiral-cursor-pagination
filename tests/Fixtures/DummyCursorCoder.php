<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Fixtures;

use Cardyo\SpiralCursorPagination\CursorEncoder\CursorDecoderInterface;
use Cardyo\SpiralCursorPagination\CursorEncoder\CursorEncoderInterface;

final class DummyCursorCoder implements CursorDecoderInterface, CursorEncoderInterface
{
    public function encodeCursor(mixed $cursor): string
    {
        if (!is_string($cursor)) {
            throw new \InvalidArgumentException('Cursor must be a string');
        }

        return $cursor;
    }

    public function decodeCursor(string $cursor): string
    {
        return $cursor;
    }
}
