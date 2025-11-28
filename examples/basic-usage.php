<?php

declare(strict_types=1);

/**
 * Basic Usage Example
 *
 * This example demonstrates how to set up cursor pagination
 * with Spiral DataGrid for a simple user listing API.
 */

use Cardyo\Spiral\DataGrid\CursorEncoder\EncoderV1;
use Cardyo\Spiral\DataGrid\CursorPaginator;
use Cardyo\Spiral\DataGrid\Formatter\CursorResults;
use Cardyo\Spiral\DataGrid\Formatter\JsonApiFormatter;
use Cycle\ORM\Select;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Filter\Equals;
use Spiral\DataGrid\Specification\Filter\Like;
use Spiral\DataGrid\Specification\Sorter\Sorter;
use Spiral\DataGrid\Specification\Value\StringValue;

// 1. Create encoder instance
$encoder = new EncoderV1();

// 2. Configure cursor paginator
$paginator = new CursorPaginator(
    decoder: $encoder,
    defaultLimit: 10,                      // Default page size
    allowedLimits: [10, 25, 50, 100],     // Allowed page sizes
    sortFields: [
        'created_at' => 'desc',            // Primary sort field
        'id' => 'asc',                     // Unique field for deterministic ordering
    ],
    uniqueField: 'id',                     // Field that guarantees uniqueness
    maxLimit: 100                          // Maximum page size
);

// 3. Create Grid Schema with cursor pagination
$schema = new GridSchema();

// Add filters
$schema->addFilter('name', new Like('name', new StringValue()));
$schema->addFilter('email', new Like('email', new StringValue()));
$schema->addFilter('status', new Equals('status', new StringValue()));

// Add sorters
$schema->addSorter('name', new Sorter('name'));
$schema->addSorter('created', new Sorter('created_at'));

// Set cursor paginator
$schema->setPaginator($paginator);

// 4. Usage in API Controller
class UserController
{
    public function index(GridSchema $schema): array
    {
        // Get Cycle ORM query
        $select = new Select(/* ... */);

        // Apply grid specifications to query
        // $grid = $gridFactory->create($select, $schema);

        // Get results
        $items = []; // Results from $grid

        // 5. Format response with CursorResults
        $results = new CursorResults(
            rawItems: $items,
            requestedLimit: 10,
            direction: \Cardyo\Spiral\DataGrid\Cursor\Direction::FORWARD,
            encoder: $encoder,
            sortFields: ['created_at' => 'desc', 'id' => 'asc']
        );

        // 6. Format as JSON:API response
        $formatter = new JsonApiFormatter(
            baseUrl: '/api/users',
            includePerItemCursors: true,  // Include cursor in each item
            includePageMeta: true         // Include pagination metadata
        );

        return $formatter->format($results, 10);
    }
}

// Example Query Parameters:
//
// Initial request (first page):
// GET /api/users?page[size]=10
//
// Next page:
// GET /api/users?page[size]=10&page[after]=eyJzb3J0X3ZhbHVlcyI6eyJjcmVhdGVkX2F0IjoiMjAyNC0wMS0xNSIsImlkIjoxMH19
//
// Previous page:
// GET /api/users?page[size]=10&page[before]=eyJzb3J0X3ZhbHVlcyI6eyJjcmVhdGVkX2F0IjoiMjAyNC0wMS0xNSIsImlkIjoxfX0
//
// With filtering:
// GET /api/users?page[size]=25&filter[status]=active&sort[created]=desc

// Example Response:
/*
{
  "data": [
    {
      "id": "1",
      "type": "users",
      "attributes": {
        "name": "John Doe",
        "email": "john@example.com",
        "status": "active",
        "created_at": "2024-01-15T10:30:00Z"
      },
      "meta": {
        "page": {
          "cursor": "eyJzb3J0X3ZhbHVlcyI6eyJjcmVhdGVkX2F0IjoiMjAyNC0wMS0xNSIsImlkIjoxfX0"
        }
      }
    },
    ...
  ],
  "links": {
    "prev": null,
    "next": "/api/users?page[after]=eyJzb3J0X3ZhbHVlcyI6eyJjcmVhdGVkX2F0IjoiMjAyNC0wMS0xNSIsImlkIjoxMH19&page[size]=10"
  },
  "meta": {
    "page": {
      "hasNext": true,
      "hasPrevious": false,
      "startCursor": "eyJzb3J0X3ZhbHVlcyI6eyJjcmVhdGVkX2F0IjoiMjAyNC0wMS0xNSIsImlkIjoxfX0",
      "endCursor": "eyJzb3J0X3ZhbHVlcyI6eyJjcmVhdGVkX2F0IjoiMjAyNC0wMS0xNSIsImlkIjoxMH19"
    }
  }
}
*/
