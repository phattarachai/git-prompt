<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\Branch;
use Phattarachai\GitPrompt\Prompts\Menu;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

/**
 * Merge and rebase between the current branch and another one.
 */
class Integrate extends Action
{
    /**
     * Merge $source into the current branch. No typed confirmation, even on a protected branch:
     * the merge stays local until a push, and Push asks for it.
     */
    public function merge(Branch $source): bool
    {
        $current = $this->git->currentBranch();

        if ($current === null) {
            error('HEAD is detached — check out a branch first.');

            return false;
        }

        if ($this->git->countCommitsBetween($current, $source->ref()) === 0) {
            info("Nothing to merge — '{$current}' already has everything in '{$source->ref()}'.");

            return true;
        }

        $result = $this->console->run(['merge', '--no-edit', '--autostash', $source->ref()]);

        if ($result->successful()) {
            info("Merged '{$source->ref()}' into '{$current}'.");

            return true;
        }

        return $this->make(Conflicts::class)->handle($result, 'Merge');
    }

    /**
     * Rebase the current branch onto $base.
     */
    public function rebaseOnto(Branch $base): bool
    {
        $current = $this->git->currentBranch();

        if ($current === null) {
            error('HEAD is detached — check out a branch first.');

            return false;
        }

        if ($this->git->countCommitsBetween($current, $base->ref()) === 0) {
            info("'{$current}' is already on top of '{$base->ref()}'.");

            return true;
        }

        if ($this->git->countMergesBetween($base->ref(), $current) > 0) {
            $choice = Menu::choose(
                label: "'{$current}' has merge commits that '{$base->ref()}' lacks — a rebase replays every commit they brought in.",
                options: [
                    'merge' => "Merge '{$base->ref()}' into '{$current}' instead",
                    'rebase' => 'Rebase anyway (flattens the merges)',
                    'cancel' => 'Cancel',
                ],
            );

            if ($choice !== 'rebase') {
                return $choice === 'merge' && $this->merge($base);
            }
        }

        if ($this->git->isProtected($current) && ! $this->console->confirmTyped($current, "Rebase protected '{$current}' (rewrites its history)")) {
            return false;
        }

        $result = $this->console->run(['rebase', '--autostash', $base->ref()]);

        if ($result->successful()) {
            info("Rebased '{$current}' onto '{$base->ref()}'.");

            return true;
        }

        return $this->make(Conflicts::class)->handle($result, 'Rebase');
    }

    /**
     * Check out $branch, then rebase it onto the branch we were on.
     */
    public function checkoutAndRebase(Branch $branch): bool
    {
        $base = $this->git->currentBranch();

        if ($base === null || ! $this->make(Checkout::class)($branch)) {
            return false;
        }

        return $this->rebaseOnto(new Branch($base));
    }
}
