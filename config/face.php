<?php

declare(strict_types=1);

// Models are always loaded from <project root>/.local/models/<model id>, and the runner from
// <project root>/.local/runners, as installed by `vendor/bin/loves-ai pull` and `vendor/bin/loves-ai setup face`.

return [
    // Bits in a face id: 64, 128, 256 or 512. It belongs here rather than in each call because
    // changing it invalidates every id already stored — ids of different widths cannot be compared.
    // 256 bits is 64 hex characters, plus the "v1:256:" prefix.
    'hash_bits' => 256,

    // Cosine similarity at which `face-match` calls two faces the same person, from -1 to 1.
    // 0.40 is a starting point, not a safe default: measure it on your own images, since what a
    // wrong match costs is yours to decide. Raise it to accept fewer matches, lower it for more.
    'threshold' => 0.40,

    // Detector confidence below which a box is not reported as a face, from 0 to 1.
    'min_score' => 0.5,

    // Size images are resized to before detection, in pixels. Larger finds smaller and more distant
    // faces and is slower; 640 is what the InsightFace packs were tuned for.
    'detection_size' => 640,

    // File the runner's output is appended to, e.g. '~/tmp/hugging-face/face.log'.
    // null discards the output. A leading "~" is expanded to the user's home directory.
    'log_file' => null,
];
