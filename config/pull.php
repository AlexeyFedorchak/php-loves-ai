<?php

declare(strict_types=1);

// Models are always saved into <project root>/.local/models/<model id>, where the runners find them.

return [
    // Branch, tag or commit hash pulled when `pull --revision` is not given.
    'revision' => 'main',

    // File the puller's output is appended to, e.g. '~/tmp/hugging-face/pull.log'.
    // null discards the output. A leading "~" is expanded to the user's home directory.
    'log_file' => null,
];
