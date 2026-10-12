<?php

namespace Phattarachai\GitPrompt;

final readonly class Branch
{
    public function __construct(
        public string $name,
        public ?string $remote = null,
        public bool $isCurrent = false,
        public ?string $upstream = null,
        public int $ahead = 0,
        public int $behind = 0,
        public bool $upstreamGone = false,
        public ?string $worktreePath = null,
        public string $relativeDate = '',
    ) {}

    /**
     * A branch that exists only on the remote (no local branch of the same name).
     */
    public function isRemote(): bool
    {
        return $this->remote !== null;
    }

    /**
     * The ref git understands for this branch: `develop` or `origin/develop`.
     */
    public function ref(): string
    {
        return $this->isRemote() ? "{$this->remote}/{$this->name}" : $this->name;
    }

    /**
     * Checked out in a worktree other than the one we are standing in.
     */
    public function isInOtherWorktree(string $currentWorktree): bool
    {
        return $this->worktreePath !== null
            && $this->worktreePath !== ''
            && realpath($this->worktreePath) !== realpath($currentWorktree);
    }
}
