<?php

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Phattarachai\GitPrompt\Actions\Palette;
use Phattarachai\GitPrompt\BranchOrder;
use Phattarachai\GitPrompt\Prompts\Escape;

it('checks out a branch by typing part of its name and pressing Enter twice', function () {
    $repo = repo();
    $repo->git('branch', 'hotfix/cart-api-log-tab');
    $repo->git('branch', 'feat/other');

    answer(['cal', Key::ENTER, Key::ENTER]);
    $done = (new Palette($repo->git, $repo->console))();

    expect($done)->toBeTrue()->and($repo->current())->toBe('hotfix/cart-api-log-tab');
    Prompt::assertOutputContains('git switch hotfix/cart-api-log-tab');
});

it('offers continue and abort first while a merge is in progress', function () {
    $repo = repo();
    $repo->git('switch', '--quiet', '-c', 'feat/a');
    $repo->commit('README.md', "a\n", 'A');
    $repo->git('switch', '--quiet', 'main');
    $repo->commit('README.md', "main\n", 'Main');
    $repo->console->git->run(['merge', 'feat/a']);

    answer([Key::DOWN, Key::ENTER]);

    expect((new Palette($repo->git, $repo->console))())->toBeTrue()
        ->and($repo->git->operationInProgress())->toBeNull();
    Prompt::assertOutputContains('Abort merge');
});

it('goes back to the palette when Esc is pressed in a submenu, and quits on Esc there', function () {
    $repo = repo();
    $repo->git('branch', 'feat/other');

    answer(['feat', Key::ENTER, Key::ESCAPE, Key::ESCAPE]);
    Escape::install();

    expect((new Palette($repo->git, $repo->console))())->toBeTrue()
        ->and($repo->current())->toBe('main');
});

it('pins protected branches on top in their configured order', function () {
    $repo = repo();
    $repo->git('branch', 'develop');
    $repo->git('branch', 'uat');
    $repo->commit('x.txt', "x\n", 'Newest');
    $repo->git('branch', 'feat/newest');
    $repo->git('config', 'gitprompt.protected', 'develop uat main');

    $names = fn (string $sort) => array_map(
        fn ($branch) => $branch->ref(),
        BranchOrder::sort($repo->git->branches(), $repo->git->protectedBranches(), $sort),
    );

    expect($names('recent'))->toBe(['develop', 'uat', 'main', 'feat/newest']);
});

it('opens on the Branches tab and switches to Actions with Tab', function () {
    $repo = repo();
    $repo->git('branch', 'develop');

    answer(['de', Key::ENTER, Key::ENTER]);
    expect((new Palette($repo->git, $repo->console))())->toBeTrue()
        ->and($repo->current())->toBe('develop');

    answer([Key::TAB, 'stat', Key::ENTER]);
    expect((new Palette($repo->git, $repo->console))())->toBeTrue();
    Prompt::assertOutputContains('working tree clean');
});
