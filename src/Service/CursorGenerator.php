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
     * Generate a cursor for an entity using field names extracted from a query.
     *
     * @param mixed $entity Entity to generate cursor for
     * @param \Cycle\ORM\Select $query Query to extract sort fields from
     * @param CursorEncoderInterface $encoder Encoder to use
     * @return string Encoded cursor
     *
     * @psalm-suppress UndefinedClass
     */
    public function generateFromQuery(
        mixed $entity,
        mixed $query,
        CursorEncoderInterface $encoder,
    ): string {
        $fieldNames = \Cardyo\SpiralCursorPagination\Util\QuerySortFieldsExtractor::extractFieldNames($query);

        return $this->generateFromFields($entity, $fieldNames, $encoder);
    }

    /**
     * Generate a cursor for an entity from explicit field names.
     *
     * @param mixed $entity Entity to generate cursor for
     * @param array<string> $fieldNames Fields to include in cursor
     * @param CursorEncoderInterface $encoder Encoder to use
     * @return string Encoded cursor
     */
    public function generateFromFields(mixed $entity, array $fieldNames, CursorEncoderInterface $encoder): string
    {
        $cursorData = [];

        foreach ($fieldNames as $field) {
            $cursorData[$field] = $this->extractValue($entity, $field);
        }

        return $encoder->encodeCursor(new CursorData($cursorData));
    }

    /**
     * Extract a field value from an entity.
     *
     * Supports both object properties and array access.
     * Tries both exact field name and camelCase variant (for snake_case DB columns).
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
            // Try exact field name first
            if (property_exists($entity, $field)) {
                return $this->getPropertyValue($entity, $field);
            }

            // Try camelCase variant (for snake_case DB columns)
            $camelField = $this->snakeToCamel($field);
            if ($camelField !== $field && property_exists($entity, $camelField)) {
                return $this->getPropertyValue($entity, $camelField);
            }

            if (method_exists($entity, '__get')) {
                return $entity->$field;
            }
        }

        return null;
    }

    /**
     * Get property value from an object, handling visibility.
     */
    private function getPropertyValue(object $entity, string $property): mixed
    {
        $reflection = new ReflectionClass($entity);
        $prop = $reflection->getProperty($property);

        if (!$prop->isPublic()) {
            $prop->setAccessible(true);
        }

        return $prop->getValue($entity);
    }

    /**
     * Convert snake_case to camelCase.
     */
    private function snakeToCamel(string $string): string
    {
        return lcfirst(str_replace('_', '', ucwords($string, '_')));
    }
}
