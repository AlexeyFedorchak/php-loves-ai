<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

use PhpLovesAi\Binary\BinaryStore;
use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotFoundException;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\HomeDirectoryNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;

/**
 * Runs one task (text-to-image, …) with locally pulled models through that task's compiled runner binary.
 *
 * Each implementation adds its own task method, e.g. TextToImage::generate(), since inputs and results differ per task.
 */
interface Runner
{
    /** The binary this runner invokes, as installed by `vendor/bin/setup`. */
    public static function tool(): Tool;

    /**
     * A runner using the binary installed by `vendor/bin/setup`.
     *
     * @param string           $modelsDir directory models were pulled into; a leading "~" is expanded
     * @param float|null       $timeout   seconds before a run is aborted; null waits indefinitely
     * @param BinaryStore|null $store     defaults to the standard install location
     *
     * @throws BinaryNotInstalledException
     * @throws HomeDirectoryNotFoundException
     */
    public static function installed(string $modelsDir, ?float $timeout = null, ?BinaryStore $store = null): static;

    /** Local directory a model is loaded from: <modelsDir>/<model id>. */
    public function modelPath(string $model): string;

    /**
     * Runs the checks performed before starting the binary, without running anything.
     *
     * @throws ModelNotFoundException
     * @throws BinaryNotFoundException
     */
    public function ensureCanRun(string $model): void;
}
