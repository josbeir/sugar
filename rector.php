<?php
declare(strict_types=1);

use Rector\CodeQuality\Rector\If_\SimplifyIfElseToTernaryRector;
use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
	->withSkipPath(
		__DIR__ . '/tests/fixtures',
	)
    ->withSkip([
        SimplifyIfElseToTernaryRector::class,
    ])
    ->withImportNames(
        importDocBlockNames: false,
        importShortClasses: false,
        removeUnusedImports: true,
    )
    ->withCache(__DIR__ . '/tmp/rector')
    ->withParallel()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
		earlyReturn: true,
		instanceOf: true,
		phpunitCodeQuality: true,
    );
