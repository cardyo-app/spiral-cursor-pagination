<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\CursorEncoder;

/**
 * Interface for encoding cursor data into opaque cursor strings.
 *
 * Cursor encoders are responsible for transforming cursor data (typically arrays)
 * into URL-safe, opaque strings that can be used for pagination.
 *
 * Implementations should ensure:
 * - Cursors are opaque (not human-readable)
 * - Cursors are URL-safe (no special characters requiring encoding)
 * - Cursors include version information for future compatibility
 */
interface CursorEncoderInterface
{
    /**
     * Encode cursor data into an opaque cursor string.
     *
     * @param mixed $cursor The cursor data to encode (typically an array)
     * @return string The encoded cursor string
     * @throws \InvalidArgumentException If the cursor data is invalid
     */
    public function encode(mixed $cursor): string;
}
