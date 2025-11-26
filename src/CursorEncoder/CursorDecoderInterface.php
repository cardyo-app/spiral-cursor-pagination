<?php

namespace Cardyo\SpiralCursorPagination\CursorEncoder;

interface CursorDecoderInterface
{
    public function decodeCursor(string $cursor): mixed;
}