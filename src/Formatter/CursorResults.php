<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Formatter;

use Cardyo\Spiral\DataGrid\Cursor\CursorData;
use Cardyo\Spiral\DataGrid\Cursor\Direction;
use Cardyo\Spiral\DataGrid\Cursor\PageInfo;
use Cardyo\Spiral\DataGrid\CursorEncoder\CursorEncoderInterface;

/**
 * Default implementation of CursorResultsInterface.
 *
 * Wraps a result set and provides cursor pagination metadata and per-item cursor generation.
 */
final class CursorResults implements CursorResultsInterface
{
    /**
     * @var array<mixed>
     */
    private readonly array $items;

    /**
     * @param iterable<mixed> $rawItems Raw result items (may include N+1 extra item)
     * @param int $requestedLimit The requested page size (without N+1)
     * @param Direction $direction The pagination direction
     * @param CursorEncoderInterface $encoder Cursor encoder for generating cursors
     * @param array<string, string> $sortFields Sort fields with directions for cursor generation
     * @param int|null $totalCount Optional total count of all items
     */
    public function __construct(
        iterable $rawItems,
        private readonly int $requestedLimit,
        private readonly Direction $direction,
        private readonly CursorEncoderInterface $encoder,
        private readonly array $sortFields,
        private readonly ?int $totalCount = null,
    ) {
        // Convert to array and handle N+1 logic
        $this->items = $this->processItems($rawItems);
    }

    public function getItems(): iterable
    {
        return $this->items;
    }

    public function getPageInfo(): PageInfo
    {
        $itemCount = \count($this->items);
        $hasMore = $itemCount > $this->requestedLimit;

        // Determine hasNextPage and hasPreviousPage based on direction and item count
        if ($this->direction === Direction::FORWARD) {
            $hasNextPage = $hasMore;
            $hasPreviousPage = false; // Cannot determine without additional query
        } else {
            $hasNextPage = false; // Cannot determine without additional query
            $hasPreviousPage = $hasMore;
        }

        // Get cursors for first and last items (excluding the extra item if present)
        $displayItems = $hasMore ? \array_slice($this->items, 0, $this->requestedLimit) : $this->items;
        $startCursor = !empty($displayItems) ? $this->getCursorForItem($displayItems[0]) : null;
        $endCursor = !empty($displayItems) ? $this->getCursorForItem($displayItems[\count($displayItems) - 1]) : null;

        return new PageInfo(
            hasNextPage: $hasNextPage,
            hasPreviousPage: $hasPreviousPage,
            startCursor: $startCursor,
            endCursor: $endCursor,
            totalCount: $this->totalCount,
        );
    }

    public function getCursorForItem(mixed $item): ?string
    {
        if ($item === null) {
            return null;
        }

        $sortValues = [];

        foreach ($this->sortFields as $field => $direction) {
            $value = $this->extractFieldValue($item, $field);

            if ($value === null) {
                return null; // Cannot generate cursor without all sort field values
            }

            $sortValues[$field] = $value;
        }

        if (empty($sortValues)) {
            return null;
        }

        $cursorData = new CursorData(
            sortValues: $sortValues,
            direction: $this->direction,
        );

        try {
            return $this->encoder->encode($cursorData->toArray());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Process raw items, handling N+1 logic.
     *
     * @param iterable<mixed> $rawItems
     * @return array<mixed>
     */
    private function processItems(iterable $rawItems): array
    {
        $items = \is_array($rawItems) ? $rawItems : \iterator_to_array($rawItems);

        // If we have more items than requested, we fetched N+1
        // Keep all items for now (PageInfo will determine hasNext/hasPrev)
        // Formatters should use PageInfo to determine what to return

        return $items;
    }

    /**
     * Extract field value from an item.
     *
     * Supports both array access and object property access.
     *
     * @param mixed $item The item to extract from
     * @param string $field The field name
     * @return mixed The field value or null if not found
     */
    private function extractFieldValue(mixed $item, string $field): mixed
    {
        // Array access
        if (\is_array($item)) {
            return $item[$field] ?? null;
        }

        // Object property access
        if (\is_object($item)) {
            // Try direct property access
            if (property_exists($item, $field)) {
                return $item->$field;
            }

            // Try getter method
            $getter = 'get' . \ucfirst($field);
            if (method_exists($item, $getter)) {
                return $item->$getter();
            }

            // Try ArrayAccess
            if ($item instanceof \ArrayAccess && isset($item[$field])) {
                return $item[$field];
            }
        }

        return null;
    }
}
