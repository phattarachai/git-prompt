<?php

use Laravel\Prompts\Key;
use Phattarachai\GitPrompt\Actions\BranchMenu;
use Phattarachai\GitPrompt\Actions\Commit;
use Phattarachai\GitPrompt\Actions\Conflicts;
use Phattarachai\GitPrompt\Actions\Integrate;
use Phattarachai\GitPrompt\Tests\TestRepo;

function conflictingBranches(): TestRepo
{
    $repo = repo();
    $repo->git('config', 'gitprompt.protected', 'none');
    $repo->git('switch', '--quiet', '-c', 'feat/a');
    $repo->commit('README.md', "from a\n", 'A');
    $repo->git('switch', '--quiet', 'main');
    $repo->commit('README.md', "from main\n", 'Main');

    return $repo;
}

it('aborts a conflicting merge and puts everything back', function () {
    $repo = conflictingBranches();

    answer([Key::DOWN, Key::ENTER]);
    $merged = (new Integrate($repo->git, $repo->console))->merge($repo->git->findBranch('feat/a'));

    expect($merged)->toBeFalse()
        ->and($repo->git->operationInProgress())->toBeNull()
        ->and($repo->read('README.md'))->toBe("from main\n");
});

it('continues a merge only after the conflict markers are gone', function () {
    $repo = conflictingBranches();

    answer([Key::ENTER]);
    (new Integrate($repo->git, $repo->console))->merge($repo->git->findBranch('feat/a'));
    expect($repo->git->operationInProgress())->toBe('merge');

    answer([]);
    expect((new Conflicts($repo->git, $repo->console))->continue())->toBeFalse();

    $repo->write('README.md', "both\n");
    answer([]);

    expect((new Conflicts($repo->git, $repo->console))->continue())->toBeTrue()
        ->and($repo->git->operationInProgress())->toBeNull()
        ->and($repo->git('log', '-1', '--format=%P'))->toContain(' ');
});

it('merges into a protected branch without asking for its name', function () {
    $repo = repo();
    $repo->git('switch', '--quiet', '-c', 'feat/a');
    $repo->commit('a.txt', "a\n", 'A');
    $repo->git('switch', '--quiet', 'main');

    answer([]);

    expect((new Integrate($repo->git, $repo->console))->merge($repo->git->findBranch('feat/a')))->toBeTrue()
        ->and($repo->read('a.txt'))->toBe("a\n");
});

it('offers a merge instead of rebasing a branch that has merge commits', function () {
    $repo = repo();
    $repo->git('switch', '--quiet', '-c', 'feat/a');
    $repo->git('switch', '--quiet', '-c', 'feat/b');
    $repo->commit('b.txt', "b\n", 'B');
    $repo->git('switch', '--quiet', 'feat/a');
    $repo->git('merge', '--quiet', '--no-ff', '--no-edit', 'feat/b');
    $repo->git('switch', '--quiet', 'main');
    $repo->commit('m.txt', "m\n", 'Main');
    $repo->git('switch', '--quiet', 'feat/a');

    answer([Key::ENTER]);

    expect((new Integrate($repo->git, $repo->console))->rebaseOnto($repo->git->findBranch('main')))->toBeTrue()
        ->and($repo->git('rev-list', '--merges', '--count', 'HEAD'))->toBe('2')
        ->and($repo->read('m.txt'))->toBe("m\n");
});

it('commits only the selected files', function () {
    $repo = repo();
    $repo->write('README.md', "changed\n");
    $repo->write('keep.txt', "not yet\n");

    // Both are pre-selected; move to the second file and untick it.
    answer([Key::DOWN, Key::SPACE, Key::ENTER, 'Update readme', Key::ENTER, Key::ENTER]);
    expect((new Commit($repo->git, $repo->console))())->toBeTrue()
        ->and($repo->git('log', '-1', '--format=%s'))->toBe('Update readme')
        ->and($repo->git('status', '--porcelain'))->toBe('?? keep.txt');
});

it('asks before force-deleting an unmerged branch', function () {
    $repo = repo();
    $repo->git('switch', '--quiet', '-c', 'feat/unmerged');
    $repo->commit('u.txt', "u\n", 'Unmerged');
    $repo->git('switch', '--quiet', 'main');
    $branch = $repo->git->findBranch('feat/unmerged');

    // Menu: type "del" for Delete…, confirm yes, then decline the force delete.
    answer(['del', Key::ENTER, Key::ENTER, Key::ENTER]);
    expect((new BranchMenu($repo->git, $repo->console))($branch))->toBeFalse()
        ->and($repo->git->findBranch('feat/unmerged'))->not->toBeNull();
});
