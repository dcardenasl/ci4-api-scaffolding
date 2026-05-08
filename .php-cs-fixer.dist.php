<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                  => true,
        'declare_strict_types'    => true,
        'ordered_imports'         => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'       => true,
        'array_syntax'            => ['syntax' => 'short'],
        'strict_comparison'       => true,
        'void_return'             => true,
    ])
    ->setFinder(
        (new Finder())
            ->in(__DIR__)
            ->exclude(['vendor', 'build', '.github'])
    );
