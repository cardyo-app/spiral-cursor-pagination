<?php

declare(strict_types=1);

/**
 * GraphQL Usage Example
 *
 * This example demonstrates how to use cursor pagination
 * with GraphQL Relay Connection format.
 */

use Cardyo\Spiral\DataGrid\Cursor\Direction;
use Cardyo\Spiral\DataGrid\CursorEncoder\EncoderV1;
use Cardyo\Spiral\DataGrid\CursorPaginator;
use Cardyo\Spiral\DataGrid\Formatter\CursorResults;
use Cardyo\Spiral\DataGrid\Formatter\GraphQLFormatter;

// 1. Set up encoder and paginator
$encoder = new EncoderV1();

$paginator = new CursorPaginator(
    decoder: $encoder,
    defaultLimit: 20,
    sortFields: [
        'created_at' => 'desc',
        'id' => 'asc',
    ],
    uniqueField: 'id'
);

// 2. GraphQL Schema Definition Example
/*
type User {
  id: ID!
  name: String!
  email: String!
  createdAt: DateTime!
}

type UserEdge {
  node: User!
  cursor: String!
}

type PageInfo {
  hasNextPage: Boolean!
  hasPreviousPage: Boolean!
  startCursor: String
  endCursor: String
}

type UserConnection {
  edges: [UserEdge!]!
  pageInfo: PageInfo!
  totalCount: Int
}

type Query {
  users(
    first: Int
    after: String
    last: Int
    before: String
  ): UserConnection!
}
*/

// 3. GraphQL Resolver Implementation
class UserResolver
{
    public function users(
        mixed $root,
        array $args,
        mixed $context,
        mixed $info
    ): array {
        // Extract pagination arguments
        $first = $args['first'] ?? null;
        $after = $args['after'] ?? null;
        $last = $args['last'] ?? null;
        $before = $args['before'] ?? null;

        // Determine direction and limit
        $direction = ($before !== null || $last !== null)
            ? Direction::BACKWARD
            : Direction::FORWARD;

        $limit = $first ?? $last ?? 20;

        // Get data source (Cycle ORM Select, etc.)
        $items = $this->fetchUsers($after, $before, $limit);

        // Create cursor results
        $results = new CursorResults(
            rawItems: $items,
            requestedLimit: $limit,
            direction: $direction,
            encoder: new EncoderV1(),
            sortFields: ['created_at' => 'desc', 'id' => 'asc'],
            totalCount: $this->getUserCount() // Optional
        );

        // Format as GraphQL Connection
        $formatter = new GraphQLFormatter(includeTotalCount: true);

        return $formatter->format($results);
    }

    private function fetchUsers(?string $after, ?string $before, int $limit): array
    {
        // Implementation depends on your data source
        // Use Spiral DataGrid with CursorPaginator here
        return [];
    }

    private function getUserCount(): int
    {
        // Optional: return total count if needed
        return 1000;
    }
}

// Example GraphQL Queries:

// Forward pagination (first page)
/*
{
  users(first: 10) {
    edges {
      node {
        id
        name
        email
        createdAt
      }
      cursor
    }
    pageInfo {
      hasNextPage
      hasPreviousPage
      startCursor
      endCursor
    }
    totalCount
  }
}
*/

// Forward pagination (next page)
/*
{
  users(first: 10, after: "eyJzb3J0X3ZhbHVlcyI6eyJjcmVhdGVkX2F0IjoiMjAyNC0wMS0xNSIsImlkIjoxMH19") {
    edges {
      node {
        id
        name
        email
      }
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
*/

// Backward pagination
/*
{
  users(last: 10, before: "eyJzb3J0X3ZhbHVlcyI6eyJjcmVhdGVkX2F0IjoiMjAyNC0wMS0xNSIsImlkIjoxfX0") {
    edges {
      node {
        id
        name
        email
      }
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
*/

// Example Response:
/*
{
  "data": {
    "users": {
      "edges": [
        {
          "node": {
            "id": "1",
            "name": "John Doe",
            "email": "john@example.com",
            "createdAt": "2024-01-15T10:30:00Z"
          },
          "cursor": "eyJzb3J0X3ZhbHVlcyI6eyJjcmVhdGVkX2F0IjoiMjAyNC0wMS0xNSIsImlkIjoxfX0"
        },
        {
          "node": {
            "id": "2",
            "name": "Jane Smith",
            "email": "jane@example.com",
            "createdAt": "2024-01-14T15:20:00Z"
          },
          "cursor": "eyJzb3J0X3ZhbHVlcyI6eyJjcmVhdGVkX2F0IjoiMjAyNC0wMS0xNCIsImlkIjoyfX0"
        }
      ],
      "pageInfo": {
        "hasNextPage": true,
        "hasPreviousPage": false,
        "startCursor": "eyJzb3J0X3ZhbHVlcyI6eyJjcmVhdGVkX2F0IjoiMjAyNC0wMS0xNSIsImlkIjoxfX0",
        "endCursor": "eyJzb3J0X3ZhbHVlcyI6eyJjcmVhdGVkX2F0IjoiMjAyNC0wMS0xNCIsImlkIjoyfX0"
      },
      "totalCount": 1000
    }
  }
}
*/

// 4. Using with Custom Node Transformer
class UserResolverWithTransformer
{
    public function users(mixed $root, array $args): array
    {
        $results = new CursorResults(/* ... */);

        $formatter = new GraphQLFormatter();

        // Transform each node before including in response
        return $formatter->formatWithTransformer(
            $results,
            function (mixed $user): array {
                return [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'createdAt' => $user->created_at->format('c'),
                    // Add computed fields
                    'fullName' => $user->first_name . ' ' . $user->last_name,
                    'avatarUrl' => $this->generateAvatarUrl($user),
                ];
            }
        );
    }

    private function generateAvatarUrl(mixed $user): string
    {
        return "https://avatars.example.com/{$user->id}";
    }
}
