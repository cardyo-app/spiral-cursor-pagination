<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\CursorEncoder;

interface CursorDecoderInterface
{
    public function decodeCursor(string $cursor): mixed;
}
