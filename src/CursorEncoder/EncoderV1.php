<?php

namespace Cardyo\SpiralCursorPagination\CursorEncoder;

use InvalidArgumentException;

final class EncoderV1 implements CursorDecoderInterface, CursorEncoderInterface
{
    public const PREFIX = 'cursor:v1:';

    public function encodeCursor(mixed $cursor): string
    {
        if (!is_string($cursor)) {
            throw new InvalidArgumentException('Invalid cursor');
        }

        return rtrim(strtr(base64_encode(sprintf('%s%s', self::PREFIX, $cursor)), '+/', '-_'), '=');
    }

    public function decodeCursor(string $cursor): mixed
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || !str_starts_with($decoded, self::PREFIX)) {
            return null; // todo: throw exception?
        }

        // Using explode to get the part after the prefix.
        // ```
        // explode(self::PREFIX, 'cursor:v1:actual_cursor_value', 2) => [self::PREFIX, 'actual_cursor_value']
        // array_reverse(...) => ['actual_cursor_value', self::PREFIX]
        // [0] => 'actual_cursor_value'
        // ```
        return array_reverse(explode(self::PREFIX, $decoded, 2))[0];
    }
}