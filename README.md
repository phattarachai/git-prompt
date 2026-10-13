# Git Prompt

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phattarachai/git-prompt.svg?style=flat-square)](https://packagist.org/packages/phattarachai/git-prompt)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/git-prompt/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/git-prompt/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Code Style](https://img.shields.io/github/actions/workflow/status/phattarachai/git-prompt/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/phattarachai/git-prompt/actions/workflows/fix-php-code-style-issues.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phattarachai/git-prompt/php?style=flat-square&label=php&logo=php&logoColor=white)](https://packagist.org/packages/phattarachai/git-prompt)
![Built on Laravel Prompts](https://img.shields.io/badge/built%20on-Laravel%20Prompts-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Total Downloads](https://img.shields.io/packagist/dt/phattarachai/git-prompt.svg?style=flat-square)](https://packagist.org/packages/phattarachai/git-prompt)

A PHPStorm-style git branch popup for the terminal. Type `g`, type a few letters of a branch or an action,
press Enter. Built on [Laravel Prompts](https://github.com/laravel/prompts), but it is a standalone CLI: it
works in any git repository, Laravel or not, from one global install.

```
 shop-app · develop · ↓5 · 3 changed

 ┌  Branches   Actions  ──────────────────────────────┐
 │ cal                                                │
 ├────────────────────────────────────────────────────┤
 │ › hotfix/cart-api-log-tab   ↑1 · 2 hours ago       │
 └────────────────────────────────────────────────────┘
  Type to filter · Tab switch tab · Enter pick · Esc quit
```

- **Two tabs, both type-to-filter.** *Branches* (the default) pins your main branches on top in the order you
  configure, then the other local branches, then remote branches you don't have locally. *Actions* holds Update
  Project, Commit, Push, New Branch, Stash, Status, Delete Branches. Tab switches; every submenu filters the same way,
  so `d` + Enter is Delete. Esc steps back, Ctrl+C quits.
- **The branch submenu you know**: Checkout, Checkout and Update, Checkout and Rebase onto current, New Branch
  from, Compare with current, Merge into current, Rebase current onto, Rename, Delete.
- **Shows the git it runs.** Every command is printed before it runs (`→ git switch develop`), so nothing is hidden
  and you learn the commands as you go.
- **Guard rails**: protected branches need their name typed back before a push, rebase or delete (a merge stays local, so it just runs); smart
  checkout stashes clashing changes and brings them back; a rejected push offers update-then-push or
  `--force-with-lease`; never a plain `--force`.
- **Conflicts are a state, not a dead end.** A merge or rebase that stops on conflicts offers Abort; resolve in your
  editor, run `g` again and pick **Continue** — it refuses while conflict markers remain.
- **Clean up in bulk.** Tick many branches — type `merged` or `gone`, Ctrl+A ticks every match — and delete them
  locally and on the remote in one go. Unmerged work gets a second warning; protected branches are never offered.
- **Worktree aware.** A branch checked out in another worktree is marked, and checkout tells you where it lives
  instead of failing.
- **A Worktrees tab for parallel work** (several agents or people, one repository). Every worktree in one list —
  branch, files not committed, commits not yet in the base branch — and a warning row for **files edited in more than
  one worktree**, the ones that will conflict when the branches meet. Pick a worktree to see its changes, open it in
  your editor or lazygit, copy its `cd`, or remove it once it is clean.
- **Read every changed file's diff.** Pick a file from the changes list to open its diff in git's pager (delta, if you
  use it) and come back for the next one with `q`. A file another worktree also touches can be compared with that
  worktree's copy before the branches meet.

## Install

```bash
composer global require phattarachai/git-prompt
```

Then put Composer's global bin on your `PATH`, or alias it:

```bash
alias g="$HOME/.composer/vendor/bin/g"
```

## Usage

| Command | Does |
|---|---|
| `g` | Search branches and actions |
| `g b` | Branches only |
| `g s` | Status |
| `g up` | Update Project — fetch, then fast-forward; asks rebase or merge when diverged |
| `g c` | Commit — pick files, write a message, optionally push |
| `g p` | Push — sets the upstream for a new branch |
| `g st` | Stash — save, or pop / apply / show / drop a saved one |
| `g n` | New branch from the current one |
| `g d` | Delete many branches, locally and on the remote |
| `g v` | View changes — pick a file, read its diff |
| `g w` | Worktrees — what each one changed, and files edited in more than one |

`g s` lists both what is not committed and what this branch committed since the base branch (`origin/HEAD`),
flagging files another worktree touches too.

## Configuration

Plain `git config`, so it can be global or per repository:

```bash
# Pinned on top in this order, and need their name typed back before a push, rebase or delete (default: main master develop)
git config --global gitprompt.protected "develop uat main"

# Branch order after the pinned ones: recent | name (default: recent; also toggled from the Actions tab)
git config --global gitprompt.sort name

# How a diverged branch updates: rebase | merge (default: ask every time)
git config --global gitprompt.update rebase

# Editor for "Open in editor" (default: the first of phpstorm, pstorm, code, cursor on your PATH)
git config --global gitprompt.editor phpstorm
```

Diffs open in git's own pager, so they look the way `git diff` does for you. For syntax-highlighted, side-by-side
diffs, [delta](https://github.com/dandavison/delta) is a good pager:

```bash
brew install git-delta
git config --global core.pager delta
git config --global delta.side-by-side true
```

When [lazygit](https://github.com/jesseduffield/lazygit) is installed, the Actions tab and each worktree's menu offer
**Open lazygit**, for reviewing many files with the list and the diff side by side.

## Testing

```bash
composer test
```

The suite drives real git against throwaway repositories, with a faked terminal for the prompts.

## ผู้พัฒนา

พัฒนาและดูแลโดย [บริษัท ภัทรชัย อาร์ทิซาน จำกัด](https://phattarachai.dev) (Phattarachai Artisan)
บริษัทที่ปรึกษาและพัฒนาเว็บ ที่เรียนรู้และแบ่งปันกับชุมชน Laravel
แพ็กเกจนี้แบ่งปันให้ชุมชนนำไปใช้และต่อยอดได้อย่างอิสระ

ดูแพ็กเกจอื่นของเราได้ที่ [phattarachai.dev/open-source](https://phattarachai.dev/open-source)
และติดต่อเราได้ที่ [phattarachai.dev](https://phattarachai.dev/#contact)
