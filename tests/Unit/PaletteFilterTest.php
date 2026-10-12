<?php

use Phattarachai\GitPrompt\Prompts\Fuzzy;

$entries = fn (array $labels) => array_combine($labels, array_map(fn ($label) => ['label' => $label, 'search' => $label], $labels));

it('keeps everything in order for an empty query', function () use ($entries) {
    $list = $entries(['Checkout', 'Delete…']);

    expect(array_keys(Fuzzy::filter($list, '')))->toBe(['Checkout', 'Delete…']);
});

it('ranks prefix, then word start, then substring, then fuzzy', function () use ($entries) {
    $list = $entries(['Checkout and Update', 'hotfix/cart-api-log-tab', 'Rename…', 'Delete…', 'origin/chore/rotate']);

    expect(array_keys(Fuzzy::filter($list, 'd')))->toBe(['Delete…', 'Checkout and Update'])
        ->and(array_keys(Fuzzy::filter($list, 'cal')))->toBe(['hotfix/cart-api-log-tab'])
        ->and(array_keys(Fuzzy::filter($list, 'or')))->toBe(['origin/chore/rotate', 'hotfix/cart-api-log-tab']);
});

it('requires every word to match', function () use ($entries) {
    $list = $entries(['hotfix/cart-api-log-tab', 'develop']);

    expect(array_keys(Fuzzy::filter($list, 'hot tab')))->toBe(['hotfix/cart-api-log-tab'])
        ->and(Fuzzy::filter($list, 'hot develop'))->toBe([]);
});
