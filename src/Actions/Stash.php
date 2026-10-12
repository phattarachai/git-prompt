<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\Prompts\Menu;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class Stash extends Action
{
    public function __invoke(): bool
    {
        $options = [];

        if ($this->git->status() !== []) {
            $options['push'] = '+ Stash my changes';
        }

        foreach ($this->git->stashes() as $stash) {
            $options[$stash['ref']] = "{$stash['ref']}  {$stash['subject']} · {$stash['date']}";
        }

        if ($options === []) {
            info('No changes to stash, and no stashes saved.');

            return true;
        }

        $choice = Menu::choose('Stash', $options);

        return $choice === 'push' ? $this->push() : $this->manage($choice);
    }

    private function push(): bool
    {
        $message = text(label: 'Stash message', placeholder: 'optional');

        $arguments = ['stash', 'push', '--include-untracked'];

        if ($message !== '') {
            array_push($arguments, '-m', $message);
        }

        if ($this->console->run($arguments)->failed()) {
            error('Could not stash.');

            return false;
        }

        info('Stashed — the working tree is clean.');

        return true;
    }

    private function manage(string $ref): bool
    {
        $action = Menu::choose(
            label: $ref,
            options: [
                'pop' => 'Pop — apply it and remove it from the list',
                'apply' => 'Apply — keep it in the list',
                'show' => 'Show files',
                'drop' => 'Drop — delete it',
            ],
        );

        if ($action === 'show') {
            return $this->console->run(['stash', 'show', '--name-status', '--include-untracked', $ref])->successful();
        }

        if ($action === 'drop' && ! confirm("Delete {$ref} for good?", default: false)) {
            return false;
        }

        if ($this->console->run(['stash', (string) $action, $ref])->successful()) {
            info("Done: stash {$action} {$ref}.");

            return true;
        }

        if ($this->git->conflictedFiles() !== []) {
            warning('Applied with conflicts — the stash is kept.');
            $this->make(Conflicts::class)->list();

            return false;
        }

        error("Could not {$action} {$ref}.");

        return false;
    }
}
