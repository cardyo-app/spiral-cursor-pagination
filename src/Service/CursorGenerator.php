<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Service;

use ArrayAccess;
use Cardyo\SpiralCursorPagination\Cursor\CursorData;
use Cardyo\SpiralCursorPagination\CursorEncoder\CursorEncoderInterface;

/**
 * Service for generating cursor strings from entities.
 *
 * Extracts field values from entities (objects, arrays, etc.) and encodes
 * them into cursor strings using the configured encoder.
 */
final class CursorGenerator
{
    /**
     * Generate a cursor string from an entity.
     *
     * @param mixed $entity The entity to extract values from
     * @param array<string> $fields Field names to extract
     * @param CursorEncoderInterface $encoder Encoder to use
     * @return string Encoded cursor string
     */
    public function generate(mixed $entity, array $fields, CursorEncoderInterface $encoder): string
    {
        $values = [];

        foreach ($fields as $field) {
            $values[$field] = $this->extractValue($entity, $field);
        }

        $cursorData = new CursorData($values);
        return $encoder->encodeCursor($cursorData);
    }

    /**
     * Extract a field value from an entity.
     *
     * Supports multiple entity types:
     * - Arrays: $entity['field']
     * - Objects with properties: $entity->field
     * - Objects with getters: $entity->getField() or $entity->field()
     * - ArrayAccess: $entity['field']
     *
     * @param mixed $entity Entity to extract from
     * @param string $field Field name
     * @return mixed Field value
     */
    private function extractValue(mixed $entity, string $field): mixed
    {
        // Array access
        if (is_array($entity)) {
            return $entity[$field] ?? null;
        }

        // ArrayAccess interface
        if ($entity instanceof ArrayAccess) {
            return $entity[$field] ?? null;
        }

        // Object property
        if (is_object($entity)) {
            // Getter method: getField() or get_field()
            $getterCamelCase = 'get' . str_replace('_', '', ucwords($field, '_'));
            if (method_exists($entity, $getterCamelCase)) {
                return $entity->$getterCamelCase();
            }

            // Direct method: field()
            if (method_exists($entity, $field)) {
                return $entity->$field();
            }

            // Direct property access (check accessibility)
            if (property_exists($entity, $field)) {
                try {
                    return $entity->$field;
                } catch (\Error) {
                    // Property exists but is not accessible
                    return null;
                }
            }
        }

        return null;
    }
}
