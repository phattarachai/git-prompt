<?php

use Laravel\Prompts\Key;
use Phattarachai\GitPrompt\Actions\Cleanup;

it('deletes every merged branch locally and on the remote in one go', function () {
    $repo = repo();
    $repo->git('remote', 'set-head', 'origin', 'main');

    foreach (['feat/done-a', 'feat/done-b'] as $name) {
        $repo->git('branch', $name);
        $repo->git('push', '--quiet', '-u', 'origin', $name);
    }

    $repo->git('switch', '--quiet', '-c', 'feat/wip');
    $repo->commit('wip.txt', "wip\n", 'WIP');
    $repo->git('push', '--quiet', '-u', 'origin', 'feat/wip');
    $repo->git('switch', '--quiet', 'main');

    // Type "merged", tick all matches, Enter → "Local and remote" → confirm.
    answer(['merged', Key::CTRL_A, Key::ENTER, Key::ENTER, 'y', Key::ENTER]);

    expect((new Cleanup($repo->git, $repo->console))())->toBeTrue()
        ->and($repo->git('branch', '--format=%(refname:short)'))->toBe("feat/wip\nmain")
        ->and($repo->git('ls-remote', '--heads', 'origin'))->not->toContain('done-')
        ->and($repo->git('ls-remote', '--heads', 'origin'))->toContain('feat/wip');
});

it('never offers protected or current branches', function () {
    $repo = repo();
    $repo->git('branch', 'develop');
    $repo->git('branch', 'feat/old');

    answer([Key::CTRL_A, Key::ENTER, 'y', Key::ENTER]);
    (new Cleanup($repo->git, $repo->console))();

    expect($repo->git('branch', '--format=%(refname:short)'))->toBe("develop\nmain");
});
