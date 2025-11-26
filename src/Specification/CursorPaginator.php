<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Specification;

use Cardyo\SpiralCursorPagination\CursorEncoder\CursorDecoderInterface;
use Cardyo\SpiralCursorPagination\CursorEncoder\CursorEncoderInterface;
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

    private ?string $after = null;

    private ?int $last = null;

    private ?string $before = null;

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
            } else {
                // Otherwise, use 'first' with default limit
                $paginator->first = $this->defaultLimit;
                if (isset($value['after'])) {
                    $paginator->after = $paginator->cursorCoder->decodeCursor($value['after']);
                }
            }
        } elseif ($hasValidFirst) {
            $paginator->first = $paginator->limitValue->convert($value['first']);

            if (isset($value['after'])) {
                $paginator->after = $paginator->cursorCoder->decodeCursor($value['after']);
            }
        } elseif ($hasValidLast) {
            $paginator->last = $paginator->limitValue->convert($value['last']);

            if (isset($value['before'])) {
                $paginator->before = $paginator->cursorCoder->decodeCursor($value['before']);
            }
        }

        return $paginator;
    }

    #[\Override]
    public function getSpecifications(): array
    {
        return [];
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

        if ($this->after !== null) {
            $value['after'] = $this->cursorCoder->encodeCursor($this->after);
        } elseif ($this->before !== null) {
            $value['before'] = $this->cursorCoder->encodeCursor($this->before);
        }

        return $value;
    }
}
