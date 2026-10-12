<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\Branch;
use Phattarachai\GitPrompt\BranchOrder;
use Phattarachai\GitPrompt\Editor;
use Phattarachai\GitPrompt\Prompts\Cancelled;
use Phattarachai\GitPrompt\Prompts\TabbedSearch;
use Phattarachai\GitPrompt\Worktree;
use Phattarachai\GitPrompt\Worktrees;

use function Laravel\Prompts\intro;

/**
 * The main popup: Branches (default), Actions and — with more than one worktree — Worktrees, each type-to-filter.
 * Esc in a submenu comes back to the tab you left; Esc here quits.
 */
class Palette extends Action
{
    private int $lastTab = 0;

    public const int WORKTREES_TAB = 2;

    public function __invoke(bool $branchesOnly = false, int $startTab = 0): bool
    {
        $this->header();
        $this->lastTab = $startTab;

        while (true) {
            $branches = BranchOrder::sort($this->git->branches(), $this->git->protectedBranches(), $this->git->branchSort());
            $inProgress = $this->git->operationInProgress() !== null;

            $tabs = [['title' => 'Branches', 'entries' => $this->branchEntries($branches)]];

            if (! $branchesOnly || $inProgress) {
                $tabs[] = ['title' => 'Actions', 'entries' => $this->actionEntries($branchesOnly)];
            }

            $worktrees = [];

            if (! $branchesOnly && count($this->git->worktreeList()) > 1) {
                $worktrees = (new Worktrees($this->git))->all();
                $tabs[] = ['title' => 'Worktrees', 'entries' => $this->worktreeEntries($worktrees)];
            }

            $search = new TabbedSearch(
                tabs: $tabs,
                active: $inProgress ? 1 : min($this->lastTab, count($tabs) - 1),
                hint: count($tabs) > 1 ? 'Type to filter · Tab switch tab · Enter pick · Esc quit' : 'Type to filter · Enter pick · Esc quit',
            );

            try {
                $choice = (string) $search->prompt();
            } catch (Cancelled) {
                return true;
            }

            $this->lastTab = $search->activeTab();

            if ($choice === 'sort') {
                $this->lastTab = 0;
                $this->toggleSort();

                continue;
            }

            try {
                return $this->dispatch($choice, $branches, $worktrees);
            } catch (Cancelled) {
                continue;
            }
        }
    }

    public function header(): void
    {
        $current = $this->git->currentBranch();
        $branch = $current === null ? null : $this->git->findBranch($current);
        $changes = count($this->git->status());

        $parts = [
            $this->git->repositoryName(),
            $current ?? "detached at {$this->git->headShortSha()}",
            $branch === null ? '' : self::tracking($branch),
            $changes === 0 ? 'clean' : "{$changes} changed",
        ];

        intro(' '.implode(' · ', array_filter($parts)).' ');
    }

    /**
     * @param  list<Branch>  $branches
     * @param  list<Worktree>  $worktrees
     */
    private function dispatch(string $choice, array $branches, array $worktrees): bool
    {
        if (str_starts_with($choice, 'branch:')) {
            return $this->make(BranchMenu::class)($branches[(int) substr($choice, strlen('branch:'))]);
        }

        if (str_starts_with($choice, 'worktree:')) {
            return $this->make(WorktreeMenu::class)($worktrees[(int) substr($choice, strlen('worktree:'))], $worktrees);
        }

        if ($choice === 'overlaps') {
            return $this->make(Overlaps::class)($worktrees);
        }

        $conflicts = $this->make(Conflicts::class);

        return match ($choice) {
            'continue' => $conflicts->continue(),
            'abort' => $conflicts->abort(),
            'update' => $this->make(Update::class)(),
            'commit' => $this->make(Commit::class)(),
            'push' => $this->make(Push::class)(),
            'new' => $this->make(NewBranch::class)(),
            'stash' => $this->make(Stash::class)(),
            'status' => $this->make(Status::class)(),
            'changes' => $this->make(FileChanges::class)->current(),
            'lazygit' => $this->lazygit(),
            'cleanup' => $this->make(Cleanup::class)(),
            default => true,
        };
    }

    private function lazygit(): bool
    {
        Editor::interactive(['lazygit'], $this->git->topLevel());

        return true;
    }

    private function toggleSort(): void
    {
        $this->console->run(['config', '--global', 'gitprompt.sort', $this->git->branchSort() === 'name' ? 'recent' : 'name']);
    }

    /**
     * @return array<string, array{label: string, search: string}>
     */
    private function actionEntries(bool $branchesOnly): array
    {
        $actions = [];

        if (($operation = $this->git->operationInProgress()) !== null) {
            $actions['continue'] = ['▶', "Continue {$operation}"];
            $actions['abort'] = ['✕', "Abort {$operation}"];
        }

        if (! $branchesOnly) {
            $actions += [
                'update' => ['↓', 'Update Project'],
                'commit' => ['✓', 'Commit…'],
                'push' => ['↑', 'Push…'],
                'new' => ['+', 'New Branch…'],
                'stash' => ['≡', 'Stash…'],
                'status' => ['•', 'Status'],
                'changes' => ['◇', 'Changes… (view diff of each file)'],
                'lazygit' => Editor::has('lazygit') ? ['⧉', 'Open lazygit'] : null,
                'cleanup' => ['✕', 'Delete Branches… (clean up)'],
            ];
        }

        if (! $branchesOnly) {
            $actions['sort'] = ['⇅', $this->git->branchSort() === 'name' ? 'Sort branches by recent commit' : 'Sort branches by name'];
        }

        return array_map(fn (array $action) => ['label' => "{$action[0]} {$action[1]}", 'search' => $action[1]], array_filter($actions));
    }

    /**
     * @param  list<Branch>  $branches
     * @return array<string, array{label: string, search: string}>
     */
    private function branchEntries(array $branches): array
    {
        $protected = $this->git->protectedBranches();
        $topLevel = $this->git->topLevel();
        $entries = [];

        foreach ($branches as $index => $branch) {
            $mark = match (true) {
                $branch->isCurrent => '●',
                in_array($branch->name, $protected, true) => '★',
                default => ' ',
            };

            $details = array_filter([
                self::tracking($branch),
                $branch->isInOtherWorktree($topLevel) ? 'worktree' : '',
                $branch->relativeDate,
            ]);

            $entries["branch:{$index}"] = [
                'label' => "{$mark} {$branch->ref()}".($details === [] ? '' : '   '.implode(' · ', $details)),
                'search' => $branch->ref(),
            ];
        }

        return $entries;
    }

    /**
     * @param  list<Worktree>  $worktrees
     * @return array<string, array{label: string, search: string}>
     */
    private function worktreeEntries(array $worktrees): array
    {
        $entries = [];
        $overlaps = Worktrees::overlaps($worktrees);

        if ($overlaps !== []) {
            $entries['overlaps'] = [
                'label' => '⚠ '.count($overlaps).' file(s) edited in more than one worktree',
                'search' => 'overlaps conflicts',
            ];
        }

        $width = max(array_map(fn (Worktree $worktree) => mb_strlen($worktree->name), $worktrees));
        $branchWidth = max(array_map(fn (Worktree $worktree) => mb_strlen($worktree->branch ?? 'detached'), $worktrees));

        foreach ($worktrees as $index => $worktree) {
            $mark = $worktree->isCurrent ? '●' : ' ';
            $branch = $worktree->branch ?? 'detached';
            $locked = $worktree->isLocked ? ' · locked' : '';

            $entries["worktree:{$index}"] = [
                'label' => sprintf("%s %-{$width}s   %-{$branchWidth}s   %s%s", $mark, $worktree->name, $branch, $worktree->summary(), $locked),
                'search' => "{$worktree->name} {$branch}",
            ];
        }

        return $entries;
    }

    private static function tracking(Branch $branch): string
    {
        if ($branch->upstreamGone) {
            return 'upstream gone';
        }

        return trim(($branch->ahead > 0 ? "↑{$branch->ahead} " : '').($branch->behind > 0 ? "↓{$branch->behind}" : ''));
    }
}
