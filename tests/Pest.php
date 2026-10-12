<?php

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Phattarachai\GitPrompt\Editor;
use Phattarachai\GitPrompt\Tests\TestRepo;

foreach (['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'] as $name => $value) {
    putenv("{$name}={$value}");
    $_SERVER[$name] = $_ENV[$name] = $value;
}

Editor::$interactive = false;

uses()->afterEach(fn () => TestRepo::cleanUp())->in(__DIR__);

/**
 * A clone of a fresh bare "origin" holding `main` with one commit.
 */
function repo(): TestRepo
{
    return TestRepo::create();
}

/**
 * Fake the terminal; each string is typed character by character, Key constants are pressed as-is.
 *
 * @param  list<string>  $inputs
 */
function answer(array $inputs): void
{
    $keys = [];

    foreach ($inputs as $input) {
        array_push($keys, ...(str_starts_with($input, "\e") || in_array($input, [Key::ENTER, Key::SPACE, Key::BACKSPACE], true) ? [$input] : mb_str_split($input)));
    }

    Prompt::fake($keys);
}
