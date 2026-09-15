<?php

declare(strict_types=1);

// Models are always loaded from <project root>/.local/models/<model id>, and the runner from
// <project root>/.local/runners, as installed by `vendor/bin/pull` and `vendor/bin/setup text-to-image`.

return [
    // Directory generated images are saved into when `text-to-image --output` is not given.
    // A leading "~" is expanded to the user's home directory.
    'output_dir' => '~/tmp/hugging-face/images',

    // File the runner's output is appended to, e.g. '~/tmp/hugging-face/text-to-image.log'.
    // null discards the output.
    'log_file' => null,
];
