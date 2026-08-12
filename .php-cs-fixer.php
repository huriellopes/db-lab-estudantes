<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/app', __DIR__ . '/public', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PSR12:risky' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'single_quote' => true,
        'no_empty_statement' => true,
        'no_extra_blank_lines' => true,
        'blank_line_after_opening_tag' => true,
        'blank_line_after_namespace' => true,
        'single_blank_line_at_eof' => true,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'concat_space' => ['spacing' => 'one'],
        'not_operator_with_successor_space' => false,
        'yoda_style' => false,
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_scalar' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
    ])
    ->setFinder($finder);
