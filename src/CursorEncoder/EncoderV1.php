<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\CursorEncoder;

/**
 * Version 1 cursor encoder/decoder implementation.
 *
 * Encodes cursor data (typically arrays) into URL-safe base64 strings with a version prefix.
 * The encoding process:
 * 1. JSON encodes the data
 * 2. Prepends version prefix ("cursor:v1:") to identify encoding version
 * 3. Base64 encodes the result
 * 4. Makes it URL-safe by replacing +/ with -_ and removing padding
 *
 * Example encoded cursor:
 * eyJzb3J0X3ZhbHVlcyI6eyJpZCI6MTIzfSwiZGlyZWN0aW9uIjoiZm9yd2FyZCIsInZlcnNpb24iOiJ2MSJ9
 */
final class EncoderV1 implements CursorDecoderInterface, CursorEncoderInterface
{
    public const PREFIX = 'cursor:v1:';

    /**
     * Encode cursor data into a URL-safe base64 string.
     *
     * @param mixed $cursor The cursor data to encode (typically an array)
     * @return string The encoded cursor string
     * @throws \JsonException If the cursor cannot be JSON encoded
     */
    public function encode(mixed $cursor): string
    {
        $json = \json_encode($cursor, \JSON_THROW_ON_ERROR);

        return \rtrim(\strtr(\base64_encode(self::PREFIX . $json), '+/', '-_'), '=');
    }

    /**
     * Decode a cursor string back into cursor data.
     *
     * @param string $cursor The encoded cursor string
     * @return mixed The decoded cursor data (typically an array), or null if invalid
     */
    public function decode(string $cursor): mixed
    {
        // Convert URL-safe base64 back to standard base64
        $base64 = \strtr($cursor, '-_', '+/');

        // Add back padding if needed
        $padLength = 4 - (\strlen($base64) % 4);
        if ($padLength < 4) {
            $base64 .= \str_repeat('=', $padLength);
        }

        $decoded = \base64_decode($base64, true);

        if ($decoded === false || !\str_starts_with($decoded, self::PREFIX)) {
            return null;
        }

        // Remove prefix
        $json = \substr($decoded, \strlen(self::PREFIX));

        try {
            return \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
