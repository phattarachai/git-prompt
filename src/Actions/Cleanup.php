<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\Branch;
use Phattarachai\GitPrompt\Prompts\Menu;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

/**
 * Tick many old branches and delete them in one go, locally and on the remote.
 * Protected branches, the current branch and branches open in another worktree
 * are never offered.
 */
class Cleanup extends Action
{
    public function __invoke(): bool
    {
        if ($this->console->run(['fetch', '--prune'], 'Fetching…')->failed()) {
            warning('Fetch failed — the remote list may be stale.');
        }

        $candidates = $this->candidates();

        if ($candidates === []) {
            info('No branches to clean up.');

            return true;
        }

        $base = $this->git->defaultBase();
        $merged = $base === null ? [] : $this->git->mergedInto($base);

        $selected = Menu::chooseMany(
            label: 'Branches to delete'.($base === null ? '' : " — 'merged' means merged into {$base}"),
            entries: $this->entries($candidates, $merged),
            hint: "Space tick · Ctrl+A tick all matches (try typing 'merged' or 'gone') · Enter confirm · Esc back",
        );

        $branches = array_values(array_filter($candidates, fn (Branch $branch) => in_array($branch->ref(), $selected, true)));

        if ($branches === []) {
            info('Nothing selected.');

            return true;
        }

        $hasRemote = array_filter($branches, fn (Branch $branch) => $this->remoteRef($branch) !== null) !== [];

        $scope = $hasRemote ? Menu::choose('Delete '.count($branches).' branch(es) from', [
            'both' => 'Local and remote',
            'local' => 'Local only',
            'cancel' => 'Cancel',
        ]) : 'local';

        if ($scope === 'cancel') {
            return false;
        }

        foreach ($branches as $branch) {
            $remote = $scope === 'both' ? $this->remoteRef($branch) : null;
            $where = array_filter([$branch->isRemote() ? null : 'local', $remote]);
            $this->console->line('  <fg=red>✕</> '.$branch->ref().'  <fg=gray>'.implode(' + ', $where).'</>');
        }

        if (! confirm('Delete these?', default: false)) {
            return false;
        }

        $deletedLocal = $this->deleteLocal(array_values(array_filter($branches, fn (Branch $branch) => ! $branch->isRemote())));

        $remoteTargets = $scope === 'both'
            ? array_filter($branches, fn (Branch $branch) => $branch->isRemote() || in_array($branch->name, $deletedLocal, true))
            : [];

        $deletedRemote = $this->deleteRemote(array_values(array_filter(array_map($this->remoteRef(...), $remoteTargets))));

        info('Deleted '.count($deletedLocal).' local and '.$deletedRemote.' remote branch(es).');

        return true;
    }

    /**
     * @return list<Branch>
     */
    private function candidates(): array
    {
        $topLevel = $this->git->topLevel();
        $skipped = 0;
        $candidates = [];

        foreach ($this->git->branches() as $branch) {
            if ($branch->isCurrent || $this->git->isProtected($branch->name)) {
                continue;
            }

            if ($branch->isInOtherWorktree($topLevel)) {
                $skipped++;

                continue;
            }

            $candidates[] = $branch;
        }

        if ($skipped > 0) {
            note("{$skipped} branch(es) open in another worktree are hidden — remove the worktree first.");
        }

        return array_reverse($candidates);
    }

    /**
     * Oldest first, so the stale ones are on top.
     *
     * @param  list<Branch>  $branches
     * @param  list<string>  $merged
     * @return array<string, array{label: string, search: string}>
     */
    private function entries(array $branches, array $merged): array
    {
        $entries = [];

        foreach ($branches as $branch) {
            $tags = array_filter([
                in_array($branch->ref(), $merged, true) ? 'merged' : null,
                $branch->upstreamGone ? 'gone' : null,
                match (true) {
                    $branch->isRemote() => 'remote only',
                    $this->remoteRef($branch) === null => 'local only',
                    default => null,
                },
                $branch->relativeDate,
            ]);

            $entries[$branch->ref()] = [
                'label' => $branch->ref().'   '.implode(' · ', $tags),
                'search' => $branch->ref().' '.implode(' ', $tags),
            ];
        }

        return $entries;
    }

    /**
     * `origin/feat/x` for a branch that exists on the remote, else null.
     */
    private function remoteRef(Branch $branch): ?string
    {
        if ($branch->isRemote()) {
            return $branch->ref();
        }

        return $branch->upstream !== null && ! $branch->upstreamGone ? $branch->upstream : null;
    }

    /**
     * @param  list<Branch>  $branches
     * @return list<string> names that were deleted
     */
    private function deleteLocal(array $branches): array
    {
        if ($branches === []) {
            return [];
        }

        $names = array_map(fn (Branch $branch) => $branch->name, $branches);
        $result = $this->console->run(['branch', '--delete', ...$names]);

        preg_match_all("/branch '([^']+)' is not fully merged/", $result->allOutput(), $matches);
        $unmerged = $matches[1];

        if ($unmerged !== []) {
            warning(count($unmerged).' branch(es) have commits that are not merged anywhere: '.implode(', ', $unmerged));

            if (confirm('Delete them anyway?', default: false)) {
                $this->console->run(['branch', '--delete', '--force', ...$unmerged]);
            }
        }

        $remaining = $this->git->value(['for-each-ref', '--format=%(refname:short)', 'refs/heads']);
        $stillThere = explode("\n", $remaining);

        return array_values(array_filter($names, fn (string $name) => ! in_array($name, $stillThere, true)));
    }

    /**
     * @param  list<string>  $refs  `origin/feat/x`
     */
    private function deleteRemote(array $refs): int
    {
        $byRemote = [];

        foreach ($refs as $ref) {
            [$remote, $name] = explode('/', $ref, 2);
            $byRemote[$remote][] = $name;
        }

        $deleted = 0;

        foreach ($byRemote as $remote => $names) {
            $result = $this->console->run(['push', $remote, '--delete', ...$names], 'Deleting on the remote…');
            $deleted += $result->successful() ? count($names) : substr_count($result->allOutput(), '[deleted]');
        }

        return $deleted;
    }
}
