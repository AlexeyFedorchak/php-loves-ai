<?php

declare(strict_types=1);

// Models are always loaded from <project root>/.local/models/<model id>, and the runner from
// <project root>/.local/runners, as installed by `vendor/bin/pull` and `vendor/bin/setup text-to-speech`.

return [
    // Directory audio files are saved into when `text-to-speech --output` is not given.
    // A leading "~" is expanded to the user's home directory.
    'output_dir' => '~/tmp/hugging-face/audio',

    // File the runner's output is appended to, e.g. '~/tmp/hugging-face/text-to-speech.log'.
    // null discards the output. A leading "~" is expanded to the user's home directory.
    'log_file' => null,
];
