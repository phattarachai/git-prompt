<?php

it('lists local branches with tracking, then remote-only branches', function () {
    $repo = repo();
    $mate = $repo->teammate();
    $mate->git('switch', '--quiet', '-c', 'feat/shared');
    $mate->commit('a.txt', "a\n", 'Shared work');
    $mate->git('push', '--quiet', '-u', 'origin', 'feat/shared');
    $mate->git('switch', '--quiet', 'main');
    $mate->commit('b.txt', "b\n", 'Teammate on main');
    $mate->git('push', '--quiet');

    $repo->commit('c.txt', "c\n", 'Mine on main');
    $repo->git('fetch', '--quiet');

    $branches = $repo->git->branches();

    expect($branches)->toHaveCount(2)
        ->and($branches[0]->name)->toBe('main')
        ->and($branches[0]->isCurrent)->toBeTrue()
        ->and($branches[0]->upstream)->toBe('origin/main')
        ->and([$branches[0]->ahead, $branches[0]->behind])->toBe([1, 1])
        ->and($branches[1]->ref())->toBe('origin/feat/shared')
        ->and($branches[1]->isRemote())->toBeTrue();
});

it('reports a branch checked out in another worktree', function () {
    $repo = repo();
    $repo->git('worktree', 'add', '--quiet', '-b', 'feat/elsewhere', dirname($repo->path).'/elsewhere');

    $branch = $repo->git->findBranch('feat/elsewhere');

    expect($branch->isInOtherWorktree($repo->path))->toBeTrue()
        ->and($repo->git->findBranch('main')->isInOtherWorktree($repo->path))->toBeFalse();
});

it('parses status including renames and untracked files', function () {
    $repo = repo();
    $repo->git('mv', 'README.md', 'docs.md');
    $repo->write('new file.txt', "x\n");

    $files = $repo->git->status();

    expect(array_map(fn ($file) => [$file->label(), $file->display(), $file->isStaged()], $files))->toBe([
        ['renamed', 'README.md → docs.md', true],
        ['new', 'new file.txt', false],
    ]);
});

it('reads protected branches from git config', function () {
    $repo = repo();

    expect($repo->git->protectedBranches())->toBe(['main', 'master', 'develop']);

    $repo->git('config', 'gitprompt.protected', 'main uat');

    expect($repo->git->protectedBranches())->toBe(['main', 'uat'])
        ->and($repo->git->isProtected('develop'))->toBeFalse();
});

it('lets the repository value replace the global one instead of adding to it', function () {
    $repo = repo();
    $global = dirname($repo->path).'/global.gitconfig';
    file_put_contents($global, "[gitprompt]\n\tprotected = develop uat main\n");
    $repo->git('config', 'gitprompt.protected', 'main main release');

    putenv("GIT_CONFIG_GLOBAL={$global}");
    $_SERVER['GIT_CONFIG_GLOBAL'] = $global;

    try {
        expect($repo->git->protectedBranches())->toBe(['main', 'release']);
    } finally {
        putenv('GIT_CONFIG_GLOBAL=/dev/null');
        $_SERVER['GIT_CONFIG_GLOBAL'] = '/dev/null';
    }
});
