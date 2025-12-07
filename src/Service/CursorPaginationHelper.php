<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Service;

use Cardyo\SpiralCursorPagination\CursorEncoder\CursorCoder;
use Cardyo\SpiralCursorPagination\CursorEncoder\CursorEncoderInterface;
use Cardyo\SpiralCursorPagination\Response\Connection;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\DataGrid\GridFactoryInterface;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Input\ArrayInput;

/**
 * Production-ready helper for cursor pagination.
 *
 * Simplifies the process of adding cursor pagination to controller endpoints.
 * Handles DataGrid integration, cursor encoding, and Connection response creation.
 *
 * Example usage in controller:
 * ```php
 * public function index(
 *     CursorPaginationHelper $helper,
 *     ServerRequestInterface $request
 * ): Connection {
 *     $select = $this->orm->getRepository(Customer::class)->select();
 *
 *     $gridSchema = new GridSchema();
 *     $gridSchema->addFilter('search', new Like('name', '%{value}%'));
 *     $gridSchema->addSorter('name', new Sorter('name'));
 *     $gridSchema->setPaginator($helper->createPaginator());
 *
 *     return $helper->paginate(
 *         query: $select,
 *         request: $request,
 *         gridSchema: $gridSchema,
 *         mapper: fn($customer) => CustomerDTO::fromEntity($customer)
 *     );
 * }
 * ```
 */
final class CursorPaginationHelper
{
    public function __construct(
        private readonly GridFactoryInterface $gridFactory,
        private readonly CursorEncoderInterface $encoder = new CursorCoder(),
        private readonly int $defaultPageSize = 20,
        private readonly int $maxPageSize = 100,
    ) {}

    /**
     * Paginate a query and return a Connection response.
     *
     * @template T
     * @param mixed $query The source query (e.g., Cycle Select)
     * @param ServerRequestInterface $request HTTP request with query params
     * @param GridSchema $gridSchema Grid schema with filters/sorters/paginator
     * @param callable(mixed): T|null $mapper Optional mapper to transform entities to DTOs
     * @param int|null $totalCount Optional total count for pagination metadata
     * @return Connection<T>
     */
    public function paginate(
        mixed $query,
        ServerRequestInterface $request,
        GridSchema $gridSchema,
        ?callable $mapper = null,
        ?int $totalCount = null,
    ): Connection {
        // Extract pagination params from request
        $queryParams = $request->getQueryParams();

        // Create grid with all filters, sorters, and pagination
        // Query params already have pagination under 'paginate' namespace
        $grid = $this->gridFactory
            ->withInput(new ArrayInput($queryParams))
            ->create($query, $gridSchema);

        // Get the actual paginator state from the grid (includes default limit if not specified in request)
        $paginatorState = $grid->getOption(\Spiral\DataGrid\GridInterface::PAGINATOR) ?? [];

        // Execute query and get results
        $compiledQuery = $grid->getSource();
        $originalEntities = iterator_to_array($grid->getIterator());

        // Apply mapper if provided (cursors will be generated from original entities)
        $mappedResults = $originalEntities;
        if ($mapper !== null) {
            $mappedResults = array_map($mapper, $originalEntities);
        }

        // Create Connection response
        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );

        return $connectionFactory->createConnection(
            results: $mappedResults,
            query: $compiledQuery,  // Use potentially modified query with fallback ORDER BY
            paginatorState: $paginatorState,  // Use paginator state from grid (includes defaults)
            encoder: $this->encoder,
            totalCount: $totalCount,
            originalEntities: $originalEntities,  // Pass original entities for cursor generation
        );
    }

    /**
     * Create a pre-configured cursor paginator.
     *
     * @param int|null $defaultLimit Default page size (overrides helper default)
     * @param int|null $maxLimit Maximum page size (overrides helper max)
     * @return \Cardyo\SpiralCursorPagination\Specification\Pagination\CursorPaginator
     */
    public function createPaginator(?int $defaultLimit = null, ?int $maxLimit = null): mixed
    {
        $defaultLimit = $defaultLimit ?? $this->defaultPageSize;
        $maxLimit = $maxLimit ?? $this->maxPageSize;

        return new \Cardyo\SpiralCursorPagination\Specification\Pagination\CursorPaginator(
            defaultLimit: $defaultLimit,
            limitValue: new \Spiral\DataGrid\Specification\Value\RangeValue(
                new \Spiral\DataGrid\Specification\Value\IntValue(),
                \Spiral\DataGrid\Specification\Value\RangeValue\Boundary::including(1),
                \Spiral\DataGrid\Specification\Value\RangeValue\Boundary::including($maxLimit),
            ),
            cursorCoder: $this->encoder,
        );
    }

    /**
     * Extract pagination input from query parameters.
     *
     * Only supports nested 'paginate' namespace (e.g., ?paginate[first]=10).
     * This is consistent with Spiral DataGrid conventions where filters use
     * ?filter[name]=value and sorts use ?sort[field]=dir.
     *
     * @param array<string, mixed> $queryParams
     * @return array{first?: int, last?: int, after?: string, before?: string}
     */
    private function extractPaginationInput(array $queryParams): array
    {
        $pagination = [];

        // Check for nested 'paginate' parameter (e.g., ?paginate[first]=10)
        if (isset($queryParams['paginate']) && is_array($queryParams['paginate'])) {
            $paginate = $queryParams['paginate'];

            if (isset($paginate['first'])) {
                $pagination['first'] = (int) $paginate['first'];
            }
            if (isset($paginate['last'])) {
                $pagination['last'] = (int) $paginate['last'];
            }
            if (isset($paginate['after'])) {
                $pagination['after'] = (string) $paginate['after'];
            }
            if (isset($paginate['before'])) {
                $pagination['before'] = (string) $paginate['before'];
            }
        }

        return $pagination;
    }

    /**
     * Create a simple paginated response without DataGrid (for manual filtering).
     *
     * Use this when you don't need DataGrid's filter/sorter features and want
     * to handle filtering manually.
     *
     * @template T
     * @param iterable<mixed> $results Already-fetched results (include +1 for hasMore)
     * @param mixed $query Original query (for cursor generation)
     * @param ServerRequestInterface $request HTTP request
     * @param callable(mixed): T|null $mapper Optional mapper
     * @param int|null $totalCount Optional total count
     * @return Connection<T>
     */
    public function paginateResults(
        iterable $results,
        mixed $query,
        ServerRequestInterface $request,
        ?callable $mapper = null,
        ?int $totalCount = null,
    ): Connection {
        $queryParams = $request->getQueryParams();
        $paginationInput = $this->extractPaginationInput($queryParams);

        $results = is_array($results) ? $results : iterator_to_array($results);

        // Apply mapper if provided
        if ($mapper !== null) {
            $results = array_map($mapper, $results);
        }

        $connectionFactory = new ConnectionFactory(
            new CursorGenerator(),
            new PaginationMetadataCalculator(),
        );

        return $connectionFactory->createConnection(
            results: $results,
            query: $query,
            paginatorState: $paginationInput,
            encoder: $this->encoder,
            totalCount: $totalCount,
        );
    }

    /**
     * Get default page size.
     */
    public function getDefaultPageSize(): int
    {
        return $this->defaultPageSize;
    }

    /**
     * Get maximum page size.
     */
    public function getMaxPageSize(): int
    {
        return $this->maxPageSize;
    }
}
