<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\Prompts\Menu;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class Push extends Action
{
    public function __invoke(): bool
    {
        $current = $this->git->currentBranch();

        if ($current === null) {
            error('HEAD is detached — check out a branch first.');

            return false;
        }

        $branch = $this->git->findBranch($current);
        $isNew = $branch?->upstream === null || $branch->upstreamGone;

        if (! $isNew && $branch->ahead === 0) {
            info("Nothing to push — '{$current}' has no commits that '{$branch->upstream}' lacks.");

            return true;
        }

        if ($isNew) {
            $remote = $this->git->defaultRemote();

            if ($remote === null) {
                error('This repository has no remote to push to.');

                return false;
            }

            $arguments = ['push', '--set-upstream', $remote, $current];
            $this->console->line("  New branch — will create '{$remote}/{$current}'.");
        } else {
            $arguments = ['push'];
            $this->console->line("  {$branch->ahead} commit(s) to '{$branch->upstream}':");

            foreach ($this->git->commitsBetween('@{u}', 'HEAD') as $commit) {
                $this->console->line("    {$commit}");
            }
        }

        $result = $this->console->run($arguments, 'Pushing…');

        if ($result->successful()) {
            info("Pushed '{$current}'.");

            return true;
        }

        if (! $result->mentions('rejected', 'non-fast-forward', 'fetch first')) {
            error('Push failed.');

            return false;
        }

        return $this->recoverFromRejection($current);
    }

    private function recoverFromRejection(string $current): bool
    {
        $choice = Menu::choose(
            label: "The remote '{$current}' has commits you don't have.",
            options: [
                'update' => 'Update first, then push',
                'force' => 'Force push with lease — overwrite the remote with mine',
                'cancel' => 'Cancel',
            ],
        );

        if ($choice === 'update') {
            if (! $this->make(Update::class)()) {
                return false;
            }

            return $this->retry(['push']);
        }

        if ($choice === 'force' && $this->console->confirmTyped($current, 'Force push overwrites commits on the remote')) {
            return $this->retry(['push', '--force-with-lease']);
        }

        return false;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function retry(array $arguments): bool
    {
        if ($this->console->run($arguments, 'Pushing…')->failed()) {
            error('Push failed.');

            return false;
        }

        info('Pushed.');

        return true;
    }
}
