<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Writer\Cycle;

use Cycle\ORM\Select;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\SpecificationInterface;
use Spiral\DataGrid\WriterInterface;

/**
 * Writer that automatically appends primary key to ORDER BY for deterministic sorting.
 *
 * Ensures consistent pagination by adding the entity's primary key as a final
 * tiebreaker when it's not already present in the ORDER BY clause.
 *
 * This prevents flaky tests and inconsistent pagination when multiple records
 * have identical values in the sort fields.
 *
 * @requires cycle/orm ^2.0
 * @psalm-suppress UndefinedClass - Cycle ORM is an optional dependency
 */
final class PrimaryKeyTiebreakerWriter implements WriterInterface
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

        // Only apply to GridSchema (main pagination specification)
        if (!$specification instanceof GridSchema) {
            return null;
        }

        $builder = $source->getBuilder();
        $tokens = $builder->getQuery()->getTokens();

        // Get existing ORDER BY clauses
        $orderBy = $tokens['orderBy'] ?? [];

        // Extract primary key from ORM
        $primaryKey = $this->getPrimaryKey($source);
        if ($primaryKey === null) {
            return $source;
        }

        // If no ORDER BY, add primary key as default sort
        if (empty($orderBy)) {
            return $source->orderBy($primaryKey, 'ASC');
        }

        // Check if primary key is already in ORDER BY
        foreach ($orderBy as [$field, $direction]) {
            $columnName = $this->extractColumnName($field);
            if ($columnName === $primaryKey) {
                // Primary key already in ORDER BY, no need to add
                return $source;
            }
        }

        // Add primary key with same direction as last sort field
        $lastDirection = 'ASC';
        if (!empty($orderBy)) {
            [, $lastDirection] = end($orderBy);
        }

        return $source->orderBy($primaryKey, $lastDirection);
    }

    /**
     * Get primary key field name from ORM schema.
     *
     * @psalm-suppress UndefinedClass
     */
    private function getPrimaryKey(Select $select): ?string
    {
        try {
            $orm = $select->getBuilder()->getLoader()->getOrm();
            $role = $select->getBuilder()->getLoader()->getTarget();
            $schema = $orm->getSchema();

            // Get primary key from schema
            $definition = $schema->define($role, \Cycle\ORM\SchemaInterface::PRIMARY_KEY);

            if (is_string($definition)) {
                return $definition;
            }

            // Composite primary key - use first field
            if (is_array($definition) && !empty($definition)) {
                return $definition[0];
            }

            return null;
        } catch (\Throwable $e) {
            // If we can't determine primary key, don't add tiebreaker
            return null;
        }
    }

    /**
     * Extract column name from potentially qualified field name.
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
}
