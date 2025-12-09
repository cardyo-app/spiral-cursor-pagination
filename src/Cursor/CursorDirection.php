<?php

namespace Cardyo\SpiralCursorPagination\Cursor;

enum CursorDirection : string
{
    case FORWARD = 'forward';
    case BACKWARD = 'backward';

    public function isForward(): bool
    {
        return $this === self::FORWARD;
    }

    public function isBackward(): bool
    {
        return $this === self::BACKWARD;
    }

    public function reverse(): self
    {
        return match ($this) {
            self::FORWARD => self::BACKWARD,
            self::BACKWARD => self::FORWARD,
        };
    }
}
