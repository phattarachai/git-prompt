<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\Prompts\Menu;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Update Project: fetch, then bring the current branch level with its upstream.
 */
class Update extends Action
{
    public function __invoke(): bool
    {
        $current = $this->git->currentBranch();

        if ($current === null) {
            error('HEAD is detached — check out a branch first.');

            return false;
        }

        if ($this->console->run(['fetch', '--prune'], 'Fetching…')->failed()) {
            error('Fetch failed.');

            return false;
        }

        $branch = $this->git->findBranch($current);

        if ($branch?->upstream === null) {
            warning("'{$current}' has no upstream yet — fetched only. Push it to create one.");

            return true;
        }

        if ($branch->upstreamGone) {
            warning("'{$branch->upstream}' was deleted on the remote — nothing to update from.");

            return false;
        }

        if ($branch->behind === 0) {
            info("'{$current}' is up to date".($branch->ahead > 0 ? " ({$branch->ahead} commit(s) to push)." : '.'));

            return true;
        }

        $arguments = $branch->ahead === 0
            ? ['merge', '--ff-only', '--autostash', '@{u}']
            : $this->divergedArguments($current, $branch->upstream, $branch->ahead, $branch->behind);

        if ($arguments === null) {
            return false;
        }

        $result = $this->console->run($arguments);

        if ($result->successful()) {
            info("Updated '{$current}' — pulled {$branch->behind} commit(s).");

            return true;
        }

        return $this->make(Conflicts::class)->handle($result, 'Update');
    }

    /**
     * @return list<string>|null
     */
    private function divergedArguments(string $current, string $upstream, int $ahead, int $behind): ?array
    {
        $strategy = $this->git->updateStrategy();

        if ($strategy === 'ask') {
            $strategy = Menu::choose(
                label: "'{$current}' and '{$upstream}' have diverged (↑{$ahead} ↓{$behind}).",
                options: [
                    'rebase' => 'Rebase — replay my commits on top (straight history)',
                    'merge' => 'Merge — keep both, add a merge commit',
                    'cancel' => 'Cancel',
                ],
                hint: 'Default it with: git config --global gitprompt.update rebase',
            );
        }

        return match ($strategy) {
            'rebase' => ['rebase', '--autostash', '@{u}'],
            'merge' => ['merge', '--autostash', '--no-edit', '@{u}'],
            default => null,
        };
    }
}
