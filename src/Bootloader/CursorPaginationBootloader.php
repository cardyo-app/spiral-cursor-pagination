<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Bootloader;

use Cardyo\SpiralCursorPagination\Interceptor\CursorPaginationInterceptor;
use Cardyo\SpiralCursorPagination\Response\ConnectionResponse;
use Cardyo\SpiralCursorPagination\Response\ConnectionResponseInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Core\CoreInterface;

/**
 * Bootloader for cursor pagination functionality.
 *
 * Registers:
 * - ConnectionResponseInterface binding
 * - CursorPaginationInterceptor
 *
 * Usage in app/src/Application/Kernel.php:
 * ```php
 * protected const LOAD = [
 *     // ... other bootloaders
 *     \Cardyo\SpiralCursorPagination\Bootloader\CursorPaginationBootloader::class,
 * ];
 * ```
 */
final class CursorPaginationBootloader extends Bootloader
{
    protected const SINGLETONS = [
        ConnectionResponseInterface::class => ConnectionResponse::class,
    ];

    protected const INTERCEPTORS = [
        CursorPaginationInterceptor::class,
    ];

    public function init(CoreInterface $core): void
    {
        // Register interceptor with the core
        foreach (static::INTERCEPTORS as $interceptor) {
            $core->addInterceptor($interceptor);
        }
    }
}
