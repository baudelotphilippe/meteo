<?php

if (!file_exists(__DIR__ . '/src')) {
    exit(0);
}

return (new PhpCsFixer\Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        '@Symfony' => true,
        'yoda_style' => false,
        'declare_strict_types' => true,
        'strict_comparison' => false,
        '@PHP80Migration' => true,
        '@PHPUnit84Migration:risky' => true,
        'increment_style' => false,
        'trailing_comma_in_multiline' => [
            'elements' => [
                'arrays',
                'parameters'
            ],
        ]
    ])
    ->setRiskyAllowed(true)
    ->setCacheFile('.php-cs-fixer.cache')
    ;
