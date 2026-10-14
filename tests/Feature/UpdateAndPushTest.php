<?php

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Phattarachai\GitPrompt\Actions\Push;
use Phattarachai\GitPrompt\Actions\Update;

it('fast-forwards a branch that is only behind, keeping local edits', function () {
    $repo = repo();
    $mate = $repo->teammate();
    $mate->commit('b.txt', "b\n", 'Teammate');
    $mate->git('push', '--quiet');
    $repo->write('README.md', "edited\n");

    answer([]);
    $updated = (new Update($repo->git, $repo->console))();

    expect($updated)->toBeTrue()
        ->and($repo->read('b.txt'))->toBe("b\n")
        ->and($repo->read('README.md'))->toBe("edited\n");
});

it('rebases a diverged branch when asked', function () {
    $repo = repo();
    $mate = $repo->teammate();
    $mate->commit('b.txt', "b\n", 'Teammate');
    $mate->git('push', '--quiet');
    $repo->commit('c.txt', "c\n", 'Mine');

    answer([Key::ENTER]);
    $updated = (new Update($repo->git, $repo->console))();

    expect($updated)->toBeTrue()
        ->and($repo->git('log', '--format=%s', '-3'))->toBe("Mine\nTeammate\nInitial commit");
});

it('merges instead of rebasing when the local commits include a merge', function () {
    $repo = repo();
    $repo->git('config', 'gitprompt.update', 'rebase');
    $mate = $repo->teammate();
    $mate->commit('b.txt', "b\n", 'Teammate');
    $mate->git('push', '--quiet');
    $repo->git('switch', '--quiet', '-c', 'hotfix');
    $repo->commit('README.md', "hotfix\n", 'Hotfix');
    $repo->git('switch', '--quiet', 'main');
    $repo->git('merge', '--quiet', '--no-ff', '--no-edit', 'hotfix');

    answer([]);

    expect((new Update($repo->git, $repo->console))())->toBeTrue()
        ->and($repo->git('rev-list', '--merges', '--count', 'HEAD'))->toBe('2')
        ->and($repo->read('b.txt'))->toBe("b\n");
});

it('pushes a new branch and sets its upstream', function () {
    $repo = repo();
    $repo->git('switch', '--quiet', '-c', 'feat/new');
    $repo->commit('n.txt', "n\n", 'New');

    answer([]);
    $pushed = (new Push($repo->git, $repo->console))();

    expect($pushed)->toBeTrue()
        ->and($repo->git('rev-parse', '--abbrev-ref', '@{u}'))->toBe('origin/feat/new');
});

it('pushes to a protected branch without asking for its name', function () {
    $repo = repo();
    $repo->commit('c.txt', "c\n", 'Mine');

    answer([]);

    expect((new Push($repo->git, $repo->console))())->toBeTrue()
        ->and($repo->git('rev-list', '--count', '@{u}..HEAD'))->toBe('0');
});

it('offers to update first when the push is rejected', function () {
    $repo = repo();
    $repo->git('config', 'gitprompt.protected', 'none');
    $mate = $repo->teammate();
    $mate->commit('b.txt', "b\n", 'Teammate');
    $mate->git('push', '--quiet');
    $repo->commit('c.txt', "c\n", 'Mine');

    // Push → rejected → "Update first" → diverged → "Rebase" → push again.
    answer([Key::ENTER, Key::ENTER]);
    $pushed = (new Push($repo->git, $repo->console))();

    expect($pushed)->toBeTrue()
        ->and($mate->git('ls-remote', 'origin', 'main'))->toStartWith($repo->git('rev-parse', 'HEAD'));
    Prompt::assertOutputContains('git push');
});
