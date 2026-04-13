<?php

declare(strict_types=1);

use Doctum\Doctum;
use Doctum\Parser\Filter\TrueFilter;
use Symfony\Component\Finder\Finder;

$iterator = Finder::create()
    ->files()
    ->name('*.php')
    ->in([
        __DIR__ . '/app',
        __DIR__ . '/config',
    ]);

return new Doctum($iterator, [
    'title' => 'WorkerHub API Docs',
    'build_dir' => __DIR__ . '/docs/api/build',
    'cache_dir' => __DIR__ . '/docs/api/cache',
    'default_opened_level' => 2,
    'filter' => new TrueFilter(),
]);
