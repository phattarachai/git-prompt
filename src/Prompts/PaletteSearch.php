<?php

namespace Phattarachai\GitPrompt\Prompts;

use Closure;
use Laravel\Prompts\SearchPrompt;
use Laravel\Prompts\Themes\Default\SearchPromptRenderer;

/**
 * A search prompt that keeps the first match highlighted, like an IDE popup:
 * type a few letters, press Enter.
 */
class PaletteSearch extends SearchPrompt
{
    public function __construct(string $label, Closure $options, string $placeholder = 'Type to filter…', int $scroll = 12, string $hint = '')
    {
        parent::__construct($label, $options, $placeholder, $scroll, hint: $hint);

        $this->initializeScrolling(0);
    }

    protected function search(): void
    {
        parent::search();

        if ($this->matches() !== []) {
            $this->highlight(0);
        }
    }

    protected function getRenderer(): callable
    {
        return new SearchPromptRenderer($this);
    }
}
