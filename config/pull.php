<?php

declare(strict_types=1);

use PhpLovesAi\Binary\Platform;

return [
    // Branch, tag or commit hash pulled when `pull --revision` is not given.
    'revision' => 'main',

    // Directory models are saved into, as <models_dir>/<model id>.
    // A leading "~" is expanded to the user's home directory; a relative path is
    // resolved against the current working directory.
    'models_dir' => '~/tmp/hugging-face/models',

    // File the puller's output is appended to, e.g. '~/tmp/hugging-face/pull.log'.
    // null discards the output. A leading "~" is expanded to the user's home directory.
    'log_file' => null,

    // Compiled puller binary for the current platform.
    'binary' => __DIR__ . '/../python/puller/dist/' . Platform::binaryName('puller'),
];
