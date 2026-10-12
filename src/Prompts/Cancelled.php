<?php

namespace Phattarachai\GitPrompt\Prompts;

use RuntimeException;

/**
 * Esc was pressed: leave the current screen.
 */
final class Cancelled extends RuntimeException {}
