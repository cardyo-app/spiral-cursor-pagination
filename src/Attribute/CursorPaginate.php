<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Attribute;

use Attribute;

/**
 * Attribute for automatic cursor pagination in controller methods.
 *
 * Similar to DataGrid attribute, this intercepts controller methods that return Select queries
 * and automatically applies cursor pagination.
 *
 * Usage:
 * ```php
 * #[CursorPaginate(
 *     schema: CustomerGridSchema::class,
 *     view: [CustomerDTO::class, 'fromEntity'],
 *     countTotal: true
 * )]
 * public function index(TenantUserInterface $user): Select {
 *     return $this->customers
 *         ->forTenant($user->getTenantId())
 *         ->select();
 * }
 * ```
 *
 * IMPORTANT: Your GridSchema MUST have a CursorPaginator configured:
 * ```php
 * class CustomerGridSchema extends GridSchema {
 *     public function __construct(CursorPaginationHelper $helper) {
 *         $this->setPaginator($helper->createPaginator(defaultLimit: 20, maxLimit: 100));
 *         $this->addSorter('name', new Sorter('name'));
 *         // ... other configuration
 *     }
 * }
 * ```
 *
 * The interceptor will:
 * - Execute the controller to get the Select query
 * - Validate that GridSchema has a CursorPaginator configured
 * - Apply the GridSchema filters/sorting from the request
 * - Apply cursor pagination
 * - Map results through the view/mapper if provided
 * - Return a Connection with paginated results
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class CursorPaginate
{
    /**
     * @param class-string $schema GridSchema class name (REQUIRED - must have CursorPaginator configured)
     * @param callable|array|string|null $view View/mapper to transform results (similar to DataGrid's view)
     * @param bool $countTotal Whether to count total records (expensive operation)
     * @param array<string, mixed> $options Response options (status, property, includeNodes, etc.)
     */
    public function __construct(
        public readonly string $schema,
        public readonly mixed $view = null,
        public readonly bool $countTotal = false,
        public readonly array $options = [],
    ) {}
}
