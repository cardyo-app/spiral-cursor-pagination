<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Cursor;

use Cardyo\SpiralCursorPagination\Exception\MalformedCursorException;
use InvalidArgumentException;

/**
 * Value object representing composite cursor data.
 *
 * A cursor can be based on multiple fields (e.g., ['created_at' => '2024-01-01', 'id' => 123])
 * for stable pagination with complex sorting. This provides a type-safe way to work with
 * cursor values before encoding/after decoding.
 *
 * Example:
 * ```php
 * $cursor = new CursorData(['created_at' => '2024-01-01 12:00:00', 'id' => 42]);
 * $createdAt = $cursor->get('created_at'); // '2024-01-01 12:00:00'
 * $hasId = $cursor->has('id'); // true
 * ```
 */
final class CursorData implements CursorDataInterface
{
    /**
     * @param array<string, mixed> $fields Field name => value pairs
     */
    public function __construct(
        private readonly array $fields,
    ) {
        if ($fields === []) {
            throw new InvalidArgumentException('CursorData cannot be empty');
        }
    }

    /**
     * Get the value of a field.
     *
     * @param string $field Field name
     * @return mixed Field value
     * @throws InvalidArgumentException If field doesn't exist
     */
    public function get(string $field): mixed
    {
        if (!$this->has($field)) {
            throw new InvalidArgumentException(sprintf('Field "%s" does not exist in cursor data', $field));
        }

        return $this->fields[$field];
    }

    /**
     * Check if a field exists in the cursor data.
     *
     * @param string $field Field name
     * @return bool True if field exists
     */
    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    /**
     * Get all fields as an associative array.
     *
     * @return array<string, mixed> Field name => value pairs
     */
    #[\Override]
    public function toArray(): array
    {
        return $this->fields;
    }

    /**
     * Get all field names.
     *
     * @return array<string> Field names
     */
    public function getFields(): array
    {
        return array_keys($this->fields);
    }

    /**
     * Create CursorData from array.
     *
     * @param array<string, mixed> $data Field name => value pairs
     * @return self
     * @throws MalformedCursorException If data is empty
     */
    public static function fromArray(array $data): self
    {
        if ($data === []) {
            throw new MalformedCursorException('Cursor data cannot be empty');
        }

        return new self($data);
    }
}
