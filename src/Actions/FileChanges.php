<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\Editor;
use Phattarachai\GitPrompt\Prompts\Cancelled;
use Phattarachai\GitPrompt\Prompts\Menu;
use Phattarachai\GitPrompt\Worktree;
use Phattarachai\GitPrompt\Worktrees;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

/**
 * Pick a changed file and read its diff in the pager (delta, if git uses it).
 * Files another worktree also touches can be compared with that worktree's copy.
 */
class FileChanges extends Action
{
    /**
     * @param  list<Worktree>  $worktrees
     */
    public function __invoke(Worktree $worktree, array $worktrees): bool
    {
        $base = $this->git->defaultBase();
        $overlaps = Worktrees::overlaps($worktrees);
        $byName = [];

        foreach ($worktrees as $other) {
            $byName[$other->name] = $other;
        }

        $files = $this->files($worktree, $base);

        if ($files === []) {
            info("Nothing changed in {$worktree->name}.");

            return true;
        }

        while (true) {
            $options = [];

            foreach ($files as $key => $file) {
                $note = strip_tags(WorktreeMenu::overlapNote($file['path'], $worktree, $overlaps));
                $options[$key] = sprintf('%-11s %-9s %s', $file['group'], $file['label'], $file['path']).$note;
            }

            try {
                $key = Menu::choose("{$worktree->name} · changes (".count($files).')', $options, 'Type to filter · Enter pick · Esc back');
            } catch (Cancelled) {
                return true;
            }

            try {
                $this->fileMenu($worktree, $files[$key], $base, $overlaps[$files[$key]['path']] ?? [], $byName);
            } catch (Cancelled) {
                continue;
            }
        }
    }

    /**
     * The changes of the worktree we are standing in.
     */
    public function current(): bool
    {
        $worktrees = (new Worktrees($this->git))->all();

        foreach ($worktrees as $worktree) {
            if ($worktree->isCurrent) {
                return $this($worktree, $worktrees);
            }
        }

        return false;
    }

    /**
     * @return array<string, array{group: string, label: string, path: string, untracked: bool, committed: bool}>
     */
    private function files(Worktree $worktree, ?string $base): array
    {
        $files = [];

        foreach ($worktree->uncommitted as $file) {
            $files['u:'.$file->path] = [
                'group' => 'uncommitted', 'label' => $file->label(), 'path' => $file->path,
                'untracked' => $file->isUntracked(), 'committed' => false,
            ];
        }

        foreach ($worktree->committed as $change) {
            $files['c:'.$change['path']] = [
                'group' => 'committed', 'label' => WorktreeMenu::statusLabel($change['status']), 'path' => $change['path'],
                'untracked' => false, 'committed' => true,
            ];
        }

        return $files;
    }

    /**
     * @param  array{group: string, label: string, path: string, untracked: bool, committed: bool}  $file
     * @param  array<string, string>  $alsoIn  worktree name => committed|uncommitted
     * @param  array<string, Worktree>  $byName
     */
    private function fileMenu(Worktree $worktree, array $file, ?string $base, array $alsoIn, array $byName): void
    {
        $options = ['diff' => $file['committed'] ? "View diff since {$base}" : 'View diff'];

        foreach ($alsoIn as $name => $how) {
            if ($name !== $worktree->name && isset($byName[$name])) {
                $options["compare:{$name}"] = "Compare with {$name}'s copy".($how === 'committed' ? ' (committed there)' : '');
            }
        }

        $options['open'] = 'Open file in editor';

        $choice = Menu::choose($file['path'], $options);

        if ($choice === 'open') {
            $this->openInEditor($worktree->path.'/'.$file['path']);

            return;
        }

        $command = match (true) {
            str_starts_with($choice, 'compare:') => ['git', 'diff', '--no-index', '--',
                self::existing($byName[substr($choice, strlen('compare:'))]->path.'/'.$file['path']),
                self::existing($worktree->path.'/'.$file['path'])],
            $file['untracked'] => ['git', 'diff', '--no-index', '--', '/dev/null', $file['path']],
            $file['committed'] => ['git', 'diff', "{$base}...HEAD", '--', $file['path']],
            default => ['git', 'diff', 'HEAD', '--', $file['path']],
        };

        $this->console->line('<fg=gray>  → '.implode(' ', array_map(fn (string $part) => str_contains($part, ' ') ? escapeshellarg($part) : $part, $command)).'</>');

        $output = Editor::interactive($command, $worktree->path);

        if ($output !== null) {
            $this->console->line($output);
        }
    }

    private function openInEditor(string $path): void
    {
        $editor = Editor::command($this->git);

        if ($editor === null) {
            error('No editor found — set one with: git config --global gitprompt.editor phpstorm');

            return;
        }

        Editor::open($editor, $path);
        info("Opened in {$editor}.");
    }

    /**
     * A missing file diffs as empty, so a file deleted on one side still compares.
     */
    private static function existing(string $path): string
    {
        return file_exists($path) ? $path : '/dev/null';
    }
}
