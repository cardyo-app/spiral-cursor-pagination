<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\CursorEncoder;

/**
 * Interface for decoding opaque cursor strings back into cursor data.
 *
 * Cursor decoders are responsible for transforming encoded cursor strings
 * back into their original data representation.
 *
 * Implementations should:
 * - Validate cursor format and integrity
 * - Handle version compatibility
 * - Return null for invalid cursors rather than throwing exceptions
 */
interface CursorDecoderInterface
{
    /**
     * Decode an opaque cursor string into cursor data.
     *
     * @param string $cursor The encoded cursor string
     * @return mixed The decoded cursor data (typically an array), or null if invalid
     */
    public function decode(string $cursor): mixed;
}
