<?php

namespace PhpCsFixer;

return Config::create()
    ->setUsingCache(true)
    ->setCacheFile(__DIR__.'/.php_cs.cache')
    ->setRules([
        '@PSR2' => true,
        '@Symfony' => true,
        'no_multiline_whitespace_before_semicolons' => true,
        'not_operator_with_space' => true,
        'ordered_imports' => true,
        'phpdoc_order' => true,
        'psr0' => false,
        'array_syntax' => [
            'syntax' => 'short',
        ],
        'binary_operator_spaces' => [
            'align_double_arrow' => true,
        ],
    ])
    ->setFinder(
        Finder::create()
            ->in(__DIR__.'/src')
            ->in(__DIR__.'/tests')
    );
