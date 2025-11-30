<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Cursor;

use Cardyo\SpiralCursorPagination\Exception\MalformedCursorException;

final class CursorData implements CursorDataInterface
{

    #[\Override]
    public function toArray(): array
    {
        return [];
    }

    /**
     * @throws MalformedCursorException
     */
    public static function fromArray(array $data): CursorDataInterface
    {
        return new self();
    }
}
