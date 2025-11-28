<?php

declare(strict_types=1);

/**
 * Custom Writer Example
 *
 * This example demonstrates how to create a custom writer
 * for a data source other than Cycle ORM.
 */

use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorAfter;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorBefore;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorDirection;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorLimit;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorSort;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\SpecificationInterface;
use Spiral\DataGrid\Writer\WriterInterface;

/**
 * Example: Writer for Doctrine DBAL Query Builder
 */
class DoctrineDBALCursorWriter implements WriterInterface
{
    public function write(
        mixed $source,
        SpecificationInterface $specification,
        Compiler $compiler
    ): mixed {
        // Only handle Doctrine DBAL QueryBuilder
        if (!$source instanceof \Doctrine\DBAL\Query\QueryBuilder) {
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

    private function applyCursorAfter(
        \Doctrine\DBAL\Query\QueryBuilder $qb,
        CursorAfter $specification
    ): \Doctrine\DBAL\Query\QueryBuilder {
        $cursor = $specification->cursor;
        $sortFields = $cursor->getSortFields();

        if (empty($sortFields)) {
            return $qb;
        }

        // Build WHERE clause for cursor position
        $conditions = $this->buildCursorConditions(
            $qb,
            $sortFields,
            $cursor->sortValues,
            '>'
        );

        return $qb->andWhere($conditions);
    }

    private function applyCursorBefore(
        \Doctrine\DBAL\Query\QueryBuilder $qb,
        CursorBefore $specification
    ): \Doctrine\DBAL\Query\QueryBuilder {
        $cursor = $specification->cursor;
        $sortFields = $cursor->getSortFields();

        if (empty($sortFields)) {
            return $qb;
        }

        $conditions = $this->buildCursorConditions(
            $qb,
            $sortFields,
            $cursor->sortValues,
            '<'
        );

        return $qb->andWhere($conditions);
    }

    private function applyCursorLimit(
        \Doctrine\DBAL\Query\QueryBuilder $qb,
        CursorLimit $specification
    ): \Doctrine\DBAL\Query\QueryBuilder {
        return $qb->setMaxResults($specification->getQueryLimit());
    }

    private function applyCursorSort(
        \Doctrine\DBAL\Query\QueryBuilder $qb,
        CursorSort $specification
    ): \Doctrine\DBAL\Query\QueryBuilder {
        foreach ($specification->fields as $field => $direction) {
            $qb->addOrderBy($field, strtoupper($direction));
        }

        return $qb;
    }

    private function buildCursorConditions(
        \Doctrine\DBAL\Query\QueryBuilder $qb,
        array $fields,
        array $values,
        string $operator
    ): string {
        $orConditions = [];
        $equalityConditions = [];

        foreach ($fields as $index => $field) {
            $value = $values[$field] ?? null;

            if ($value === null) {
                continue;
            }

            // Build parameter names
            $paramName = 'cursor_' . $field . '_' . $index;

            // Build current condition
            $currentCondition = [...$equalityConditions];
            $currentCondition[] = "$field $operator :$paramName";

            $orConditions[] = '(' . implode(' AND ', $currentCondition) . ')';

            // Bind parameter
            $qb->setParameter($paramName, $value);

            // Add equality for next iteration
            $equalityParamName = 'cursor_eq_' . $field . '_' . $index;
            $equalityConditions[] = "$field = :$equalityParamName";
            $qb->setParameter($equalityParamName, $value);
        }

        return '(' . implode(' OR ', $orConditions) . ')';
    }
}

/**
 * Example: Writer for Elasticsearch
 */
class ElasticsearchCursorWriter implements WriterInterface
{
    public function write(
        mixed $source,
        SpecificationInterface $specification,
        Compiler $compiler
    ): mixed {
        // Assuming $source is an Elasticsearch query array
        if (!is_array($source) || !isset($source['query'])) {
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

    private function applyCursorAfter(array $query, CursorAfter $specification): array
    {
        $cursor = $specification->cursor;

        // Use Elasticsearch search_after
        $searchAfter = [];
        foreach ($cursor->getSortFields() as $field) {
            $searchAfter[] = $cursor->getSortValue($field);
        }

        $query['search_after'] = $searchAfter;

        return $query;
    }

    private function applyCursorBefore(array $query, CursorBefore $specification): array
    {
        // Elasticsearch doesn't support backward pagination natively
        // You would need to reverse sort and results
        $cursor = $specification->cursor;

        $searchAfter = [];
        foreach ($cursor->getSortFields() as $field) {
            $searchAfter[] = $cursor->getSortValue($field);
        }

        $query['search_after'] = $searchAfter;

        return $query;
    }

    private function applyCursorLimit(array $query, CursorLimit $specification): array
    {
        $query['size'] = $specification->getQueryLimit();
        return $query;
    }

    private function applyCursorSort(array $query, CursorSort $specification): array
    {
        $sort = [];

        foreach ($specification->fields as $field => $direction) {
            $sort[] = [
                $field => [
                    'order' => $direction,
                ],
            ];
        }

        $query['sort'] = $sort;

        return $query;
    }
}

/**
 * Example: Writer for Array/Collection Data
 */
class ArrayCursorWriter implements WriterInterface
{
    public function write(
        mixed $source,
        SpecificationInterface $specification,
        Compiler $compiler
    ): mixed {
        if (!is_array($source)) {
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

    private function applyCursorAfter(array $items, CursorAfter $specification): array
    {
        $cursor = $specification->cursor;

        return array_filter($items, function ($item) use ($cursor) {
            return $this->compareItemToCursor($item, $cursor->sortValues, '>');
        });
    }

    private function applyCursorBefore(array $items, CursorBefore $specification): array
    {
        $cursor = $specification->cursor;

        return array_filter($items, function ($item) use ($cursor) {
            return $this->compareItemToCursor($item, $cursor->sortValues, '<');
        });
    }

    private function applyCursorLimit(array $items, CursorLimit $specification): array
    {
        return array_slice($items, 0, $specification->getQueryLimit());
    }

    private function applyCursorSort(array $items, CursorSort $specification): array
    {
        $fields = $specification->fields;

        usort($items, function ($a, $b) use ($fields) {
            foreach ($fields as $field => $direction) {
                $aValue = is_array($a) ? $a[$field] : $a->$field;
                $bValue = is_array($b) ? $b[$field] : $b->$field;

                $comparison = $aValue <=> $bValue;

                if ($comparison !== 0) {
                    return $direction === 'asc' ? $comparison : -$comparison;
                }
            }

            return 0;
        });

        return $items;
    }

    private function compareItemToCursor(mixed $item, array $cursorValues, string $operator): bool
    {
        foreach ($cursorValues as $field => $cursorValue) {
            $itemValue = is_array($item) ? $item[$field] : $item->$field;

            if ($operator === '>') {
                if ($itemValue > $cursorValue) {
                    return true;
                }
                if ($itemValue < $cursorValue) {
                    return false;
                }
            } else { // '<'
                if ($itemValue < $cursorValue) {
                    return true;
                }
                if ($itemValue > $cursorValue) {
                    return false;
                }
            }
        }

        return false;
    }
}

// Usage: Register your custom writer
/*
// In app/config/dataGrid.php
return [
    'writers' => [
        \DoctrineDBALCursorWriter::class,
        \ElasticsearchCursorWriter::class,
        \ArrayCursorWriter::class,
    ],
];
*/
