<?php

declare(strict_types=1);

/**
 * Example: Dynamic Sorting with UUID and User-Controlled Sort Fields
 *
 * This example demonstrates how to implement cursor pagination with:
 * - Non-incremental unique field (UUID instead of auto-increment ID)
 * - Dynamic user-controlled sorting (recently active vs. least active users)
 * - Integration with Spiral DataGrid sorters
 *
 * Use Case:
 * - Table: customers
 * - Fields: uuid (primary key), created_at, last_active_at
 * - Requirements: Users can sort by recently active OR least active users
 */

use Cardyo\Spiral\DataGrid\CursorEncoder\EncoderV1;
use Cardyo\Spiral\DataGrid\CursorPaginator;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorSortAdapter;
use Spiral\DataGrid\GridFactory;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Sorter\Sorter;

// 1. Create encoder for cursor encoding/decoding
$encoder = new EncoderV1();

// 2. Configure paginator WITHOUT static sortFields
//    This allows dynamic sorting based on user input
$paginator = new CursorPaginator(
    decoder: $encoder,
    defaultLimit: 25,
    allowedLimits: [10, 25, 50, 100],
    sortFields: [], // Empty - sorting comes from user-controlled sorters
    uniqueField: 'uuid', // Use UUID as unique field (not incremental ID)
);

// 3. Create user-controlled sorter for "last_active_at" field
//    Users can toggle between ASC (least active) and DESC (recently active)
$lastActiveSorter = new Sorter('last_active_at');

// 4. Wrap the sorter with CursorSortAdapter to ensure UUID is always appended
//    This maintains cursor stability while allowing dynamic sorting
$cursorAwareSorter = new CursorSortAdapter(
    sorter: $lastActiveSorter,
    uniqueField: 'uuid',
    uniqueDirection: 'asc', // UUID always sorted ascending for consistency
);

// 5. Configure Grid Schema with paginator and sorter
$schema = new GridSchema();
$schema->setPaginator($paginator);
$schema->addSorter('last_active', $cursorAwareSorter);

// 6. Create Grid with the schema
$gridFactory = new GridFactory();

// Assuming you have a Cycle ORM Select for customers
// $customersSelect = $orm->getRepository(Customer::class)->select();
// $grid = $gridFactory->create($customersSelect, $schema);

// 7. Example API requests and resulting SQL

/*
 * Request 1: Recently active users (DESC)
 * GET /api/customers?page[size]=25&page[after]=<cursor>&sort=-last_active
 *
 * Resulting ORDER BY:
 * ORDER BY last_active_at DESC, uuid ASC
 *
 * Cursor contains: ['last_active_at' => '2025-01-15 10:30:00', 'uuid' => 'a1b2c3...']
 */

/*
 * Request 2: Least active users (ASC)
 * GET /api/customers?page[size]=25&page[after]=<cursor>&sort=last_active
 *
 * Resulting ORDER BY:
 * ORDER BY last_active_at ASC, uuid ASC
 *
 * Cursor contains: ['last_active_at' => '2024-06-10 08:15:00', 'uuid' => 'd4e5f6...']
 */

/*
 * Request 3: Backward pagination (recently active, going back)
 * GET /api/customers?page[size]=25&page[before]=<cursor>&sort=-last_active
 *
 * Query modifications for backward pagination:
 * - WHERE (last_active_at < ? OR (last_active_at = ? AND uuid < ?))
 * - ORDER BY last_active_at ASC, uuid ASC (reversed!)
 * - Results are then reversed again before returning
 */

// 8. Processing requests
$input = [
    'size' => 25,
    'after' => 'eyJzb3J0X3ZhbHVlcyI6eyJsYXN0X2FjdGl2ZV9hdCI6IjIwMjUtMDEtMTUgMTA6MzA6MDAiLCJ1dWlkIjoiYTFiMmMzLi4uIn19',
    // User-selected sort direction comes from query parameter
    'sort' => '-last_active', // '-' prefix means DESC
];

// The Grid automatically processes the input
// $result = $grid->getIterator($input);

// 9. Response format (JSON:API)
$response = [
    'data' => [
        [
            'type' => 'customers',
            'id' => 'uuid-1',
            'attributes' => [
                'created_at' => '2024-01-15T10:00:00Z',
                'last_active_at' => '2025-01-20T15:30:00Z',
            ],
            'meta' => [
                'page' => [
                    'cursor' => 'eyJzb3J0X3ZhbHVlcyI6eyJsYXN0X2FjdGl2ZV9hdCI6IjIwMjUtMDEtMjAgMTU6MzA6MDAiLCJ1dWlkIjoidXVpZC0xIn19',
                ],
            ],
        ],
        // ... more customers
    ],
    'links' => [
        'prev' => null,
        'next' => '/api/customers?page[size]=25&page[after]=eyJzb3J0...&sort=-last_active',
    ],
    'meta' => [
        'page' => [
            'hasNext' => true,
            'hasPrevious' => false,
        ],
    ],
];

/**
 * Key Benefits:
 *
 * 1. Non-incremental Primary Key Support:
 *    - Works with UUIDs, composite keys, or any unique field
 *    - No dependency on auto-increment IDs
 *
 * 2. Dynamic User-Controlled Sorting:
 *    - Users can change sort direction (ASC/DESC) without breaking pagination
 *    - Cursors automatically adjust to the selected sort order
 *    - Same endpoint supports multiple sort use cases
 *
 * 3. Cursor Stability:
 *    - UUID is always appended as the final sort field
 *    - Ensures deterministic ordering even with duplicate last_active_at values
 *    - Prevents missing or duplicate items during pagination
 *
 * 4. Backward Pagination:
 *    - Supports both forward (after) and backward (before) navigation
 *    - Automatic sort reversal for backward queries
 *    - Consistent results in both directions
 *
 * 5. Multiple Sort Fields:
 *    - Easy to add more sort options (created_at, name, etc.)
 *    - Each sorter automatically gets UUID appended via CursorSortAdapter
 */

// Example: Adding multiple sorters
$schema->addSorter('created', new CursorSortAdapter(
    sorter: new Sorter('created_at'),
    uniqueField: 'uuid',
    uniqueDirection: 'asc',
));

$schema->addSorter('name', new CursorSortAdapter(
    sorter: new Sorter('name'),
    uniqueField: 'uuid',
    uniqueDirection: 'asc',
));

// Now users can sort by:
// - sort=created (oldest first)
// - sort=-created (newest first)
// - sort=name (A-Z)
// - sort=-name (Z-A)
// - sort=last_active (least active)
// - sort=-last_active (recently active)

// All with stable cursor pagination using UUID!
