<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Util;

use Cycle\ORM\Select;

/**
 * Extracts sort fields and directions from Cycle ORM queries.
 *
 * This utility inspects the ORDER BY clauses in a compiled query
 * to determine which fields are being sorted and in what direction.
 * This allows cursor pagination to work without requiring explicit
 * sort field configuration.
 */
final class QuerySortFieldsExtractor
{
    /**
     * Extract sort fields from a Cycle ORM Select query.
     *
     * @param Select $select Query to extract from
     * @return array<string, string> Map of field => direction ('ASC' or 'DESC')
     */
    public static function extract(Select $select): array
    {
        $tokens = $select->getBuilder()->getQuery()->getTokens();

        if (empty($tokens['orderBy'])) {
            return [];
        }

        $sortFields = [];
        foreach ($tokens['orderBy'] as [$field, $direction]) {
            // Extract column name from qualified names like "customer.uuid"
            $columnName = self::extractColumnName($field);
            $sortFields[$columnName] = strtoupper($direction);
        }

        return $sortFields;
    }

    /**
     * Extract just the field names (keys) from sort fields.
     *
     * @param Select $select Query to extract from
     * @return array<string> List of field names
     */
    public static function extractFieldNames(Select $select): array
    {
        return array_keys(self::extract($select));
    }

    /**
     * Extract column name from potentially qualified field name.
     *
     * Handles:
     * - Simple names: "uuid" => "uuid"
     * - Qualified names: "customer.uuid" => "uuid"
     * - Alias names: "c.uuid" => "uuid"
     *
     * @param mixed $field Field name or object
     * @return string Column name
     */
    private static function extractColumnName(mixed $field): string
    {
        if (!is_string($field)) {
            return (string) $field;
        }

        // Check if it's a qualified name (table.column or alias.column)
        if (str_contains($field, '.')) {
            $parts = explode('.', $field);
            return end($parts);
        }

        return $field;
    }
}
