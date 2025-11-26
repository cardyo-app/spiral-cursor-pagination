<?php

namespace Cardyo\SpiralCursorPagination\CursorEncoder;

interface CursorEncoderInterface
{
    public function encodeCursor(mixed $cursor): string;
}