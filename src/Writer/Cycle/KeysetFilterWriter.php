<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Writer\Cycle;

use Cardyo\SpiralCursorPagination\Cursor\CursorData;
use Cardyo\SpiralCursorPagination\Specification\Cursor\KeysetFilter;
use Cycle\ORM\Select;
use Cycle\ORM\Select\QueryBuilder;
use DateTimeImmutable;
use DateTimeZone;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\SpecificationInterface;
use Spiral\DataGrid\WriterInterface;

/**
 * Writer for applying KeysetFilter to Cycle ORM queries.
 *
 * Builds WHERE clauses for efficient keyset pagination using tuple comparison.
 * For composite cursors (multiple fields), expands the tuple comparison into
 * a series of OR conditions for database compatibility.
 *
 * Example for fields ['created_at', 'id'] with forward direction:
 * WHERE created_at > '2024-01-01'
 *    OR (created_at = '2024-01-01' AND id > 123)
 *
 * @requires cycle/orm ^2.0
 * @psalm-suppress UndefinedClass - Cycle ORM is an optional dependency
 */
final class KeysetFilterWriter implements WriterInterface
{
    #[\Override]
    public function write(mixed $source, SpecificationInterface $specification, Compiler $compiler): mixed
    {
        if (!class_exists(Select::class)) {
            return null;
        }

        if (!$source instanceof Select) {
            return null;
        }

        if (!$specification instanceof KeysetFilter) {
            return null;
        }

        $fields = $specification->getFields();
        $cursorData = $specification->getCursorData();

        // If no fields specified, detect from query's ORDER BY clauses
        if ($fields === []) {
            $fields = $this->extractSortFieldsFromQuery($source);
            if ($fields === []) {
                // No sort order to build keyset filter from
                return $source;
            }
        }

        foreach ($fields as $field) {
            if (!$cursorData->has($field)) {
                return $source;
            }
        }

        // Determine operator based on pagination direction AND sort direction
        $operator = $this->determineOperator($source, $fields[0], $specification->isForward());

        if (count($fields) === 1) {
            $field = $fields[0];
            $value = $this->convertCursorValue($cursorData->get($field));
            return $source->where($field, $operator, $value);
        }

        return $this->buildTupleComparison($source, $fields, $cursorData, $operator);
    }

    /**
     * Determine the comparison operator based on pagination and sort direction.
     *
     * Logic:
     * - Forward + ASC: > (next items have larger values)
     * - Forward + DESC: < (next items have smaller values)
     * - Backward + ASC: < (previous items have smaller values)
     * - Backward + DESC: > (previous items have larger values)
     *
     * @psalm-suppress UndefinedClass
     */
    private function determineOperator(Select $select, string $field, bool $isForward): string
    {
        $tokens = $select->getBuilder()->getQuery()->getTokens();

        if (empty($tokens['orderBy'])) {
            // No explicit ORDER BY, assume ASC
            return $isForward ? '>' : '<';
        }

        // Find the direction for our field
        $direction = 'ASC';
        foreach ($tokens['orderBy'] as [$orderField, $orderDirection]) {
            $columnName = $this->extractColumnName($orderField);
            if ($columnName === $field) {
                $direction = strtoupper($orderDirection);
                break;
            }
        }

        $isDesc = ($direction === 'DESC');

        // XOR logic: if directions match, use >, otherwise use <
        return ($isForward === !$isDesc) ? '>' : '<';
    }

    /**
     * Extract sort fields from query's ORDER BY clauses.
     *
     * @return array<string>
     * @psalm-suppress UndefinedClass
     */
    private function extractSortFieldsFromQuery(Select $select): array
    {
        $tokens = $select->getBuilder()->getQuery()->getTokens();

        if (empty($tokens['orderBy'])) {
            return [];
        }

        $fields = [];
        foreach ($tokens['orderBy'] as [$field, $direction]) {
            // Extract column name from qualified names like "customer.uuid"
            $columnName = $this->extractColumnName($field);
            $fields[] = $columnName;
        }

        return $fields;
    }

    /**
     * Extract column name from potentially qualified field name.
     *
     * @param mixed $field
     */
    private function extractColumnName(mixed $field): string
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

    /**
     * Build tuple comparison for multi-field cursors.
     *
     * Expands (f1, f2, f3) > (v1, v2, v3) into:
     * f1 > v1 OR (f1 = v1 AND f2 > v2) OR (f1 = v1 AND f2 = v2 AND f3 > v3)
     *
     * @param array<string> $fields
     *
     * @psalm-suppress UndefinedClass
     */
    private function buildTupleComparison(
        Select $select,
        array $fields,
        CursorData $data,
        string $operator,
    ): Select {
        return $select->where(function (QueryBuilder $query) use ($fields, $data, $operator): void {
            $counter = count($fields);
            for ($i = 0; $i < $counter; ++$i) {
                $query->orWhere(function (QueryBuilder $subQuery) use ($fields, $data, $operator, $i): void {
                    for ($j = 0; $j < $i; ++$j) {
                        $value = $this->convertCursorValue($data->get($fields[$j]));
                        $subQuery->where($fields[$j], '=', $value);
                    }

                    $value = $this->convertCursorValue($data->get($fields[$i]));
                    $subQuery->where($fields[$i], $operator, $value);
                });
            }
        });
    }

    /**
     * Convert cursor values to database-compatible format.
     *
     * Handles DateTime serialization - Cycle stores DateTime as arrays,
     * but WHERE clauses need DateTimeImmutable objects for comparison.
     *
     * @param mixed $value Raw value from cursor
     * @return mixed Converted value ready for database comparison
     */
    private function convertCursorValue(mixed $value): mixed
    {
        if (is_array($value) && isset($value['date'], $value['timezone'])) {
            return new DateTimeImmutable(
                $value['date'],
                new DateTimeZone($value['timezone']),
            );
        }

        return $value;
    }
}
