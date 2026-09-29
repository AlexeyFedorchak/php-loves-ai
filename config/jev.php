<?php

declare(strict_types=1);

// Models are always loaded from <project root>/.local/models/<model id>, and the runner from
// <project root>/.local/runners, as installed by `vendor/bin/loves-ai pull` and `vendor/bin/loves-ai setup jev`.

return [
    // File the runner's output is appended to, e.g. '~/tmp/hugging-face/jev.log'.
    // null discards the output. A leading "~" is expanded to the user's home directory.
    'log_file' => null,
];
