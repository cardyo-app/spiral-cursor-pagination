<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Unit\Cursor;

use Cardyo\SpiralCursorPagination\Cursor\CursorData;
use Cardyo\SpiralCursorPagination\Cursor\JsonCursorSerializer;
use PHPUnit\Framework\TestCase;

final class JsonCursorSerializerTest extends TestCase
{
    private JsonCursorSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new JsonCursorSerializer();
    }

    public function testSerializeSimpleData(): void
    {
        $data = new CursorData(['id' => 123]);
        $serialized = $this->serializer->serialize($data);

        $this->assertSame('{"id":123}', $serialized);
    }

    public function testSerializeCompositeData(): void
    {
        $data = new CursorData(['created_at' => '2024-01-01 12:00:00', 'id' => 42]);
        $serialized = $this->serializer->serialize($data);

        $this->assertSame('{"created_at":"2024-01-01 12:00:00","id":42}', $serialized);
    }

    public function testSerializeWithNullValue(): void
    {
        $data = new CursorData(['id' => 1, 'deleted_at' => null]);
        $serialized = $this->serializer->serialize($data);

        $this->assertSame('{"id":1,"deleted_at":null}', $serialized);
    }

    public function testDeserializeSimpleData(): void
    {
        $result = $this->serializer->deserialize('{"id":123}');

        $this->assertInstanceOf(CursorData::class, $result);
        $this->assertSame(123, $result->get('id'));
    }

    public function testDeserializeCompositeData(): void
    {
        $result = $this->serializer->deserialize('{"created_at":"2024-01-01 12:00:00","id":42}');

        $this->assertInstanceOf(CursorData::class, $result);
        $this->assertSame('2024-01-01 12:00:00', $result->get('created_at'));
        $this->assertSame(42, $result->get('id'));
    }

    public function testDeserializeReturnsNullForInvalidJson(): void
    {
        $result = $this->serializer->deserialize('invalid json');

        $this->assertNotInstanceOf(CursorData::class, $result);
    }

    public function testDeserializeReturnsNullForEmptyArray(): void
    {
        $result = $this->serializer->deserialize('[]');

        $this->assertNotInstanceOf(CursorData::class, $result);
    }

    public function testDeserializeReturnsNullForNonArray(): void
    {
        $result = $this->serializer->deserialize('"just a string"');

        $this->assertNotInstanceOf(CursorData::class, $result);
    }

    public function testRoundTripPreservesData(): void
    {
        $original = new CursorData([
            'id' => 999,
            'created_at' => '2024-12-25 00:00:00',
            'score' => 95.5,
            'deleted_at' => null,
        ]);

        $serialized = $this->serializer->serialize($original);
        $deserialized = $this->serializer->deserialize($serialized);
        $this->assertInstanceOf(CursorData::class, $deserialized);

        $this->assertEquals($original->toArray(), $deserialized->toArray());
    }
}
