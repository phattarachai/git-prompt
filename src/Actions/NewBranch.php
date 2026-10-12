<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\Branch;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;

class NewBranch extends Action
{
    public function __invoke(?Branch $from = null): bool
    {
        $start = $from?->ref() ?? $this->git->currentBranch() ?? $this->git->headShortSha();

        $name = text(
            label: "New branch from '{$start}'",
            placeholder: 'feat/short-description',
            required: true,
            validate: fn (string $value) => match (true) {
                ! $this->git->isValidBranchName($value) => 'Not a valid branch name.',
                $this->git->findBranch($value) !== null => "'{$value}' already exists.",
                default => null,
            },
        );

        $arguments = ['switch', '--no-track', '-c', $name];

        if ($from !== null) {
            $arguments[] = $from->ref();
        }

        if ($this->console->run($arguments)->failed()) {
            error("Could not create '{$name}'.");

            return false;
        }

        info("Created and switched to '{$name}'.");

        return true;
    }
}
