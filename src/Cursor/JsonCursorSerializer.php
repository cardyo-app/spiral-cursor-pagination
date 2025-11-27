<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Cursor;

use JsonException;

/**
 * JSON-based cursor serializer.
 *
 * Serializes CursorData to JSON format for compact and human-readable
 * (when base64-decoded) cursor representation.
 *
 * Example serialized format: {"created_at":"2024-01-01 12:00:00","id":42}
 */
final class JsonCursorSerializer implements CursorSerializerInterface
{
    #[\Override]
    public function serialize(CursorData $data): string
    {
        try {
            return json_encode($data->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $jsonException) {
            throw new \RuntimeException('Failed to serialize cursor data: ' . $jsonException->getMessage(), 0, $jsonException);
        }
    }

    #[\Override]
    public function deserialize(string $serialized): ?CursorData
    {
        try {
            $decoded = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded) || $decoded === []) {
                return null;
            }

            return new CursorData($decoded);
        } catch (JsonException|\InvalidArgumentException) {
            return null;
        }
    }
}
