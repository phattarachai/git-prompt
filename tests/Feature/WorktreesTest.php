<?php

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Phattarachai\GitPrompt\Actions\FileChanges;
use Phattarachai\GitPrompt\Actions\Status;
use Phattarachai\GitPrompt\Actions\WorktreeMenu;
use Phattarachai\GitPrompt\Console;
use Phattarachai\GitPrompt\Git;
use Phattarachai\GitPrompt\Prompts\Escape;
use Phattarachai\GitPrompt\Worktrees;

/**
 * main checkout + two worktrees: "busy" edits README.md (uncommitted),
 * "done" committed a change to README.md and app.txt.
 */
function agentWorktrees(): array
{
    $repo = repo();
    $repo->git('remote', 'set-head', 'origin', 'main');
    $root = dirname($repo->path);

    $repo->git('worktree', 'add', '--quiet', '-b', 'fix/busy', "{$root}/busy", 'origin/main');
    file_put_contents("{$root}/busy/README.md", "busy edit\n");
    file_put_contents("{$root}/busy/new.txt", "new\n");

    $repo->git('worktree', 'add', '--quiet', '-b', 'feat/done', "{$root}/done", 'origin/main');
    $done = new Git("{$root}/done");
    file_put_contents("{$root}/done/README.md", "done edit\n");
    file_put_contents("{$root}/done/app.txt", "app\n");
    $done->run(['add', '--all']);
    $done->run(['-c', 'user.name=T', '-c', 'user.email=t@example.com', 'commit', '--quiet', '-m', 'Done']);

    return [$repo, $root];
}

it('summarises every worktree, current first and clean ones last', function () {
    [$repo] = agentWorktrees();

    $worktrees = (new Worktrees($repo->git))->all();

    expect(array_map(fn ($worktree) => [$worktree->name, $worktree->branch, $worktree->summary()], $worktrees))->toBe([
        ['work', 'main', 'clean'],
        ['busy', 'fix/busy', '1 changed · 1 new'],
        ['done', 'feat/done', '↑1'],
    ]);
});

it('finds files touched by more than one worktree, committed or not', function () {
    [$repo] = agentWorktrees();

    expect(Worktrees::overlaps((new Worktrees($repo->git))->all()))->toBe([
        'README.md' => ['busy' => 'uncommitted', 'done' => 'committed'],
    ]);
});

it('shows committed changes since the base branch and flags overlaps in status', function () {
    [, $root] = agentWorktrees();
    $git = new Git("{$root}/done");

    answer([]);
    (new Status($git, new Console($git)))();

    Prompt::assertOutputContains('Committed since origin/main (1 commit(s))');
    Prompt::assertOutputContains('app.txt');
    Prompt::assertOutputContains('also in busy');
});

it('only offers to remove a clean worktree, and keeps a branch with unmerged commits', function () {
    [$repo, $root] = agentWorktrees();
    $worktrees = (new Worktrees($repo->git))->all();
    [, $busy, $done] = $worktrees;

    answer(['stat', Key::ENTER]);
    (new WorktreeMenu($repo->git, $repo->console))($busy, $worktrees);
    Prompt::assertOutputDoesntContain('Remove worktree');

    answer(['remove', Key::ENTER, 'y', Key::ENTER]);
    expect((new WorktreeMenu($repo->git, $repo->console))($done, $worktrees))->toBeTrue()
        ->and(is_dir("{$root}/done"))->toBeFalse()
        ->and($repo->git->findBranch('feat/done'))->not->toBeNull();
});

it('shows the diff of a picked file, and compares it with the copy in another worktree', function () {
    [$repo] = agentWorktrees();
    $worktrees = (new Worktrees($repo->git))->all();
    [, $busy] = $worktrees;
    $git = new Git($busy->path);

    // README.md → View diff → back to the list → README.md → Compare with done's copy → Esc, Esc.
    answer(['readme', Key::ENTER, Key::ENTER, 'readme', Key::ENTER, 'compare', Key::ENTER, Key::ESCAPE]);
    Escape::install();

    expect((new FileChanges($git, new Console($git)))($busy, $worktrees))->toBeTrue();
    Prompt::assertOutputContains('git diff HEAD -- README.md');
    Prompt::assertOutputContains('+busy edit');
    Prompt::assertOutputContains("Compare with done's copy (committed there)");
    Prompt::assertOutputContains('+busy edit');
    Prompt::assertOutputContains('-done edit');
});
