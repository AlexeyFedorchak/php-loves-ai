<?php

declare(strict_types=1);

return [
    // Directory models were pulled into; a model is loaded from <models_dir>/<model id>.
    // A leading "~" is expanded to the user's home directory.
    'models_dir' => '~/tmp/hugging-face/models',

    // Directory generated images are saved into when `text-to-image --output` is not given.
    'output_dir' => '~/tmp/hugging-face/images',

    // File the runner's output is appended to, e.g. '~/tmp/hugging-face/text-to-image.log'.
    // null discards the output.
    'log_file' => null,

    // Path to a text-to-image runner binary. null uses the one downloaded by `vendor/bin/setup text-to-image`.
    'binary' => null,
];
