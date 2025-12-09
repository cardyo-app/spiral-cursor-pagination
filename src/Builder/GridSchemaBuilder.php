<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Builder;

use Cardyo\SpiralCursorPagination\Service\CursorPaginationHelper;
use Cardyo\SpiralCursorPagination\Specification\Pagination\CursorPaginator;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Filter;
use Spiral\DataGrid\Specification\Sorter\Sorter;

/**
 * Fluent builder for creating GridSchema with cursor pagination.
 *
 * Provides a clean, chainable API for building grid schemas.
 *
 * Example:
 * ```php
 * $schema = GridSchemaBuilder::create($helper)
 *     ->withSearch('name', 'name')
 *     ->withFilter('active', new Gte('login_count', 10))
 *     ->withSorter('name', 'name')
 *     ->withSorter('created', 'created_at')
 *     ->withPagination(pageSize: 20)
 *     ->build();
 * ```
 */
final class GridSchemaBuilder
{
    private GridSchema $schema;

    private function __construct(
        private readonly ?CursorPaginationHelper $helper = null,
    ) {
        $this->schema = new GridSchema();
    }

    /**
     * Create a new builder instance.
     *
     * @param CursorPaginationHelper|null $helper Optional helper for auto-pagination setup
     */
    public static function create(?CursorPaginationHelper $helper = null): self
    {
        return new self($helper);
    }

    /**
     * Add a search filter (uses LIKE pattern).
     *
     * @param string $name Filter name (from query params)
     * @param string $field Database field to search
     * @param string $pattern Search pattern (default: %{value}%)
     */
    public function withSearch(
        string $name,
        string $field,
        string $pattern = '%{value}%'
    ): self {
        $this->schema->addFilter(
            $name,
            new Filter\Like($field, $pattern)
        );

        return $this;
    }

    /**
     * Add a generic filter.
     *
     * @param string $name Filter name (from query params)
     * @param Filter\FilterInterface $filter Filter specification
     */
    public function withFilter(string $name, Filter\FilterInterface $filter): self
    {
        $this->schema->addFilter($name, $filter);
        return $this;
    }

    /**
     * Add an equals filter.
     *
     * @param string $name Filter name
     * @param string $field Database field
     */
    public function withEquals(string $name, string $field): self
    {
        $this->schema->addFilter(
            $name,
            new Filter\Equals($field, '{value}')
        );

        return $this;
    }

    /**
     * Add a greater-than-or-equal filter.
     *
     * @param string $name Filter name
     * @param string $field Database field
     */
    public function withGte(string $name, string $field): self
    {
        $this->schema->addFilter(
            $name,
            new Filter\Gte($field, '{value}')
        );

        return $this;
    }

    /**
     * Add a less-than-or-equal filter.
     *
     * @param string $name Filter name
     * @param string $field Database field
     */
    public function withLte(string $name, string $field): self
    {
        $this->schema->addFilter(
            $name,
            new Filter\Lte($field, '{value}')
        );

        return $this;
    }

    /**
     * Add an IN filter (for array values).
     *
     * @param string $name Filter name
     * @param string $field Database field
     */
    public function withIn(string $name, string $field): self
    {
        $this->schema->addFilter(
            $name,
            new Filter\InArray($field, ['{value}'])
        );

        return $this;
    }

    /**
     * Add a sorter (allows sorting by this field).
     *
     * @param string $name Sorter name (from query params)
     * @param string $field Database field to sort by
     */
    public function withSorter(string $name, string $field): self
    {
        $this->schema->addSorter($name, new Sorter($field));
        return $this;
    }

    /**
     * Configure cursor pagination.
     *
     * If CursorPaginationHelper was provided in create(), uses it to create paginator.
     * Otherwise, you must provide a paginator instance.
     *
     * @param int|null $pageSize Page size (default: helper's default or 20)
     * @param int|null $maxPageSize Max page size (default: helper's max or 100)
     * @param CursorPaginator|null $paginator Custom paginator (overrides pageSize params)
     */
    public function withPagination(
        ?int $pageSize = null,
        ?int $maxPageSize = null,
        ?CursorPaginator $paginator = null,
    ): self {
        if ($paginator !== null) {
            $this->schema->setPaginator($paginator);
            return $this;
        }

        if ($this->helper === null) {
            throw new \LogicException(
                'Cannot auto-create paginator without CursorPaginationHelper. ' .
                'Either pass helper to create() or provide paginator instance.'
            );
        }

        $this->schema->setPaginator(
            $this->helper->createPaginator($pageSize, $maxPageSize)
        );

        return $this;
    }

    /**
     * Set a custom paginator.
     *
     * @param CursorPaginator $paginator
     */
    public function setPaginator(CursorPaginator $paginator): self
    {
        $this->schema->setPaginator($paginator);
        return $this;
    }

    /**
     * Build and return the GridSchema.
     */
    public function build(): GridSchema
    {
        return $this->schema;
    }

    /**
     * Get the schema (alias for build()).
     */
    public function getSchema(): GridSchema
    {
        return $this->build();
    }
}
