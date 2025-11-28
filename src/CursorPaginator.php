<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid;

use Cardyo\Spiral\DataGrid\Cursor\CursorData;
use Cardyo\Spiral\DataGrid\Cursor\Direction;
use Cardyo\Spiral\DataGrid\CursorEncoder\CursorDecoderInterface;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorAfter;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorBefore;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorDirection;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorLimit;
use Cardyo\Spiral\DataGrid\Specification\Cursor\CursorSort;
use Spiral\DataGrid\Specification\FilterInterface;
use Spiral\DataGrid\Specification\SequenceInterface;
use Spiral\DataGrid\SpecificationInterface;

/**
 * Cursor-based paginator for Spiral DataGrid.
 *
 * Implements both JSON:API cursor pagination profile and GraphQL Relay Connection specification.
 *
 * Supports two input formats:
 *
 * 1. JSON:API format:
 *    - page[size]: Number of items per page (default: configured limit)
 *    - page[after]: Cursor for forward pagination
 *    - page[before]: Cursor for backward pagination
 *
 * 2. GraphQL format:
 *    - first: Number of items for forward pagination
 *    - after: Cursor for forward pagination
 *    - last: Number of items for backward pagination
 *    - before: Cursor for backward pagination
 *
 * Example usage:
 * ```php
 * // Configure paginator with default limit and allowed limits
 * $paginator = new CursorPaginator(
 *     encoder: $encoder,
 *     defaultLimit: 10,
 *     allowedLimits: [10, 25, 50, 100],
 *     sortFields: ['created_at' => 'desc', 'id' => 'asc'],
 *     uniqueField: 'id'
 * );
 *
 * // Use in Grid Schema
 * $schema->setPaginator($paginator);
 * ```
 *
 * @see https://jsonapi.org/profiles/ethanresnick/cursor-pagination/
 * @see https://relay.dev/graphql/connections.htm
 */
final class CursorPaginator implements FilterInterface, SequenceInterface
{
    /**
     * @var array<SpecificationInterface>
     */
    private array $specifications = [];

    /**
     * @param CursorDecoderInterface $decoder Cursor decoder for decoding cursor strings
     * @param int $defaultLimit Default number of items per page
     * @param array<int> $allowedLimits List of allowed page sizes (empty array allows any size)
     * @param array<string, string> $sortFields Sort fields with directions (e.g., ['created_at' => 'desc', 'id' => 'asc'])
     * @param string $uniqueField Unique field name for deterministic ordering (typically primary key)
     * @param int $maxLimit Maximum allowed page size (0 for no maximum)
     */
    public function __construct(
        private readonly CursorDecoderInterface $decoder,
        private readonly int $defaultLimit = 10,
        private readonly array $allowedLimits = [],
        private readonly array $sortFields = [],
        private readonly string $uniqueField = 'id',
        private readonly int $maxLimit = 100,
    ) {
        if ($defaultLimit < 1) {
            throw new \InvalidArgumentException('Default limit must be a positive integer');
        }

        if ($maxLimit < 0) {
            throw new \InvalidArgumentException('Max limit must be a non-negative integer');
        }

        if (!empty($allowedLimits)) {
            foreach ($allowedLimits as $limit) {
                if ($limit < 1) {
                    throw new \InvalidArgumentException('All allowed limits must be positive integers');
                }
            }

            if (!\in_array($defaultLimit, $allowedLimits, true)) {
                throw new \InvalidArgumentException('Default limit must be in the list of allowed limits');
            }
        }

        // Ensure sort fields include the unique field
        if (!empty($sortFields) && !isset($sortFields[$uniqueField])) {
            throw new \InvalidArgumentException(\sprintf(
                'Sort fields must include unique field "%s" for deterministic ordering',
                $uniqueField
            ));
        }
    }

    /**
     * Process pagination input and generate specifications.
     *
     * Supports multiple input formats:
     * - JSON:API: ['size' => 10, 'after' => 'cursor...']
     * - GraphQL: ['first' => 10, 'after' => 'cursor...']
     * - Mixed: ['size' => 10, 'first' => 15] (uses first available in priority order)
     *
     * @param mixed $value Pagination parameters
     * @return SpecificationInterface|null Returns self with populated specifications, or null if value is invalid
     */
    public function withValue(mixed $value): ?SpecificationInterface
    {
        if (!\is_array($value)) {
            return null;
        }

        $this->specifications = [];

        // Determine pagination direction and limit
        $direction = $this->determineDirection($value);
        $limit = $this->determineLimit($value, $direction);

        // Add limit specification
        $this->specifications[] = new CursorLimit($limit, true);

        // Add direction specification
        $this->specifications[] = new CursorDirection($direction);

        // Add cursor specification if present
        $cursorString = $this->extractCursor($value, $direction);
        if ($cursorString !== null) {
            $cursorData = $this->decodeCursor($cursorString);
            if ($cursorData !== null) {
                if ($direction === Direction::FORWARD) {
                    $this->specifications[] = new CursorAfter($cursorData);
                } else {
                    $this->specifications[] = new CursorBefore($cursorData);
                }
            }
        }

        // Add sort specification if configured
        if (!empty($this->sortFields)) {
            $sortFields = $direction === Direction::BACKWARD
                ? $this->reverseSortFields()
                : $this->sortFields;

            $this->specifications[] = new CursorSort($sortFields, $this->uniqueField);
        }

        return $this;
    }

    /**
     * Get all specifications generated by this paginator.
     *
     * @return array<SpecificationInterface>
     */
    public function getSpecifications(): array
    {
        return $this->specifications;
    }

    /**
     * Get the current pagination parameters.
     *
     * @return array{limit: int, direction: string, cursor?: string}
     */
    public function getValue(): array
    {
        $value = [
            'limit' => $this->defaultLimit,
            'direction' => Direction::FORWARD->value,
        ];

        foreach ($this->specifications as $spec) {
            if ($spec instanceof CursorLimit) {
                $value['limit'] = $spec->limit;
            } elseif ($spec instanceof CursorDirection) {
                $value['direction'] = $spec->direction->value;
            } elseif ($spec instanceof CursorAfter || $spec instanceof CursorBefore) {
                // We can't reconstruct the cursor string here without an encoder
                $value['cursor'] = '[cursor]';
            }
        }

        return $value;
    }

    /**
     * Determine the pagination direction from input parameters.
     */
    private function determineDirection(array $value): Direction
    {
        // GraphQL format: 'before' or 'last' indicates backward pagination
        if (isset($value['before']) || (isset($value['last']) && !isset($value['first']))) {
            return Direction::BACKWARD;
        }

        // JSON:API format: 'page[before]' indicates backward pagination
        // Default to forward pagination
        return Direction::FORWARD;
    }

    /**
     * Determine the page size limit from input parameters.
     */
    private function determineLimit(array $value, Direction $direction): int
    {
        // Priority order for determining limit:
        // 1. 'first' or 'last' (GraphQL format)
        // 2. 'size' (JSON:API format)
        // 3. Default limit

        $limit = null;

        if ($direction === Direction::FORWARD && isset($value['first'])) {
            $limit = (int) $value['first'];
        } elseif ($direction === Direction::BACKWARD && isset($value['last'])) {
            $limit = (int) $value['last'];
        } elseif (isset($value['size'])) {
            $limit = (int) $value['size'];
        }

        $limit = $limit ?? $this->defaultLimit;

        // Validate limit
        $limit = $this->validateLimit($limit);

        return $limit;
    }

    /**
     * Validate and adjust the limit according to constraints.
     */
    private function validateLimit(int $limit): int
    {
        // Ensure positive
        if ($limit < 1) {
            return $this->defaultLimit;
        }

        // Check against allowed limits
        if (!empty($this->allowedLimits) && !\in_array($limit, $this->allowedLimits, true)) {
            // Find the closest allowed limit or use default
            return $this->defaultLimit;
        }

        // Check against max limit
        if ($this->maxLimit > 0 && $limit > $this->maxLimit) {
            return $this->maxLimit;
        }

        return $limit;
    }

    /**
     * Extract cursor string from input parameters.
     */
    private function extractCursor(array $value, Direction $direction): ?string
    {
        if ($direction === Direction::FORWARD) {
            return $value['after'] ?? null;
        }

        return $value['before'] ?? null;
    }

    /**
     * Decode cursor string into CursorData.
     */
    private function decodeCursor(string $cursorString): ?CursorData
    {
        try {
            $decoded = $this->decoder->decode($cursorString);
            if (!\is_array($decoded)) {
                return null;
            }

            return CursorData::fromArray($decoded);
        } catch (\Throwable) {
            // Invalid cursor, return null to ignore it
            return null;
        }
    }

    /**
     * Reverse sort fields for backward pagination.
     *
     * @return array<string, string>
     */
    private function reverseSortFields(): array
    {
        $reversed = [];
        foreach ($this->sortFields as $field => $direction) {
            $reversed[$field] = \strtolower($direction) === 'asc' ? 'desc' : 'asc';
        }
        return $reversed;
    }
}
