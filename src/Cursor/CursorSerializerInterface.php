<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Cursor;

/**
 * Interface for serializing/deserializing CursorData to/from string representation.
 *
 * Implementations might use JSON, MessagePack, or other serialization formats
 * to convert the cursor data into a compact string representation.
 */
interface CursorSerializerInterface
{
    /**
     * Serialize cursor data to a string.
     *
     * @param CursorData $data Cursor data to serialize
     * @return string Serialized representation
     */
    public function serialize(CursorData $data): string;

    /**
     * Deserialize a string back to cursor data.
     *
     * @param string $serialized Serialized cursor data
     * @return CursorData|null Cursor data, or null if deserialization fails
     */
    public function deserialize(string $serialized): ?CursorData;
}
