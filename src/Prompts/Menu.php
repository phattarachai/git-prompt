<?php

namespace Phattarachai\GitPrompt\Prompts;

/**
 * Every list in the app is a type-to-filter list, like an IDE popup.
 */
final class Menu
{
    /**
     * @param  array<string, string>  $options  key => label
     */
    public static function choose(string $label, array $options, string $hint = 'Type to filter · Enter pick · Esc back'): string
    {
        $entries = self::entries($options);

        return (string) (new PaletteSearch(
            label: $label,
            options: fn (string $query) => Fuzzy::filter($entries, $query),
            scroll: min(12, max(1, count($options))),
            hint: $hint,
        ))->prompt();
    }

    /**
     * @param  array<string, array{label: string, search: string}>  $entries
     * @return list<string>
     */
    public static function chooseMany(string $label, array $entries, string $hint): array
    {
        return array_map('strval', (new PaletteMultiSearch(
            label: $label,
            options: fn (string $query) => Fuzzy::filter($entries, $query),
            hint: $hint,
        ))->prompt());
    }

    /**
     * @param  array<string, string>  $options
     * @return array<string, array{label: string, search: string}>
     */
    private static function entries(array $options): array
    {
        return array_map(fn (string $label) => ['label' => $label, 'search' => $label], $options);
    }
}
