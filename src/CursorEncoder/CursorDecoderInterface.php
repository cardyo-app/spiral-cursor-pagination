<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\CursorEncoder;

use Cardyo\SpiralCursorPagination\Cursor\CursorDataInterface;

interface CursorDecoderInterface
{
    public function decodeCursor(string $cursor): CursorDataInterface;
}
