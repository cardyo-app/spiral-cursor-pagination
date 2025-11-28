<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Specification\Cursor;

use Spiral\DataGrid\SpecificationInterface;

/**
 * Specification defining the sort order for cursor pagination.
 *
 * Cursor pagination requires deterministic ordering to ensure stable pagination.
 * This means the sort order must include a unique field (typically the primary key)
 * as the final sort criterion to guarantee consistent results across requests.
 *
 * Example:
 * ```php
 * // Sort by timestamp, then by id (unique field)
 * new CursorSort([
 *     'created_at' => 'asc',
 *     'id' => 'asc',
 * ], 'id')
 * ```
 *
 * @see https://jsonapi.org/profiles/ethanresnick/cursor-pagination/#sort-order
 */
final readonly class CursorSort implements SpecificationInterface
{
    public const ASC = 'asc';
    public const DESC = 'desc';

    /**
     * @param array<string, string> $fields Field names and their sort directions ('asc' or 'desc')
     * @param string $uniqueField The unique field name (typically primary key) that ensures deterministic ordering
     */
    public function __construct(
        public array $fields,
        public string $uniqueField,
    ) {
        if (empty($fields)) {
            throw new \InvalidArgumentException('Sort fields cannot be empty');
        }

        if (!isset($fields[$uniqueField])) {
            throw new \InvalidArgumentException(\sprintf(
                'Unique field "%s" must be included in sort fields for deterministic ordering',
                $uniqueField
            ));
        }

        foreach ($fields as $field => $direction) {
            if (!\is_string($field) || $field === '') {
                throw new \InvalidArgumentException('Sort field name must be a non-empty string');
            }

            if (!\in_array(\strtolower($direction), [self::ASC, self::DESC], true)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Sort direction for field "%s" must be "asc" or "desc", got "%s"',
                    $field,
                    $direction
                ));
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function getValue(): array
    {
        return $this->fields;
    }

    /**
     * Get the field names in sort order.
     *
     * @return array<string>
     */
    public function getFieldNames(): array
    {
        return \array_keys($this->fields);
    }

    /**
     * Get the direction for a specific field.
     */
    public function getDirection(string $field): ?string
    {
        return $this->fields[$field] ?? null;
    }

    /**
     * Check if a field is sorted in ascending order.
     */
    public function isAscending(string $field): bool
    {
        return isset($this->fields[$field]) && \strtolower($this->fields[$field]) === self::ASC;
    }

    /**
     * Check if a field is sorted in descending order.
     */
    public function isDescending(string $field): bool
    {
        return isset($this->fields[$field]) && \strtolower($this->fields[$field]) === self::DESC;
    }

    /**
     * Get the unique field name that ensures deterministic ordering.
     */
    public function getUniqueField(): string
    {
        return $this->uniqueField;
    }

    /**
     * Create a reversed sort order for backward pagination.
     *
     * @return self
     */
    public function reverse(): self
    {
        $reversedFields = [];
        foreach ($this->fields as $field => $direction) {
            $reversedFields[$field] = \strtolower($direction) === self::ASC ? self::DESC : self::ASC;
        }

        return new self($reversedFields, $this->uniqueField);
    }
}
