<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\CursorEncoder;

interface CursorEncoderInterface
{
    public function encodeCursor(mixed $cursor): string;
}
