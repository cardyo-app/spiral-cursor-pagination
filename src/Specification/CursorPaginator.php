<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Specification;

use Cardyo\SpiralCursorPagination\Cursor\CursorData;
use Cardyo\SpiralCursorPagination\CursorEncoder\CursorDecoderInterface;
use Cardyo\SpiralCursorPagination\CursorEncoder\CursorEncoderInterface;
use Cardyo\SpiralCursorPagination\Specification\Cursor\CursorLimit;
use Cardyo\SpiralCursorPagination\Specification\Cursor\KeysetFilter;
use Cardyo\SpiralCursorPagination\Specification\Cursor\SortDirection;
use Spiral\DataGrid\Specification\FilterInterface;
use Spiral\DataGrid\Specification\SequenceInterface;
use Spiral\DataGrid\Specification\ValueInterface;
use Spiral\DataGrid\SpecificationInterface;

/**
 * GraphQL-inspired Complete Connection Model-like cursor paginator.
 */
final class CursorPaginator implements SequenceInterface, FilterInterface
{
    private ?int $first = null;

    private ?CursorData $after = null;

    private ?string $afterEncoded = null;

    private ?int $last = null;

    private ?CursorData $before = null;

    private ?string $beforeEncoded = null;

    /**
     * @var array<string> Fields used for cursor-based sorting
     */
    private array $sortFields = ['id'];

    public function __construct(
        private readonly int $defaultLimit,
        private readonly ValueInterface $limitValue,
        public readonly CursorDecoderInterface&CursorEncoderInterface $cursorCoder,
    ) {}

    #[\Override]
    public function withValue(mixed $value): SpecificationInterface
    {
        $paginator = clone $this;
        if (!\is_array($value)) {
            return $paginator;
        }

        // Validate key combinations - if invalid, fallback to defaults
        $hasInvalidCombination = (isset($value['first']) && isset($value['last']))
            || (isset($value['after']) && isset($value['before']))
            || (isset($value['after']) && isset($value['last']))
            || (isset($value['before']) && isset($value['first']));

        if ($hasInvalidCombination) {
            // Invalid combination - fallback to default
            $paginator->first = $this->defaultLimit;
            return $paginator;
        }

        // Determine which limit to use and validate it
        $hasValidFirst = isset($value['first']) && $paginator->limitValue->accepts($value['first']);
        $hasValidLast = isset($value['last']) && $paginator->limitValue->accepts($value['last']);

        // Apply default limit if no valid limit is provided
        if (!$hasValidFirst && !$hasValidLast) {
            // If 'before' cursor is present, use 'last' with default limit
            if (isset($value['before'])) {
                $paginator->last = $this->defaultLimit;
                $paginator->before = $paginator->cursorCoder->decodeCursor($value['before']);
                $paginator->beforeEncoded = $value['before'];
            } else {
                // Otherwise, use 'first' with default limit
                $paginator->first = $this->defaultLimit;
                if (isset($value['after'])) {
                    $paginator->after = $paginator->cursorCoder->decodeCursor($value['after']);
                    $paginator->afterEncoded = $value['after'];
                }
            }
        } elseif ($hasValidFirst) {
            $paginator->first = $paginator->limitValue->convert($value['first']);

            if (isset($value['after'])) {
                $decoded = $paginator->cursorCoder->decodeCursor($value['after']);
                $paginator->after = $decoded instanceof CursorData ? $decoded : null;
                $paginator->afterEncoded = $value['after'];
            }
        } elseif ($hasValidLast) {
            $paginator->last = $paginator->limitValue->convert($value['last']);

            if (isset($value['before'])) {
                $decoded = $paginator->cursorCoder->decodeCursor($value['before']);
                $paginator->before = $decoded instanceof CursorData ? $decoded : null;
                $paginator->beforeEncoded = $value['before'];
            }
        }

        return $paginator;
    }

    /**
     * Configure the fields used for cursor-based sorting.
     *
     * @param array<string> $fields Field names in sort priority order
     * @return self New instance with configured sort fields
     */
    public function withSortFields(array $fields): self
    {
        $paginator = clone $this;
        $paginator->sortFields = $fields;
        return $paginator;
    }

    /**
     * Get the configured sort fields.
     *
     * @return array<string>
     */
    public function getSortFields(): array
    {
        return $this->sortFields;
    }

    /**
     * Check if this is backward pagination (using last/before).
     */
    private function isBackward(): bool
    {
        return $this->last !== null;
    }

    #[\Override]
    public function getSpecifications(): array
    {
        $limit = $this->first ?? $this->last ?? $this->defaultLimit;
        $specs = [];

        // Add limit with extra fetch for hasNext/hasPrev detection
        $specs[] = new CursorLimit($limit, fetchExtra: true);

        // Add keyset filter if cursor is present
        if ($this->after instanceof CursorData) {
            $specs[] = new KeysetFilter(
                $this->after,
                KeysetFilter::DIRECTION_FORWARD,
                $this->sortFields,
            );
        } elseif ($this->before instanceof CursorData) {
            $specs[] = new KeysetFilter(
                $this->before,
                KeysetFilter::DIRECTION_BACKWARD,
                $this->sortFields,
            );
        }

        // Add sort direction (reversed for backward pagination)
        $specs[] = new SortDirection($this->sortFields, $this->isBackward());

        return $specs;
    }

    #[\Override]
    public function getValue(): array
    {
        $value = [];

        if ($this->first !== null) {
            $value['first'] = $this->first;
        } elseif ($this->last !== null) {
            $value['last'] = $this->last;
        } else {
            $value['first'] = $this->defaultLimit;
        }

        if ($this->afterEncoded !== null) {
            $value['after'] = $this->afterEncoded;
        } elseif ($this->beforeEncoded !== null) {
            $value['before'] = $this->beforeEncoded;
        }

        return $value;
    }
}
