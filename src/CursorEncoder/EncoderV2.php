<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\CursorEncoder;

use Cardyo\SpiralCursorPagination\Cursor\CursorData;
use Cardyo\SpiralCursorPagination\Cursor\CursorSerializerInterface;
use Cardyo\SpiralCursorPagination\Cursor\JsonCursorSerializer;
use InvalidArgumentException;

/**
 * Version 2 cursor encoder supporting composite cursors via CursorData.
 *
 * Encodes CursorData objects (composite cursors) to base64-encoded strings
 * with version prefix for future compatibility. Falls back to string encoding
 * for backward compatibility with simple cursors.
 *
 * Format: cursor:v2:{base64(serialized_cursor_data)}
 *
 * Example:
 * ```php
 * $encoder = new EncoderV2();
 * $cursor = new CursorData(['created_at' => '2024-01-01', 'id' => 123]);
 * $encoded = $encoder->encodeCursor($cursor);
 * // Result: "Y3Vyc29yOnYyOnsibmFtZSI6IkpvaG4iLCJpZCI6MTIzfQ"
 * ```
 */
final class EncoderV2 implements CursorDecoderInterface, CursorEncoderInterface
{
    public const PREFIX = 'cursor:v2:';

    public function __construct(private readonly ?CursorSerializerInterface $serializer = new JsonCursorSerializer()) {}

    #[\Override]
    public function encodeCursor(mixed $cursor): string
    {
        if ($cursor instanceof CursorData) {
            $serialized = $this->serializer->serialize($cursor);
            $prefixed = sprintf('%s%s', self::PREFIX, $serialized);
            return rtrim(strtr(base64_encode($prefixed), '+/', '-_'), '=');
        }

        if (is_string($cursor)) {
            // Backward compatibility: encode simple strings
            $prefixed = sprintf('%s%s', self::PREFIX, $cursor);
            return rtrim(strtr(base64_encode($prefixed), '+/', '-_'), '=');
        }

        throw new InvalidArgumentException('Cursor must be CursorData or string');
    }

    #[\Override]
    public function decodeCursor(string $cursor): mixed
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || !str_starts_with($decoded, self::PREFIX)) {
            return null;
        }

        // Extract the part after the prefix
        $payload = array_reverse(explode(self::PREFIX, $decoded, 2))[0];

        // Try to deserialize as CursorData first
        $cursorData = $this->serializer->deserialize($payload);

        if ($cursorData instanceof CursorData) {
            return $cursorData;
        }

        // Fallback to string for backward compatibility
        return $payload;
    }
}
