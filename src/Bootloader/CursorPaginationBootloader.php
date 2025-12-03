<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Bootloader;

use Cardyo\SpiralCursorPagination\Interceptor\CursorPaginationInterceptor;
use Cardyo\SpiralCursorPagination\Service\CursorPaginationHelper;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Bootloader\DomainBootloader;
use Spiral\Core\Container;

/**
 * Bootloader for cursor pagination integration.
 *
 * Registers the CursorPaginationInterceptor and helper services.
 *
 * Add to your application bootloaders:
 * ```php
 * protected const LOAD = [
 *     // ...
 *     \Cardyo\SpiralCursorPagination\Bootloader\CursorPaginationBootloader::class,
 * ];
 * ```
 */
final class CursorPaginationBootloader extends Bootloader
{
    public function defineSingletons(): array
    {
        return [
            CursorPaginationHelper::class => CursorPaginationHelper::class,
        ];
    }

    public function init(Container $container, DomainBootloader $domain): void
    {
        // Register the interceptor for all HTTP requests
        $domain->addInterceptor(CursorPaginationInterceptor::class);
    }
}
