<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Specification\Pagination;

use Cardyo\SpiralCursorPagination\CursorEncoder\CursorDecoderInterface;
use Cardyo\SpiralCursorPagination\CursorEncoder\CursorEncoderInterface;
use InvalidArgumentException;
use Spiral\DataGrid\Specification\FilterInterface;
use Spiral\DataGrid\Specification\Pagination\Limit;
use Spiral\DataGrid\Specification\SequenceInterface;
use Spiral\DataGrid\Specification\ValueInterface;
use Spiral\DataGrid\SpecificationInterface;
use function is_array;

/**
 * @see https://jsonapi.org/profiles/ethanresnick/cursor-pagination/
 * @see https://relay.dev/graphql/connections.htm
 */
class CursorPaginator implements FilterInterface, SequenceInterface
{
    private int $limit;

    public function __construct(
        private readonly int $defaultLimit,
        private readonly ValueInterface $limitValue,
        CursorDecoderInterface&CursorEncoderInterface $cursorCoder,
    ) {
        if ($defaultLimit < 1) {
            throw new InvalidArgumentException('Default limit must be a positive integer');
        }

        if (!$this->limitValue->accepts($defaultLimit)) {
            throw new InvalidArgumentException('Default limit must be one of the allowed limits');
        }

        $this->limit = $defaultLimit;
    }

    #[\Override]
    public function withValue(mixed $value): ?SpecificationInterface
    {
        $paginator = clone $this;
        if (!is_array($value)) {
            return $paginator;
        }

        $this->limit = $this->determineLimit($value);

        return $paginator;
    }

    #[\Override]
    public function getValue(): mixed
    {
        return [
            'size' => $this->limit,
        ];
    }

    #[\Override]
    public function getSpecifications(): array
    {
        return [
            new Limit($this->limit),
        ];
    }

    private function determineLimit(array $value): int
    {
        if (
            (isset($value['size']) && isset($value['first'])) ||
            (isset($value['size']) && isset($value['last'])) ||
            (isset($value['first']) && isset($value['last']))
        ) {
            throw new InvalidArgumentException('Cannot specify both size and first/last parameters'); // todo: custom exception
        }

        if (isset($value['size'])) {
            if (!$this->limitValue->accepts($value['size'])) {
                throw new InvalidArgumentException('Invalid size value'); // todo: custom exception
            }

            return $this->limitValue->convert($value['size']);
        }

        if (isset($value['first'])) {
            if (!$this->limitValue->accepts($value['first'])) {
                throw new InvalidArgumentException('Invalid first value'); // todo: custom exception
            }

            return $this->limitValue->convert($value['first']);
        }

        if (isset($value['last'])) {
            if (!$this->limitValue->accepts($value['last'])) {
                throw new InvalidArgumentException('Invalid last value'); // todo: custom exception
            }

            return $this->limitValue->convert($value['last']);
        }

        return $this->defaultLimit;
    }
}
