<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\UnsupportedModelException;

/**
 * Runs one task (text-to-image, …) through that task's runner binary installed by `vendor/bin/loves-ai setup`, with models
 * pulled by `vendor/bin/loves-ai pull`. Both are found in the project's .local directory (see LocalStorage).
 *
 * Each implementation adds its own task method, e.g. TextToImage::generate(), since inputs and results differ per task.
 */
interface Runner
{
    /** The binary this runner invokes, as installed by `vendor/bin/loves-ai setup`. */
    public static function tool(): Tool;

    /** Local directory a model is loaded from: <project root>/.local/models/<model id>. */
    public function modelPath(string $model): string;

    /**
     * Runs the checks performed before starting the binary, without running anything.
     *
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException when the model was pulled but this runner cannot use it
     */
    public function ensureCanRun(string $model): void;
}
