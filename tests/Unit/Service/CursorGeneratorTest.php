<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Unit\Service;

use Cardyo\SpiralCursorPagination\Cursor\CursorData;
use Cardyo\SpiralCursorPagination\CursorEncoder\EncoderV2;
use Cardyo\SpiralCursorPagination\Service\CursorGenerator;
use PHPUnit\Framework\TestCase;

final class CursorGeneratorTest extends TestCase
{
    private CursorGenerator $generator;

    private EncoderV2 $encoder;

    protected function setUp(): void
    {
        $this->generator = new CursorGenerator();
        $this->encoder = new EncoderV2();
    }

    public function testGenerateFromArray(): void
    {
        $entity = ['id' => 123, 'name' => 'John', 'created_at' => '2024-01-01'];
        $fields = ['id', 'created_at'];

        $cursor = $this->generator->generate($entity, $fields, $this->encoder);

        $this->assertIsString($cursor);

        $decoded = $this->encoder->decodeCursor($cursor);
        $this->assertInstanceOf(CursorData::class, $decoded);
        $this->assertSame(123, $decoded->get('id'));
        $this->assertSame('2024-01-01', $decoded->get('created_at'));
    }

    public function testGenerateFromObjectWithProperties(): void
    {
        $entity = new class {
            public int $id = 456;

            public string $created_at = '2024-12-25';
        };
        $fields = ['id', 'created_at'];

        $cursor = $this->generator->generate($entity, $fields, $this->encoder);

        $decoded = $this->encoder->decodeCursor($cursor);
        $this->assertInstanceOf(CursorData::class, $decoded);
        $this->assertSame(456, $decoded->get('id'));
        $this->assertSame('2024-12-25', $decoded->get('created_at'));
    }

    public function testGenerateFromObjectWithGetter(): void
    {
        $entity = new class {
            private int $id = 789;

            public function getId(): int
            {
                return $this->id;
            }
        };
        $fields = ['id'];

        $cursor = $this->generator->generate($entity, $fields, $this->encoder);

        $decoded = $this->encoder->decodeCursor($cursor);
        $this->assertInstanceOf(CursorData::class, $decoded);
        $this->assertSame(789, $decoded->get('id'));
    }

    public function testGenerateHandlesMissingFields(): void
    {
        $entity = ['id' => 1];
        $fields = ['id', 'non_existent'];

        $cursor = $this->generator->generate($entity, $fields, $this->encoder);

        $decoded = $this->encoder->decodeCursor($cursor);
        $this->assertInstanceOf(CursorData::class, $decoded);
        $this->assertSame(1, $decoded->get('id'));
        $this->assertNull($decoded->get('non_existent'));
    }

    public function testGenerateFromSingleField(): void
    {
        $entity = ['id' => 999];
        $fields = ['id'];

        $cursor = $this->generator->generate($entity, $fields, $this->encoder);

        $decoded = $this->encoder->decodeCursor($cursor);
        $this->assertInstanceOf(CursorData::class, $decoded);
        $this->assertSame(999, $decoded->get('id'));
    }
}
