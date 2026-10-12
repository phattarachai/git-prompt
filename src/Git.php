<?php

namespace Phattarachai\GitPrompt;

use Symfony\Component\Process\Process;

class Git
{
    private const string SEPARATOR = "\x1f";

    /** @var list<string> */
    public const array DEFAULT_PROTECTED = ['main', 'master', 'develop'];

    public function __construct(public readonly string $cwd) {}

    /**
     * Run git and capture the result. Never throws on a non-zero exit.
     *
     * @param  list<string>  $arguments
     */
    public function run(array $arguments): Result
    {
        $process = new Process(['git', ...$arguments], $this->cwd, [
            'LANGUAGE' => 'en',
            'GIT_EDITOR' => 'true',
            'GIT_TERMINAL_PROMPT' => '0',
        ], timeout: null);

        $process->run();

        return new Result(
            command: self::describe($arguments),
            exitCode: (int) $process->getExitCode(),
            output: rtrim($process->getOutput()),
            errorOutput: rtrim($process->getErrorOutput()),
        );
    }

    /**
     * @param  list<string>  $arguments
     */
    public function value(array $arguments): string
    {
        $result = $this->run($arguments);

        return $result->successful() ? trim($result->output) : '';
    }

    public function isRepository(): bool
    {
        return $this->value(['rev-parse', '--is-inside-work-tree']) === 'true';
    }

    public function topLevel(): string
    {
        return $this->value(['rev-parse', '--show-toplevel']);
    }

    public function repositoryName(): string
    {
        return basename($this->topLevel());
    }

    /**
     * Null when HEAD is detached.
     */
    public function currentBranch(): ?string
    {
        $name = $this->value(['symbolic-ref', '--quiet', '--short', 'HEAD']);

        return $name === '' ? null : $name;
    }

    public function headShortSha(): string
    {
        return $this->value(['rev-parse', '--short', 'HEAD']);
    }

    /**
     * Local branches first (most recently committed first), then remote branches
     * that have no local branch of the same name.
     *
     * @return list<Branch>
     */
    public function branches(): array
    {
        $format = implode(self::SEPARATOR, [
            '%(refname)', '%(HEAD)', '%(upstream:short)', '%(upstream:track,nobracket)',
            '%(worktreepath)', '%(committerdate:relative)',
        ]);

        $lines = $this->value(['for-each-ref', '--sort=-committerdate', "--format={$format}", 'refs/heads', 'refs/remotes']);

        $local = [];
        $remote = [];

        foreach (array_filter(explode("\n", $lines)) as $line) {
            [$refname, $head, $upstream, $track, $worktree, $date] = array_pad(explode(self::SEPARATOR, $line), 6, '');

            if (str_starts_with($refname, 'refs/heads/')) {
                [$ahead, $behind] = self::parseTrack($track);

                $local[] = new Branch(
                    name: substr($refname, strlen('refs/heads/')),
                    isCurrent: $head === '*',
                    upstream: $upstream === '' ? null : $upstream,
                    ahead: $ahead,
                    behind: $behind,
                    upstreamGone: $track === 'gone',
                    worktreePath: $worktree === '' ? null : $worktree,
                    relativeDate: $date,
                );

                continue;
            }

            [$remoteName, $name] = array_pad(explode('/', substr($refname, strlen('refs/remotes/')), 2), 2, '');

            if ($name === '' || $name === 'HEAD') {
                continue;
            }

            $remote[] = new Branch(name: $name, remote: $remoteName, relativeDate: $date);
        }

        $localNames = array_map(fn (Branch $branch) => $branch->name, $local);
        $trackedRefs = array_filter(array_map(fn (Branch $branch) => $branch->upstream, $local));

        $remoteOnly = array_filter(
            $remote,
            fn (Branch $branch) => ! in_array($branch->name, $localNames, true) && ! in_array($branch->ref(), $trackedRefs, true),
        );

        return [...$local, ...array_values($remoteOnly)];
    }

    public function findBranch(string $ref): ?Branch
    {
        foreach ($this->branches() as $branch) {
            if ($branch->ref() === $ref || (! $branch->isRemote() && $branch->name === $ref)) {
                return $branch;
            }
        }

        return null;
    }

    /**
     * @return list<FileStatus>
     */
    public function status(): array
    {
        $raw = $this->run(['status', '--porcelain=v1', '-z', '--untracked-files=all'])->output;
        $parts = explode("\0", $raw);
        $files = [];

        for ($i = 0; $i < count($parts); $i++) {
            $entry = $parts[$i];

            if (strlen($entry) < 4) {
                continue;
            }

            $index = $entry[0];
            $worktree = $entry[1];
            $originalPath = null;

            if ($index === 'R' || $index === 'C') {
                $originalPath = $parts[++$i] ?? null;
            }

            $files[] = new FileStatus($index, $worktree, substr($entry, 3), $originalPath);
        }

        return $files;
    }

    /**
     * Uncommitted changes to tracked files (untracked files never block a checkout).
     */
    public function hasTrackedChanges(): bool
    {
        foreach ($this->status() as $file) {
            if (! $file->isUntracked()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function conflictedFiles(): array
    {
        $output = $this->value(['diff', '--name-only', '--diff-filter=U']);

        return $output === '' ? [] : explode("\n", $output);
    }

    /**
     * 'rebase', 'merge', 'cherry-pick', 'revert' or null.
     */
    public function operationInProgress(): ?string
    {
        $markers = [
            'rebase' => ['rebase-merge', 'rebase-apply'],
            'merge' => ['MERGE_HEAD'],
            'cherry-pick' => ['CHERRY_PICK_HEAD'],
            'revert' => ['REVERT_HEAD'],
        ];

        foreach ($markers as $operation => $paths) {
            foreach ($paths as $path) {
                if (file_exists($this->gitPath($path))) {
                    return $operation;
                }
            }
        }

        return null;
    }

    /**
     * Commits on $from that are not on $to, newest first.
     *
     * @return list<string>
     */
    public function commitsBetween(string $to, string $from, int $limit = 20): array
    {
        $output = $this->value(['log', '--format=%h %s (%cr)', "--max-count={$limit}", "{$to}..{$from}"]);

        return $output === '' ? [] : explode("\n", $output);
    }

    public function countCommitsBetween(string $to, string $from): int
    {
        return (int) $this->value(['rev-list', '--count', "{$to}..{$from}"]);
    }

    /**
     * @return list<string>
     */
    public function remotes(): array
    {
        $output = $this->value(['remote']);

        return $output === '' ? [] : explode("\n", $output);
    }

    /**
     * The remote a new branch is pushed to: `origin` when it exists, else the first one.
     */
    public function defaultRemote(): ?string
    {
        $remotes = $this->remotes();

        return in_array('origin', $remotes, true) ? 'origin' : ($remotes[0] ?? null);
    }

    public function isValidBranchName(string $name): bool
    {
        return $name !== '' && $this->run(['check-ref-format', '--branch', $name])->successful();
    }

    /**
     * Branches pinned on top (in this order) that need a typed confirmation before a merge,
     * push or delete touches them. Set with `git config --global gitprompt.protected "develop uat main"`;
     * a repository's own value replaces the global one.
     *
     * @return list<string>
     */
    public function protectedBranches(): array
    {
        $configured = $this->value(['config', '--get', 'gitprompt.protected']);

        if ($configured === '') {
            return self::DEFAULT_PROTECTED;
        }

        return array_values(array_unique(array_filter(preg_split('/[\s,]+/', $configured) ?: [])));
    }

    public function isProtected(string $branch): bool
    {
        return in_array($branch, $this->protectedBranches(), true);
    }

    /**
     * How a diverged branch is updated: `rebase`, `merge`, or `ask` (the default).
     */
    public function updateStrategy(): string
    {
        $strategy = $this->value(['config', '--get', 'gitprompt.update']);

        return in_array($strategy, ['rebase', 'merge'], true) ? $strategy : 'ask';
    }

    /**
     * How branches are listed after the protected ones: `recent` (default) or `name`.
     */
    public function branchSort(): string
    {
        return $this->value(['config', '--get', 'gitprompt.sort']) === 'name' ? 'name' : 'recent';
    }

    /**
     * The remote's default branch, e.g. `origin/develop`, or null when unknown.
     */
    public function defaultBase(): ?string
    {
        $remote = $this->defaultRemote();

        if ($remote === null) {
            return null;
        }

        $base = $this->value(['rev-parse', '--abbrev-ref', "{$remote}/HEAD"]);

        return $base === '' || $base === "{$remote}/HEAD" ? null : $base;
    }

    /**
     * Refs (`feat/x`, `origin/feat/x`) whose commits are all contained in $base.
     *
     * @return list<string>
     */
    public function mergedInto(string $base): array
    {
        $output = $this->value(['for-each-ref', "--merged={$base}", '--format=%(refname)', 'refs/heads', 'refs/remotes']);

        return array_values(array_map(
            fn (string $refname) => preg_replace('#^refs/(heads|remotes)/#', '', $refname) ?? $refname,
            array_filter(explode("\n", $output)),
        ));
    }

    /**
     * @return list<array{ref: string, subject: string, date: string}>
     */
    public function stashes(): array
    {
        $output = $this->value(['stash', 'list', '--format=%gd'.self::SEPARATOR.'%s'.self::SEPARATOR.'%cr']);

        if ($output === '') {
            return [];
        }

        return array_map(function (string $line): array {
            [$ref, $subject, $date] = array_pad(explode(self::SEPARATOR, $line), 3, '');

            return ['ref' => $ref, 'subject' => $subject, 'date' => $date];
        }, explode("\n", $output));
    }

    private function gitPath(string $path): string
    {
        $resolved = $this->value(['rev-parse', '--git-path', $path]);

        return str_starts_with($resolved, '/') ? $resolved : $this->cwd.'/'.$resolved;
    }

    /**
     * @return array{int, int}
     */
    private static function parseTrack(string $track): array
    {
        preg_match('/ahead (\d+)/', $track, $ahead);
        preg_match('/behind (\d+)/', $track, $behind);

        return [(int) ($ahead[1] ?? 0), (int) ($behind[1] ?? 0)];
    }

    /**
     * The command as the user would type it.
     *
     * @param  list<string>  $arguments
     */
    public static function describe(array $arguments): string
    {
        return 'git '.implode(' ', array_map(
            fn (string $argument) => preg_match('/^[\w.\/@{}:=,+-]+$/', $argument) === 1 ? $argument : escapeshellarg($argument),
            $arguments,
        ));
    }
}
