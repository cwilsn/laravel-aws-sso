<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector;
use RectorLaravel\Rector\Class_\DescriptionPropertyToDescriptionAttributeRector;
use RectorLaravel\Rector\Class_\SignaturePropertyToSignatureAttributeRector;
use RectorLaravel\Rector\Expr\AppEnvironmentComparisonToParameterRector;
use RectorLaravel\Rector\FuncCall\AppToResolveRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withCache(__DIR__.'/.rector.cache')
    // The package supports PHP 8.3 and up, so rules must not emit newer syntax
    // even though the machine running Rector may be on a later version.
    ->withPhpSets(php83: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::INSTANCEOF,
        SetList::TYPE_DECLARATION,
        LaravelSetList::LARAVEL_130,
        LaravelSetList::LARAVEL_CODE_QUALITY,
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withSkip([
        // Rewrites `unset($_ENV[$name])` to `unset(Env::get($name))`, which is
        // a fatal error. The test helper writes to putenv/$_ENV/$_SERVER on
        // purpose, because that is everywhere Env reads from.
        EnvVariableToEnvHelperRector::class,

        // `in_array($app->environment(), $list, true)` becomes
        // `$app->environment($list)`, which matches with Str::is() wildcards.
        // This gate decides when AWS auth is enforced, so it stays exact.
        AppEnvironmentComparisonToParameterRector::class,

        // Turns `$x === null` into `! $x instanceof Foo`. Equivalent for a
        // nullable type, but it reads as a type check rather than a null check.
        FlipTypeControlToUseExclusiveTypeRector::class,

        // `app()` and `resolve()` are the same call; churn without a gain.
        AppToResolveRector::class,

        // The property form keeps a multi-line command signature readable, and
        // Laravel still supports it. Attributes would only move the string.
        DescriptionPropertyToDescriptionAttributeRector::class,
        SignaturePropertyToSignatureAttributeRector::class,

        // Closures passed to `toThrow()` never return, so annotating them with
        // the happy-path return type is actively misleading.
        AddArrowFunctionReturnTypeRector::class => [
            __DIR__.'/tests',
        ],
    ]);
