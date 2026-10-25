<?php

declare(strict_types=1);

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3x0' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in(__DIR__ . '/src')
            ->in(__DIR__ . '/tests')
            ->in(__DIR__ . '/examples'),
    );
