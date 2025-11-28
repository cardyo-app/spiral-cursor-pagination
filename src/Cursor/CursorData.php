<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Cursor;

/**
 * Represents the decoded cursor data containing sort field values and metadata.
 *
 * The cursor encodes the position in a result set based on the values of the
 * sorted fields at that position. This allows for stable pagination even when
 * new items are added or removed from the dataset.
 */
final readonly class CursorData
{
    /**
     * @param array<string, mixed> $sortValues Key-value pairs of sort field names and their values at cursor position
     * @param Direction $direction The pagination direction this cursor was created for
     * @param string $version The cursor encoding version for backward compatibility
     */
    public function __construct(
        public array $sortValues,
        public Direction $direction = Direction::FORWARD,
        public string $version = 'v1',
    ) {
    }

    /**
     * Create a CursorData from an array representation.
     *
     * @param array{sort_values: array<string, mixed>, direction?: string, version?: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sortValues: $data['sort_values'],
            direction: isset($data['direction'])
                ? Direction::from($data['direction'])
                : Direction::FORWARD,
            version: $data['version'] ?? 'v1',
        );
    }

    /**
     * Convert the CursorData to an array representation for encoding.
     *
     * @return array{sort_values: array<string, mixed>, direction: string, version: string}
     */
    public function toArray(): array
    {
        return [
            'sort_values' => $this->sortValues,
            'direction' => $this->direction->value,
            'version' => $this->version,
        ];
    }

    /**
     * Get the value of a specific sort field.
     */
    public function getSortValue(string $field): mixed
    {
        return $this->sortValues[$field] ?? null;
    }

    /**
     * Check if a sort field exists in the cursor.
     */
    public function hasSortValue(string $field): bool
    {
        return \array_key_exists($field, $this->sortValues);
    }

    /**
     * Get all sort field names.
     *
     * @return array<string>
     */
    public function getSortFields(): array
    {
        return \array_keys($this->sortValues);
    }
}
