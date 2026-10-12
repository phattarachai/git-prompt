<?php

use Laravel\Prompts\Key;
use Phattarachai\GitPrompt\Actions\Checkout;

it('checks out a remote-only branch as a local tracking branch', function () {
    $repo = repo();
    $mate = $repo->teammate();
    $mate->git('switch', '--quiet', '-c', 'feat/remote');
    $mate->commit('a.txt', "a\n", 'Remote work');
    $mate->git('push', '--quiet', '-u', 'origin', 'feat/remote');
    $repo->git('fetch', '--quiet');

    answer([]);
    $switched = (new Checkout($repo->git, $repo->console))($repo->git->findBranch('origin/feat/remote'));

    expect($switched)->toBeTrue()
        ->and($repo->current())->toBe('feat/remote')
        ->and($repo->git('rev-parse', '--abbrev-ref', '@{u}'))->toBe('origin/feat/remote');
});

it('smart-checks-out when local changes clash, carrying the changes over', function () {
    $repo = repo();
    $repo->git('switch', '--quiet', '-c', 'feat/other');
    $repo->commit('notes.txt', "other\n", 'Other notes');
    $repo->git('switch', '--quiet', 'main');
    $repo->write('notes.txt', "mine\n");
    $repo->git('add', 'notes.txt');

    answer([Key::ENTER]);
    $switched = (new Checkout($repo->git, $repo->console))($repo->git->findBranch('feat/other'));

    expect($switched)->toBeTrue()
        ->and($repo->current())->toBe('feat/other')
        ->and($repo->git->conflictedFiles())->toBe(['notes.txt'])
        ->and($repo->git('stash', 'list'))->not->toBe('');
});

it('refuses a branch checked out in another worktree', function () {
    $repo = repo();
    $repo->git('worktree', 'add', '--quiet', '-b', 'feat/elsewhere', dirname($repo->path).'/elsewhere');

    answer([]);
    $switched = (new Checkout($repo->git, $repo->console))($repo->git->findBranch('feat/elsewhere'));

    expect($switched)->toBeFalse()->and($repo->current())->toBe('main');
});
