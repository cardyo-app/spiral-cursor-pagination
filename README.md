# Spiral DataGrid Cursor Pagination

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Cursor-based pagination implementation for [Spiral Framework DataGrid](https://github.com/spiral/data-grid), compliant with both the [JSON:API cursor pagination profile](https://jsonapi.org/profiles/ethanresnick/cursor-pagination/) and [GraphQL Relay Connection specification](https://relay.dev/graphql/connections.htm).

## Features

- 🎯 **Dual Standard Compliance**: Implements both JSON:API and GraphQL pagination specifications
- 🔒 **Opaque Cursors**: URL-safe, base64-encoded cursors with version support
- ⚡ **Stable Pagination**: Avoids common offset-based pagination issues (duplicate/missing items)
- 🔄 **Bidirectional**: Supports both forward (`after`) and backward (`before`) pagination
- 🎨 **Deterministic Ordering**: Ensures consistent results across requests
- 🧩 **Framework Integration**: Seamlessly integrates with Spiral DataGrid
- 🛠️ **Cycle ORM Support**: Includes writer for Cycle ORM out of the box
- 📦 **Extensible**: Easy to add support for other data sources

## Installation

```bash
composer require cardyo/spiral-cursor-pagination
```

## Requirements

- PHP 8.1 or higher
- Spiral DataGrid ^3.0

## Quick Start

### 1. Basic Usage with JSON:API Format

```php
use Cardyo\Spiral\DataGrid\CursorPaginator;
use Cardyo\Spiral\DataGrid\CursorEncoder\EncoderV1;

$encoder = new EncoderV1();

$paginator = new CursorPaginator(
    decoder: $encoder,
    defaultLimit: 10,
    allowedLimits: [10, 25, 50, 100],
    sortFields: ['created_at' => 'desc', 'id' => 'asc'],
    uniqueField: 'id'
);

// Add to your Grid Schema
$schema->setPaginator($paginator);
```

### 2. Query Parameters

**JSON:API Format:**
```
GET /api/users?page[size]=25&page[after]=eyJzb3J0X3ZhbHVlcyI6eyJpZCI6MTAwfX0
```

**GraphQL Format:**
```
{
  users(first: 25, after: "eyJzb3J0X3ZhbHVlcyI6eyJpZCI6MTAwfX0") {
    edges {
      node { ... }
      cursor
    }
    pageInfo {
      hasNextPage
      hasPreviousPage
      startCursor
      endCursor
    }
  }
}
```

### 3. Response Formatting

#### JSON:API Response

```php
use Cardyo\Spiral\DataGrid\Formatter\JsonApiFormatter;
use Cardyo\Spiral\DataGrid\Formatter\CursorResults;

$formatter = new JsonApiFormatter(
    baseUrl: '/api/users',
    includePerItemCursors: true
);

$results = new CursorResults(
    rawItems: $items,
    requestedLimit: 10,
    direction: Direction::FORWARD,
    encoder: $encoder,
    sortFields: ['created_at' => 'desc', 'id' => 'asc']
);

$response = $formatter->format($results, $pageSize);
```

**Response:**
```json
{
  "data": [
    {
      "id": "1",
      "type": "users",
      "attributes": {
        "name": "John Doe"
      },
      "meta": {
        "page": {
          "cursor": "eyJzb3J0X3ZhbHVlcyI6eyJpZCI6MX19"
        }
      }
    }
  ],
  "links": {
    "prev": null,
    "next": "/api/users?page[after]=eyJzb3J0X3ZhbHVlcyI6eyJpZCI6MTB9fQ&page[size]=10"
  },
  "meta": {
    "page": {
      "hasNext": true,
      "hasPrevious": false,
      "startCursor": "eyJzb3J0X3ZhbHVlcyI6eyJpZCI6MX19",
      "endCursor": "eyJzb3J0X3ZhbHVlcyI6eyJpZCI6MTB9fQ"
    }
  }
}
```

#### GraphQL Connection Response

```php
use Cardyo\Spiral\DataGrid\Formatter\GraphQLFormatter;

$formatter = new GraphQLFormatter(includeTotalCount: true);
$response = $formatter->format($results);
```

**Response:**
```json
{
  "edges": [
    {
      "node": {
        "id": "1",
        "name": "John Doe"
      },
      "cursor": "eyJzb3J0X3ZhbHVlcyI6eyJpZCI6MX19"
    }
  ],
  "pageInfo": {
    "hasNextPage": true,
    "hasPreviousPage": false,
    "startCursor": "eyJzb3J0X3ZhbHVlcyI6eyJpZCI6MX19",
    "endCursor": "eyJzb3J0X3ZhbHVlcyI6eyJpZCI6MTB9fQ"
  },
  "totalCount": 1000
}
```

## Architecture

### Core Components

#### 1. Cursor Data Structures

- **`CursorData`**: Represents decoded cursor containing sort field values
- **`Direction`**: Enum for forward/backward pagination
- **`PageInfo`**: Pagination metadata (hasNext, hasPrev, cursors)

#### 2. Specifications

- **`CursorAfter`**: Forward pagination cursor
- **`CursorBefore`**: Backward pagination cursor
- **`CursorLimit`**: Page size with N+1 fetching
- **`CursorDirection`**: Pagination direction indicator
- **`CursorSort`**: Deterministic sort order

#### 3. Paginator

**`CursorPaginator`**: Main pagination handler that:
- Processes both JSON:API and GraphQL input formats
- Decodes cursors using `CursorDecoderInterface`
- Generates specifications for writers
- Validates and enforces limits

#### 4. Writers

**`CycleCursorWriter`**: Transforms cursor specifications into Cycle ORM queries

Handles:
- `CursorAfter`: Adds WHERE clause for forward pagination
- `CursorBefore`: Adds WHERE clause for backward pagination
- `CursorLimit`: Applies LIMIT with N+1 pattern
- `CursorSort`: Applies ORDER BY with deterministic ordering

#### 5. Formatters

- **`JsonApiFormatter`**: Formats results per JSON:API profile
- **`GraphQLFormatter`**: Formats results per GraphQL Relay spec

## Advanced Usage

### Custom Data Source Support

Create a custom writer for your data source:

```php
use Cardyo\Spiral\DataGrid\Writer\WriterInterface;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\SpecificationInterface;

class MyCustomCursorWriter implements WriterInterface
{
    public function write(
        mixed $source,
        SpecificationInterface $specification,
        Compiler $compiler
    ): mixed {
        if (!$source instanceof MyDataSource) {
            return null;
        }

        return match (true) {
            $specification instanceof CursorAfter => $this->handleAfter($source, $specification),
            $specification instanceof CursorBefore => $this->handleBefore($source, $specification),
            $specification instanceof CursorLimit => $this->handleLimit($source, $specification),
            $specification instanceof CursorSort => $this->handleSort($source, $specification),
            default => null,
        };
    }

    // Implement handlers...
}
```

### Complex Sorting

```php
$paginator = new CursorPaginator(
    decoder: $encoder,
    defaultLimit: 20,
    sortFields: [
        'priority' => 'desc',    // First sort by priority
        'created_at' => 'desc',  // Then by creation date
        'id' => 'asc',          // Finally by ID (unique field)
    ],
    uniqueField: 'id'
);
```

### Cursor Encoding

Cursors are encoded using base64url with a version prefix:

```php
// Cursor data structure
[
    'sort_values' => [
        'created_at' => '2024-01-15 10:30:00',
        'id' => 12345
    ],
    'direction' => 'forward',
    'version' => 'v1'
]

// Encoded cursor:
// eyJzb3J0X3ZhbHVlcyI6eyJjcmVhdGVkX2F0IjoiMjAyNC0wMS0xNSAxMDozMDowMCIsImlkIjoxMjM0NX0sImRpcmVjdGlvbiI6ImZvcndhcmQiLCJ2ZXJzaW9uIjoidjEifQ
```

## How It Works

### Forward Pagination (after)

1. Decode cursor to get sort field values
2. Build WHERE clause: `(created_at > ? OR (created_at = ? AND id > ?))`
3. Apply sort order: `ORDER BY created_at ASC, id ASC`
4. Fetch N+1 items to detect if more pages exist
5. Return first N items with `hasNextPage` flag

### Backward Pagination (before)

1. Decode cursor to get sort field values
2. Build WHERE clause: `(created_at < ? OR (created_at = ? AND id < ?))`
3. Apply reversed sort: `ORDER BY created_at DESC, id DESC`
4. Fetch N+1 items
5. Reverse results
6. Return last N items with `hasPreviousPage` flag

### N+1 Query Pattern

Always fetches one extra item to determine if more pages exist:
- Request 10 items → Fetch 11
- If 11 items returned → `hasNextPage = true`, return first 10
- If ≤10 items returned → `hasNextPage = false`, return all

## Testing

```bash
# Run tests
composer test

# Run with coverage
vendor/bin/phpunit --coverage-html coverage

# Static analysis
composer psalm

# Code style check
composer ecs

# Mutation testing
composer infection
```

## Configuration Examples

### With Spiral Framework

```php
// app/config/dataGrid.php
return [
    'writers' => [
        \Cardyo\Spiral\DataGrid\Writer\CycleCursorWriter::class,
    ],
];
```

### Complete Grid Schema Example

```php
use Spiral\DataGrid\GridSchema;
use Cardyo\Spiral\DataGrid\CursorPaginator;

class UserSchema extends GridSchema
{
    public function __construct(
        private readonly EncoderV1 $encoder
    ) {
        $this->addFilter('name', new Like('name', new StringValue()));
        $this->addFilter('status', new Equals('status', new EnumValue(['active', 'inactive'])));

        $this->addSorter('name', new Sorter('name'));
        $this->addSorter('created', new Sorter('created_at'));

        $this->setPaginator(new CursorPaginator(
            decoder: $this->encoder,
            defaultLimit: 25,
            allowedLimits: [10, 25, 50, 100],
            sortFields: ['created_at' => 'desc', 'id' => 'asc'],
            uniqueField: 'id',
            maxLimit: 100
        ));
    }
}
```

## Standards Compliance

### JSON:API Cursor Pagination Profile

✅ Query Parameters: `page[size]`, `page[after]`, `page[before]`
✅ Response Links: `prev`, `next`
✅ Metadata: `meta.page` with cursor info
✅ Consistent Ordering: Deterministic sort with unique field
✅ Opaque Cursors: Base64-encoded with version prefix

[Specification](https://jsonapi.org/profiles/ethanresnick/cursor-pagination/)

### GraphQL Relay Connection

✅ Arguments: `first`, `after`, `last`, `before`
✅ Connection Type: `edges`, `pageInfo`
✅ Edge Type: `node`, `cursor`
✅ PageInfo: `hasNextPage`, `hasPreviousPage`, `startCursor`, `endCursor`
✅ Opaque Cursors: Implementation-independent format

[Specification](https://relay.dev/graphql/connections.htm)

## Performance Considerations

### Index Requirements

Ensure composite indexes on sort fields:

```sql
CREATE INDEX idx_users_cursor ON users(created_at, id);
```

### Avoiding Count Queries

Total count is optional and can be expensive. Only include when necessary:

```php
$formatter = new JsonApiFormatter(
    baseUrl: '/api/users',
    includePageMeta: true  // Includes hasNext/hasPrev but not totalCount
);
```

### Cursor Caching

Cursors are stateless and can be cached:

```php
$cursor = $results->getCursorForItem($item);
// Cache cursor with item ID for quick access
```

## Troubleshooting

### "Invalid cursor" Errors

- Verify cursor encoding/decoding matches
- Check for URL encoding issues in transit
- Ensure version prefix compatibility

### Inconsistent Results

- Verify unique field is included in sort
- Check for concurrent data modifications
- Ensure index covers all sort fields

### Performance Issues

- Add composite indexes on sort fields
- Limit maximum page size
- Consider cursor expiration for large datasets

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Credits

- [Leonid Meleshin](https://github.com/leon0399)
- [All Contributors](../../contributors)

## Related

- [Spiral Framework](https://spiral.dev/)
- [Spiral DataGrid](https://github.com/spiral/data-grid)
- [JSON:API](https://jsonapi.org/)
- [GraphQL Cursor Connections Specification](https://relay.dev/graphql/connections.htm)
