<?php

declare(strict_types=1);

// Models are always loaded from <project root>/.local/models/<model id>, and the runner from
// <project root>/.local/runners, as installed by `vendor/bin/pull` and `vendor/bin/setup image-to-video`.

return [
    // Directory videos are saved into when `image-to-video --output` is not given.
    // A leading "~" is expanded to the user's home directory.
    'output_dir' => '~/tmp/hugging-face/videos',

    // File the runner's output is appended to, e.g. '~/tmp/hugging-face/image-to-video.log'.
    // null discards the output. A leading "~" is expanded to the user's home directory.
    'log_file' => null,
];
