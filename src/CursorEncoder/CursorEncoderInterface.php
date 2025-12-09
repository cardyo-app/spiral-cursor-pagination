<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\CursorEncoder;

use Cardyo\SpiralCursorPagination\Cursor\CursorDataInterface;

interface CursorEncoderInterface
{
    public function encodeCursor(CursorDataInterface $cursor): string;
}
