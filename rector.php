<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;

return RectorConfig::configure()
    ->withCache('./runtime/rector.cache')
    ->withParallel()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
    )
    ->withComposerBased(
        phpunit: true,
    )
    ->withPhpSets()
    ->withImportNames(
        importShortClasses: false,
        removeUnusedImports: true,
    )
    ->withSkip([
        ReadOnlyPropertyRector::class => [
            '*/Entity/*',
        ],
    ]);
