<?php

declare(strict_types=1);

use Josecl\PhpCsFixerCustomFixers\CustomFixers;

$finder = Symfony\Component\Finder\Finder::create()
    ->in([
        __DIR__ . '/src',
    ])
    ->name('*.php')
    ->notName('*.blade.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return CustomFixers::config($finder);
