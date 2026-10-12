<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\Console;
use Phattarachai\GitPrompt\Git;

abstract class Action
{
    public function __construct(protected Git $git, protected Console $console) {}

    /**
     * @template T of Action
     *
     * @param  class-string<T>  $action
     * @return T
     */
    protected function make(string $action): Action
    {
        return new $action($this->git, $this->console);
    }
}
