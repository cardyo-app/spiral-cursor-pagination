<?php

namespace Cardyo\SpiralCursorPagination\CursorEncoder;

use Cardyo\SpiralCursorPagination\Cursor\CursorData;
use Cardyo\SpiralCursorPagination\Cursor\CursorDataInterface;
use Cardyo\SpiralCursorPagination\Exception\MalformedCursorException;
use JsonException;

final class CursorCoder implements CursorEncoderInterface, CursorDecoderInterface
{
    /** @var string */
    public const PREFIX = 'cursor:v2:';

    #[\Override]
    public function encodeCursor(CursorDataInterface $cursor): string
    {
        $json = json_encode($cursor->toArray(), JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode(self::PREFIX . $json), '+/', '-_'), '=');
    }

    /**
     * @throws MalformedCursorException
     */
    #[\Override]
    public function decodeCursor(string $cursor): CursorDataInterface
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || !str_starts_with($decoded, self::PREFIX)) {
            throw new MalformedCursorException();
        }

        try {
            $json = substr($decoded, strlen(self::PREFIX));
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return CursorData::fromArray($data);
        } catch (JsonException $e) {
            throw new MalformedCursorException(previous: $e);
        }
    }
}
