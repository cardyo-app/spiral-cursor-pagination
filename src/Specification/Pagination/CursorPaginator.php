<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Specification\Pagination;

use Cardyo\SpiralCursorPagination\Cursor\CursorDataInterface;
use Cardyo\SpiralCursorPagination\Cursor\CursorDirection;
use Cardyo\SpiralCursorPagination\CursorEncoder\CursorDecoderInterface;
use Cardyo\SpiralCursorPagination\CursorEncoder\CursorEncoderInterface;
use Cardyo\SpiralCursorPagination\Specification\Cursor\CursorLimit;
use Cardyo\SpiralCursorPagination\Specification\Cursor\KeysetFilter;
use Cardyo\SpiralCursorPagination\Specification\Cursor\SortDirection;
use InvalidArgumentException;
use Spiral\DataGrid\Specification\FilterInterface;
use Spiral\DataGrid\Specification\SequenceInterface;
use Spiral\DataGrid\Specification\ValueInterface;
use Spiral\DataGrid\SpecificationInterface;

/**
 * Cursor-based paginator for Spiral DataGrid.
 *
 * Implements cursor pagination following the JSON:API cursor pagination profile
 * and GraphQL Relay Connection specification.
 *
 * Supports both forward pagination (first/after) and backward pagination (last/before).
 * Uses keyset filtering for efficient pagination without OFFSET.
 *
 * @see https://jsonapi.org/profiles/ethanresnick/cursor-pagination/
 * @see https://relay.dev/graphql/connections.htm
 */
class CursorPaginator implements FilterInterface, SequenceInterface
{
    private CursorDirection $direction;
    private int $limit;
    private ?CursorDataInterface $cursorData = null;

    /**
     * @var array<string, string> Field name => direction ('ASC' or 'DESC')
     */
    private array $sortFields = [];

    public function __construct(
        private readonly int $defaultLimit,
        private readonly ValueInterface $limitValue,
        private readonly CursorDecoderInterface&CursorEncoderInterface $cursorCoder,
    ) {
        if ($defaultLimit < 1) {
            throw new InvalidArgumentException('Default limit must be a positive integer');
        }

        if (!$this->limitValue->accepts($defaultLimit)) {
            throw new InvalidArgumentException('Default limit must be one of the allowed limits');
        }

        $this->direction = CursorDirection::FORWARD;
        $this->limit = $defaultLimit;
    }

    /**
     * Configure sort fields for cursor generation.
     *
     * @param array<string, string> $sortFields Field name => direction ('ASC' or 'DESC')
     * @return self New instance with configured sort fields
     */
    public function withSortFields(array $sortFields): self
    {
        $paginator = clone $this;
        $paginator->sortFields = $sortFields;
        return $paginator;
    }

    #[\Override]
    public function withValue(mixed $value): ?SpecificationInterface
    {
        $paginator = clone $this;
        if (!\is_array($value)) {
            return $paginator;
        }

        $paginator->validateValue($value);

        $paginator->direction = $paginator->determineDirection($value);
        $paginator->limit = $paginator->determineLimit($value);

        $cursorData = $value['after'] ?? $value['before'] ?? null;
        if ($cursorData !== null) {
            $paginator->cursorData = $paginator->cursorCoder->decodeCursor($cursorData);
        }

        return $paginator;
    }

    #[\Override]
    public function getValue(): mixed
    {
        $value = [];

        if ($this->direction->isForward()) {
            $value['first'] = $this->limit;
            if ($this->cursorData !== null) {
                $value['after'] = $this->cursorCoder->encodeCursor($this->cursorData);
            }
        } else {
            $value['last'] = $this->limit;
            if ($this->cursorData !== null) {
                $value['before'] = $this->cursorCoder->encodeCursor($this->cursorData);
            }
        }

        return $value;
    }

    #[\Override]
    public function getSpecifications(): array
    {
        $specifications = [
            new CursorLimit($this->limit),
        ];

        // Only add sort direction spec for backward pagination (needs reversal)
        if ($this->sortFields !== [] && $this->direction->isBackward()) {
            $specifications[] = new SortDirection($this->sortFields, $this->direction);
        }

        if ($this->cursorData !== null && $this->sortFields !== []) {
            $specifications[] = new KeysetFilter(
                $this->cursorData,
                $this->direction,
                array_keys($this->sortFields),
            );
        }

        return $specifications;
    }

    /**
     * Get the current pagination direction.
     */
    public function getDirection(): CursorDirection
    {
        return $this->direction;
    }

    /**
     * Get the current limit.
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Get the current cursor data.
     */
    public function getCursorData(): ?CursorDataInterface
    {
        return $this->cursorData;
    }

    /**
     * Get configured sort fields.
     *
     * @return array<string, string>
     */
    public function getSortFields(): array
    {
        return $this->sortFields;
    }

    private function validateValue(array $value): void
    {
        if (
            (isset($value['size']) && isset($value['first'])) ||
            (isset($value['size']) && isset($value['last'])) ||
            (isset($value['first']) && isset($value['last']))
        ) {
            throw new InvalidArgumentException('Cannot specify both size and first/last parameters');
        }

        if (
            (isset($value['after']) && isset($value['before'])) ||
            (isset($value['after']) && isset($value['last'])) ||
            (isset($value['before']) && isset($value['first']))
        ) {
            throw new InvalidArgumentException('Invalid combination of cursor parameters');
        }
    }

    private function determineDirection(array $value): CursorDirection
    {
        if (isset($value['first']) || isset($value['after'])) {
            return CursorDirection::FORWARD;
        }

        if (isset($value['last']) || isset($value['before'])) {
            return CursorDirection::BACKWARD;
        }

        return CursorDirection::FORWARD;
    }

    private function determineLimit(array $value): int
    {
        $limit = $value['size'] ?? $value['first'] ?? $value['last'] ?? $this->defaultLimit;

        if (!$this->limitValue->accepts($limit)) {
            throw new InvalidArgumentException('Invalid size value');
        }

        return $this->limitValue->convert($limit);
    }
}
