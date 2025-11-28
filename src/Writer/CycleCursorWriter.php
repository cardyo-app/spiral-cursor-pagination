<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Writer;

use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorAfter;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorBefore;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorLimit;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorSort;
use Cycle\ORM\Select;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\SpecificationInterface;
use Spiral\DataGrid\Writer\WriterInterface;

/**
 * Writer for applying cursor pagination specifications to Cycle ORM queries.
 *
 * This writer transforms cursor pagination specifications into Cycle ORM
 * Select query modifications, supporting both forward and backward pagination
 * with proper sorting and filtering.
 *
 * Handles:
 * - CursorAfter: Adds WHERE clause for forward pagination
 * - CursorBefore: Adds WHERE clause for backward pagination
 * - CursorLimit: Applies LIMIT clause (with N+1 for hasNext/hasPrev detection)
 * - CursorSort: Applies ORDER BY clause with deterministic ordering
 *
 * Example query transformation for forward pagination:
 * ```
 * Original: SELECT * FROM users
 * After cursor: WHERE (created_at > ? OR (created_at = ? AND id > ?))
 * Sort: ORDER BY created_at ASC, id ASC
 * Limit: LIMIT 11 (when requesting 10 items)
 * ```
 *
 * @see https://cycle-orm.dev/docs/query-builder-complex
 */
final class CycleCursorWriter implements WriterInterface
{
    /**
     * Apply cursor specification to Cycle ORM Select query.
     *
     * @param Select $source The Cycle ORM Select query
     * @param SpecificationInterface $specification The cursor specification to apply
     * @param Compiler $compiler The specification compiler
     * @return Select|null Modified query or null if specification not supported
     */
    public function write(mixed $source, SpecificationInterface $specification, Compiler $compiler): mixed
    {
        if (!$source instanceof Select) {
            return null;
        }

        return match (true) {
            $specification instanceof CursorAfter => $this->applyCursorAfter($source, $specification),
            $specification instanceof CursorBefore => $this->applyCursorBefore($source, $specification),
            $specification instanceof CursorLimit => $this->applyCursorLimit($source, $specification),
            $specification instanceof CursorSort => $this->applyCursorSort($source, $specification),
            default => null,
        };
    }

    /**
     * Apply forward pagination cursor (after).
     *
     * Generates WHERE clause: (field1 > val1 OR (field1 = val1 AND field2 > val2) OR ...)
     */
    private function applyCursorAfter(Select $source, CursorAfter $specification): Select
    {
        $cursor = $specification->cursor;
        $sortFields = $cursor->getSortFields();

        if (empty($sortFields)) {
            return $source;
        }

        // Build compound WHERE condition for cursor position
        $conditions = $this->buildCursorConditions($sortFields, $cursor->sortValues, '>');

        return $source->where($conditions);
    }

    /**
     * Apply backward pagination cursor (before).
     *
     * Generates WHERE clause: (field1 < val1 OR (field1 = val1 AND field2 < val2) OR ...)
     */
    private function applyCursorBefore(Select $source, CursorBefore $specification): Select
    {
        $cursor = $specification->cursor;
        $sortFields = $cursor->getSortFields();

        if (empty($sortFields)) {
            return $source;
        }

        // Build compound WHERE condition for cursor position
        $conditions = $this->buildCursorConditions($sortFields, $cursor->sortValues, '<');

        return $source->where($conditions);
    }

    /**
     * Apply cursor limit.
     *
     * Applies LIMIT clause, potentially fetching N+1 items to detect if more pages exist.
     */
    private function applyCursorLimit(Select $source, CursorLimit $specification): Select
    {
        return $source->limit($specification->getQueryLimit());
    }

    /**
     * Apply cursor sort order.
     *
     * Applies ORDER BY clause with all sort fields in the specified order.
     * The sort must include a unique field for deterministic ordering.
     */
    private function applyCursorSort(Select $source, CursorSort $specification): Select
    {
        $query = $source;

        foreach ($specification->fields as $field => $direction) {
            $query = $query->orderBy($field, \strtoupper($direction));
        }

        return $query;
    }

    /**
     * Build cursor comparison conditions for WHERE clause.
     *
     * Creates a nested OR condition that compares multiple sort fields:
     * (field1 OP val1 OR (field1 = val1 AND field2 OP val2) OR ...)
     *
     * This allows proper cursor-based filtering across multiple sort columns.
     *
     * @param array<string> $fields Sort field names in order
     * @param array<string, mixed> $values Cursor values for each field
     * @param string $operator Comparison operator ('>' for after, '<' for before)
     * @return array The WHERE condition structure for Cycle ORM
     */
    private function buildCursorConditions(array $fields, array $values, string $operator): array
    {
        $conditions = [];

        // Build conditions incrementally
        // For fields [a, b, c], create:
        // (a > val_a) OR (a = val_a AND b > val_b) OR (a = val_a AND b = val_b AND c > val_c)

        $equalityConditions = [];

        foreach ($fields as $index => $field) {
            $value = $values[$field] ?? null;

            if ($value === null) {
                // Skip null values in cursor
                continue;
            }

            // Build condition for current field
            $currentCondition = [...$equalityConditions, [$field, $operator, $value]];
            $conditions[] = $currentCondition;

            // Add equality condition for next iteration
            $equalityConditions[] = [$field, '=', $value];
        }

        // If only one condition, return it directly
        if (\count($conditions) === 1) {
            return $conditions[0];
        }

        // Multiple conditions - wrap in OR
        return ['OR' => $conditions];
    }
}
