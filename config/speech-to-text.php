<?php

declare(strict_types=1);

// Models are always loaded from <project root>/.local/models/<model id>, and the runner from
// <project root>/.local/runners, as installed by `vendor/bin/pull` and `vendor/bin/setup speech-to-text`.

return [
    // File the runner's output is appended to, e.g. '~/tmp/hugging-face/speech-to-text.log'.
    // null discards the output. A leading "~" is expanded to the user's home directory.
    'log_file' => null,
];
