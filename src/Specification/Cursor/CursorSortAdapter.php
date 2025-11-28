<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Specification\Cursor;

use Spiral\DataGrid\Specification\SorterInterface;
use Spiral\DataGrid\SpecificationInterface;

/**
 * Adapter that wraps a regular Sorter and ensures cursor pagination requirements are met.
 *
 * This adapter automatically appends a unique field to any sorter to ensure deterministic
 * ordering required for cursor pagination. It works with Spiral's built-in sorters and
 * allows dynamic user-controlled sorting while maintaining cursor stability.
 *
 * Example usage:
 * ```php
 * use Spiral\DataGrid\Specification\Sorter\Sorter;
 *
 * // User-controlled sorter for "last_active_at" field
 * $userSorter = new Sorter('last_active_at');
 *
 * // Wrap it to ensure 'uuid' is always appended for cursor stability
 * $cursorSorter = new CursorSortAdapter(
 *     sorter: $userSorter,
 *     uniqueField: 'uuid',
 *     uniqueDirection: 'asc'
 * );
 *
 * // When user sets direction, unique field is automatically appended
 * $sorted = $cursorSorter->withDirection('desc');
 * // Results in: ORDER BY last_active_at DESC, uuid ASC
 * ```
 *
 * This allows your users to sort by any field (recently active, least active, etc.)
 * while maintaining cursor pagination stability through the unique field.
 */
final class CursorSortAdapter implements SorterInterface
{
    /**
     * @param SorterInterface $sorter The base sorter to wrap
     * @param string $uniqueField The unique field to append (e.g., 'id', 'uuid')
     * @param string $uniqueDirection The direction for the unique field ('asc' or 'desc')
     */
    public function __construct(
        private readonly SorterInterface $sorter,
        private readonly string $uniqueField,
        string $uniqueDirection = self::ASC,
    ) {
        $normalizedDirection = \strtolower($uniqueDirection);

        if (!\in_array($normalizedDirection, [self::ASC, self::DESC], true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Unique field direction must be "asc" or "desc", got "%s"',
                $uniqueDirection
            ));
        }

        $this->uniqueDirection = $normalizedDirection;
    }

    private readonly string $uniqueDirection;

    /**
     * {@inheritdoc}
     */
    public function withDirection(int|string $direction): ?SpecificationInterface
    {
        // Apply direction to the wrapped sorter
        $appliedSorter = $this->sorter->withDirection($direction);

        if ($appliedSorter === null) {
            return null;
        }

        // Extract sort fields from the applied sorter
        $sortFields = $this->extractSortFields($appliedSorter, $direction);

        // Ensure unique field is appended
        if (!isset($sortFields[$this->uniqueField])) {
            $sortFields[$this->uniqueField] = $this->uniqueDirection;
        }

        return new CursorSort($sortFields, $this->uniqueField);
    }

    /**
     * {@inheritdoc}
     */
    public function getValue(): mixed
    {
        return $this->sorter->getValue();
    }

    /**
     * Extract sort fields from a sorter specification.
     *
     * @param SpecificationInterface $sorter The sorter specification
     * @param int|string $direction The applied direction
     * @return array<string, string> Sort fields with directions
     */
    private function extractSortFields(SpecificationInterface $sorter, int|string $direction): array
    {
        // If it's already a CursorSort, return its fields
        if ($sorter instanceof CursorSort) {
            return $sorter->fields;
        }

        // For standard sorters, get the field name from getValue()
        $value = $sorter->getValue();

        if ($value === null || $value === '') {
            return [];
        }

        // Normalize direction
        $normalizedDirection = $this->normalizeDirection($direction);

        // Handle single field or multiple fields
        if (\is_string($value)) {
            // Single field
            return [$value => $normalizedDirection];
        }

        if (\is_array($value)) {
            // Multiple fields - assume all have the same direction
            $fields = [];
            foreach ($value as $field) {
                if (\is_string($field)) {
                    $fields[$field] = $normalizedDirection;
                }
            }
            return $fields;
        }

        return [];
    }

    /**
     * Normalize direction to 'asc' or 'desc'.
     */
    private function normalizeDirection(int|string $direction): string
    {
        if (\is_int($direction)) {
            return $direction > 0 ? self::ASC : self::DESC;
        }

        $dir = \strtolower((string) $direction);
        return \in_array($dir, [self::ASC, self::DESC], true) ? $dir : self::ASC;
    }
}
