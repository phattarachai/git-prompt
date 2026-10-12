<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\Branch;
use Phattarachai\GitPrompt\Prompts\Menu;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class Checkout extends Action
{
    public function __invoke(Branch $branch): bool
    {
        if ($branch->isCurrent) {
            info("Already on '{$branch->name}'.");

            return true;
        }

        if ($branch->isInOtherWorktree($this->git->topLevel())) {
            warning("'{$branch->name}' is checked out in another worktree — git allows a branch in only one place.");
            $this->console->line("  cd {$branch->worktreePath}");

            return false;
        }

        $switch = $branch->isRemote()
            ? ['switch', '--track', '-c', $branch->name, $branch->ref()]
            : ['switch', $branch->name];

        $result = $this->console->run($switch);

        if ($result->successful()) {
            info("Switched to '{$branch->name}'.");

            return true;
        }

        if (! $result->mentions('would be overwritten')) {
            error("Could not check out '{$branch->name}'.");

            return false;
        }

        $choice = Menu::choose(
            label: "Your uncommitted changes clash with '{$branch->name}'.",
            options: [
                'smart' => 'Smart checkout — stash my changes, switch, then bring them back',
                'cancel' => 'Cancel',
            ],
        );

        return $choice === 'smart' && $this->smartCheckout($switch, $branch->name);
    }

    /**
     * @param  list<string>  $switch
     */
    private function smartCheckout(array $switch, string $name): bool
    {
        $stashed = $this->console->run(['stash', 'push', '--include-untracked', '-m', "g: smart checkout to {$name}"]);

        if ($stashed->failed()) {
            error('Could not stash your changes.');

            return false;
        }

        if ($this->console->run($switch)->failed()) {
            $this->console->run(['stash', 'pop']);
            error("Could not check out '{$name}'. Your changes are back where they were.");

            return false;
        }

        if ($this->console->run(['stash', 'pop'])->failed()) {
            warning("Switched to '{$name}', but your changes conflict here.");
            $this->make(Conflicts::class)->list();
            $this->console->line('  Your changes are still saved in stash@{0} — drop it once the conflicts are resolved.');

            return true;
        }

        info("Switched to '{$name}' and brought your changes along.");

        return true;
    }
}
