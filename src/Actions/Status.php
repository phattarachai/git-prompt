<?php

namespace Phattarachai\GitPrompt\Actions;

use Phattarachai\GitPrompt\FileStatus;
use Phattarachai\GitPrompt\Worktree;
use Phattarachai\GitPrompt\Worktrees;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * What this branch changed: files not committed yet, and files committed since it left
 * the base branch — each flagged when another worktree touches the same file.
 */
class Status extends Action
{
    public function __invoke(): bool
    {
        if (($operation = $this->git->operationInProgress()) !== null) {
            warning(ucfirst($operation).' in progress — run `g` and pick Continue or Abort.');
        }

        $worktrees = count($this->git->worktreeList()) > 1 ? (new Worktrees($this->git))->all() : [];
        $current = array_values(array_filter($worktrees, fn (Worktree $worktree) => $worktree->isCurrent))[0] ?? null;
        $overlaps = Worktrees::overlaps($worktrees);
        $alsoIn = fn (string $path) => $current === null ? '' : strip_tags(WorktreeMenu::overlapNote($path, $current, $overlaps));

        $files = $this->git->status();

        if ($files === []) {
            info('Nothing uncommitted — working tree clean.');
        } else {
            $hasStaged = array_filter($files, fn (FileStatus $file) => $file->isStaged()) !== [];

            self::table(['', 'Not committed', 'File', ''], array_map(fn (FileStatus $file) => [
                $file->isStaged() ? '●' : '',
                $file->label(),
                $file->display(),
                $alsoIn($file->path),
            ], $files));

            if ($hasStaged) {
                $this->console->line('<fg=gray>  ● = staged</>');
            }
        }

        $this->committed($alsoIn);

        return true;
    }

    /**
     * @param  \Closure(string): string  $alsoIn
     */
    private function committed(\Closure $alsoIn): void
    {
        $branch = $this->git->currentBranch();
        $base = $this->git->defaultBase();

        if ($branch === null || $base === null || $this->git->isProtected($branch)) {
            return;
        }

        $changes = $this->git->committedChanges($base);
        $commits = $this->git->countCommitsBetween($base, 'HEAD');

        if ($changes === []) {
            $this->console->line("<fg=gray>  No commits on '{$branch}' since {$base}.</>");

            return;
        }

        self::table(["Committed since {$base} ({$commits} commit(s))", 'File', ''], array_map(fn (array $change) => [
            WorktreeMenu::statusLabel($change['status']),
            $change['path'],
            $alsoIn($change['path']),
        ], $changes));
    }

    /**
     * A table without the columns that are empty on every row.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private static function table(array $headers, array $rows): void
    {
        $keep = array_keys(array_filter($headers, fn (string $header, int $column) => $header !== ''
            || array_filter($rows, fn (array $row) => $row[$column] !== '') !== [], ARRAY_FILTER_USE_BOTH));

        table(
            headers: array_map(fn (int $column) => $headers[$column], $keep),
            rows: array_map(fn (array $row) => array_map(fn (int $column) => $row[$column], $keep), $rows),
        );
    }
}
