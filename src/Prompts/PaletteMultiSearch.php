<?php

namespace Phattarachai\GitPrompt\Prompts;

use Closure;
use Laravel\Prompts\MultiSearchPrompt;
use Laravel\Prompts\Themes\Default\MultiSearchPromptRenderer;

/**
 * Multi-select with filtering; the first match stays highlighted so Space
 * ticks it right after typing, and Ctrl+A ticks every match.
 */
class PaletteMultiSearch extends MultiSearchPrompt
{
    public function __construct(string $label, Closure $options, string $hint = '', int $scroll = 15)
    {
        parent::__construct($label, $options, scroll: $scroll, hint: $hint);

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
        return new MultiSearchPromptRenderer($this);
    }
}
