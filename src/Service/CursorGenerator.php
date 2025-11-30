<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Service;

use Cardyo\SpiralCursorPagination\Cursor\CursorData;
use Cardyo\SpiralCursorPagination\CursorEncoder\CursorEncoderInterface;
use ReflectionClass;
use ReflectionProperty;

/**
 * Service for generating cursors from entities.
 *
 * Extracts field values from entities and encodes them into opaque cursor strings.
 * Supports both object properties and array access for flexibility.
 */
final class CursorGenerator
{
    /**
     * Generate a cursor for an entity.
     *
     * @param mixed $entity Entity to generate cursor for
     * @param array<string> $sortFields Fields to include in cursor
     * @param CursorEncoderInterface $encoder Encoder to use
     * @return string Encoded cursor
     */
    public function generate(mixed $entity, array $sortFields, CursorEncoderInterface $encoder): string
    {
        $cursorData = [];

        foreach ($sortFields as $field) {
            $cursorData[$field] = $this->extractValue($entity, $field);
        }

        return $encoder->encodeCursor(new CursorData($cursorData));
    }

    /**
     * Extract a field value from an entity.
     *
     * Supports both object properties and array access.
     *
     * @param mixed $entity Entity to extract from
     * @param string $field Field name
     * @return mixed Field value
     */
    private function extractValue(mixed $entity, string $field): mixed
    {
        if (is_array($entity)) {
            return $entity[$field] ?? null;
        }

        if (is_object($entity)) {
            if (property_exists($entity, $field)) {
                $reflection = new ReflectionClass($entity);
                $property = $reflection->getProperty($field);

                if (!$property->isPublic()) {
                    $property->setAccessible(true);
                }

                return $property->getValue($entity);
            }

            if (method_exists($entity, '__get')) {
                return $entity->$field;
            }
        }

        return null;
    }
}
