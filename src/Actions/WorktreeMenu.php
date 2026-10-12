<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\Console;
use Phattarachai\GitPrompt\Editor;
use Phattarachai\GitPrompt\Git;
use Phattarachai\GitPrompt\Prompts\Menu;
use Phattarachai\GitPrompt\Worktree;
use Phattarachai\GitPrompt\Worktrees;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * The Worktrees tab: what each worktree holds, and the files several of them touch.
 */
class WorktreeMenu extends Action
{
    /**
     * @param  list<Worktree>  $worktrees
     */
    public function __invoke(Worktree $worktree, array $worktrees): bool
    {
        $options = array_filter([
            'changes' => 'Changes — pick a file to view its diff',
            'stat' => 'Diff stat — lines added and removed per file',
            'lazygit' => Editor::has('lazygit') ? 'Open in lazygit' : null,
            'copy' => "Copy path — puts `cd {$worktree->path}` on the clipboard",
            'open' => 'Open in editor',
            'remove' => $this->canRemove($worktree) ? 'Remove worktree… (the branch stays)' : null,
        ]);

        $title = $worktree->name.($worktree->branch === null ? '' : " · {$worktree->branch}");

        return match (Menu::choose($title, $options)) {
            'changes' => $this->make(FileChanges::class)($worktree, $worktrees),
            'lazygit' => $this->lazygit($worktree),
            'stat' => $this->diffStat($worktree),
            'copy' => $this->copyPath($worktree),
            'open' => $this->openInEditor($worktree),
            'remove' => $this->remove($worktree),
            default => true,
        };
    }

    /**
     * @param  array<string, array<string, string>>  $overlaps
     */
    public static function overlapNote(string $path, Worktree $worktree, array $overlaps): string
    {
        $others = array_diff_key($overlaps[$path] ?? [], [$worktree->name => true]);

        if ($others === []) {
            return '';
        }

        return '  <fg=yellow>⚠ also in '.implode(', ', array_map(
            fn (string $name, string $how) => $how === 'committed' ? "{$name} (committed)" : $name,
            array_keys($others),
            $others,
        )).'</>';
    }

    public static function statusLabel(string $status): string
    {
        return match ($status[0] ?? '') {
            'A' => 'added',
            'D' => 'deleted',
            'R' => 'renamed',
            default => 'modified',
        };
    }

    private function lazygit(Worktree $worktree): bool
    {
        Editor::interactive(['lazygit', '-p', $worktree->path], $worktree->path);

        return true;
    }

    private function diffStat(Worktree $worktree): bool
    {
        $console = new Console(new Git($worktree->path));
        $base = $this->git->defaultBase();

        $this->console->line("  <options=bold>Not committed</> <fg=gray>({$worktree->name})</>");
        $console->run(['diff', '--stat', 'HEAD']);

        if ($base !== null && $worktree->branch !== null && ! $this->git->isProtected($worktree->branch)) {
            $this->console->line("  <options=bold>Committed since {$base}</>");
            $console->run(['diff', '--stat', "{$base}...HEAD"]);
        }

        return true;
    }

    private function copyPath(Worktree $worktree): bool
    {
        $command = 'cd '.escapeshellarg($worktree->path);
        $finder = new ExecutableFinder;

        foreach ([['pbcopy'], ['wl-copy'], ['xclip', '-selection', 'clipboard']] as $clipboard) {
            if ($finder->find($clipboard[0]) !== null) {
                (new Process($clipboard))->setInput($command)->run();
                info("Copied: {$command}");

                return true;
            }
        }

        $this->console->line("  {$command}");

        return true;
    }

    private function openInEditor(Worktree $worktree): bool
    {
        $editor = Editor::command($this->git);

        if ($editor === null) {
            error('No editor found — set one with: git config --global gitprompt.editor phpstorm');

            return false;
        }

        Editor::open($editor, $worktree->path);
        info("Opened {$worktree->name} in {$editor}.");

        return true;
    }

    private function canRemove(Worktree $worktree): bool
    {
        return ! $worktree->isMain && ! $worktree->isCurrent && ! $worktree->isLocked
            && ($worktree->isMissing || $worktree->isClean());
    }

    private function remove(Worktree $worktree): bool
    {
        if (! confirm("Remove the worktree folder {$worktree->path}?", default: false)) {
            return false;
        }

        $result = $worktree->isMissing
            ? $this->console->run(['worktree', 'prune'])
            : $this->console->run(['worktree', 'remove', $worktree->path]);

        if ($result->failed()) {
            error('Could not remove the worktree.');

            return false;
        }

        info("Removed {$worktree->name}.");

        if ($worktree->branch === null) {
            return true;
        }

        if ($worktree->ahead > 0) {
            warning("'{$worktree->branch}' still has {$worktree->ahead} commit(s) not in the base branch — kept.");

            return true;
        }

        if (! $this->git->isProtected($worktree->branch) && confirm("Also delete the branch '{$worktree->branch}'? It has nothing the base branch lacks.", default: false)) {
            return $this->console->run(['branch', '--delete', $worktree->branch])->successful();
        }

        return true;
    }
}
