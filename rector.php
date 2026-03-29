<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPromotedPropertyRector;
use Rector\DeadCode\Rector\Cast\RecastingRemovalRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
    ])
    ->withSets([
        SetList::DEAD_CODE,
        SetList::CODE_QUALITY,
        SetList::TYPE_DECLARATION,
        LevelSetList::UP_TO_PHP_82,
    ])
    ->withSkip([
        RemoveUnusedPromotedPropertyRector::class,
        RecastingRemovalRector::class,
        // StrictArrayParamDimFetchRector adds `array` type to $app params in ServiceProvider
        // but $app is Application (ArrayAccess), not array — breaks PHPStan
        \Rector\Strict\Rector\Stmt\StrictArrayParamDimFetchRector::class => [
            __DIR__.'/src/RagServiceProvider.php',
        ],
    ]);
