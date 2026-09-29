<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\HomeDirectoryNotFoundException;
use PhpLovesAi\Exception\MediaNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\Path;

/**
 * Answers questions by choosing one of the given options, with JEV decision classifiers pulled into the project,
 * through the runner installed by `vendor/bin/loves-ai setup jev`.
 *
 *     $decision = (new Jev())->decide('akhilaaa3/Jev-Omni', 'Has the meeting started?', ['Yes', 'No'],
 *         state: 'The meeting starts at 10 AM. It is now 9 AM.');
 *     $decision->prediction; // 'No'
 *
 * The question can be about text (the state), an image, an audio file or a video. The model does not write an
 * answer: it gives each option a probability, so the result is always one of the options.
 */
final class Jev extends BinaryRunner
{
    /** A model the runner can load; used in error messages and hints. */
    public const EXAMPLE_MODEL = 'akhilaaa3/Jev-Omni';

    /** Hugging Face models this runner can use. */
    public const COMPATIBLE_MODELS_URL = 'https://huggingface.co/models?search=jev';

    /** What media can be; without a modality, the runner tells it by the file's extension. */
    public const MODALITIES = ['image', 'audio', 'video'];

    /** The most options a question can have; models may accept fewer, and answer best with up to 20. */
    public const MAX_OPTIONS = 256;

    public static function tool(): Tool
    {
        return Tool::Jev;
    }

    /**
     * @param string                        $model     Hugging Face model id, e.g. "akhilaaa3/Jev-Omni"
     * @param string                        $question  the question, e.g. "Has the meeting started?"
     * @param list<string>                  $options   the answers to choose from, at least two and all different,
     *                                                 e.g. ['Yes', 'No']
     * @param string|null                   $state     what the question is about, as text, e.g. "It is now 9 AM."
     * @param string|null                   $mediaPath image, audio or video file the question is about; a leading "~"
     *                                                 is expanded
     * @param string|null                   $modality  what the media is: "image", "audio" or "video"; null tells it by
     *                                                 the file's extension
     * @param int|null                      $frames    frames sampled from a video; null uses 16
     * @param string|null                   $device    torch device such as "cpu", "cuda" or "mps";
     *                                                 null picks the best available
     * @param (callable(string): void)|null $onOutput  receives the runner's progress output as it arrives
     *
     * @throws \InvalidArgumentException when there are fewer than two options, duplicates, or an unknown modality
     * @throws MediaNotFoundException
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException when the model has no decision head
     * @throws HomeDirectoryNotFoundException
     * @throws RunFailedException e.g. when the media cannot be decoded
     */
    public function decide(
        string $model,
        string $question,
        array $options,
        ?string $state = null,
        ?string $mediaPath = null,
        ?string $modality = null,
        ?int $frames = null,
        ?string $device = null,
        ?callable $onOutput = null,
    ): Decision {
        $this->ensureCanDecide($model, $options, $modality);

        if ($mediaPath !== null) {
            $mediaPath = Path::expandHome($mediaPath);
            if (!is_file($mediaPath) || !is_readable($mediaPath)) {
                throw new MediaNotFoundException($mediaPath);
            }
        }

        $result = $this->run($model, [
            '--question' => $question,
            '--options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            '--state' => $state,
            '--media' => $mediaPath,
            '--modality' => $modality,
            '--frames' => $frames,
            '--device' => $device,
        ], $onOutput);

        $index = $result['index'] ?? null;
        $probabilities = $result['probabilities'] ?? null;
        if (!is_int($index) || !isset($options[$index]) || !is_array($probabilities) || count($probabilities) !== count($options)) {
            throw new RunFailedException(0, 'The runner did not report a decision.');
        }

        $byOption = [];
        foreach (array_values($probabilities) as $i => $probability) {
            $byOption[$options[$i]] = is_numeric($probability) ? (float) $probability : 0.0;
        }

        return new Decision($options[$index], $index, $byOption[$options[$index]], $byOption);
    }

    /**
     * Runs the checks decide() performs before starting the binary, including the options and modality, without
     * deciding anything.
     *
     * @param list<string> $options
     *
     * @throws \InvalidArgumentException
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException
     */
    public function ensureCanDecide(string $model, array $options, ?string $modality = null): void
    {
        self::validate($options, $modality);
        $this->ensureCanRun($model);
    }

    /**
     * A JEV model is a transformers model plus its decision head, which the runner rebuilds from the weights alone.
     */
    protected function ensureModelIsSupported(string $model, string $modelPath): void
    {
        $problem = TransformersModel::problem($modelPath);

        if ($problem === null
            && (!TransformersModel::has($modelPath, 'head.pt') || !TransformersModel::has($modelPath, 'decision_config.json'))
        ) {
            $problem = 'it has no decision head (head.pt and decision_config.json), so it cannot choose among options. '
                . 'If it is a chat model, use it with text-to-text or image-to-text.';
        }

        if ($problem === null) {
            return;
        }

        throw new UnsupportedModelException($model, $modelPath, sprintf(
            '%s cannot answer questions with options: %s Use a JEV decision classifier instead, e.g. %s (browse: %s).',
            $model,
            $problem,
            self::EXAMPLE_MODEL,
            self::COMPATIBLE_MODELS_URL,
        ));
    }

    /**
     * @param list<string> $options
     *
     * @throws \InvalidArgumentException
     */
    private static function validate(array $options, ?string $modality): void
    {
        if (count($options) < 2) {
            throw new \InvalidArgumentException('At least two options are needed to choose from.');
        }

        if (count($options) > self::MAX_OPTIONS) {
            throw new \InvalidArgumentException(sprintf('At most %d options can be given, %d were.', self::MAX_OPTIONS, count($options)));
        }

        if (count(array_unique($options)) !== count($options)) {
            throw new \InvalidArgumentException('Every option must be different.');
        }

        if ($modality !== null && !in_array($modality, self::MODALITIES, true)) {
            throw new \InvalidArgumentException(sprintf("Unknown modality '%s'; use one of: %s.", $modality, implode(', ', self::MODALITIES)));
        }
    }
}
