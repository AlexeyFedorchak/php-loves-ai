<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\HomeDirectoryNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\Path;

/**
 * Reads text aloud into an audio file with transformers models pulled into the project, through the runner installed
 * by `vendor/bin/loves-ai setup text-to-speech`.
 *
 *     $file = (new TextToSpeech())->speak('facebook/mms-tts-eng', 'Hello from PHP!', __DIR__ . '/hello.wav');
 *
 * Works with Hugging Face "text-to-speech" models that need no extra input, e.g. VITS and MMS
 * (facebook/mms-tts-eng, kakao-enterprise/vits-ljs) or Bark (suno/bark-small). The output file's extension picks the
 * format: .wav, .mp3, .m4a, .flac or .ogg.
 */
final class TextToSpeech extends BinaryRunner
{
    /** A model the runner can load; used in error messages and hints. */
    public const EXAMPLE_MODEL = 'facebook/mms-tts-eng';

    /** Hugging Face models this runner can use. */
    public const COMPATIBLE_MODELS_URL = 'https://huggingface.co/models?pipeline_tag=text-to-speech&library=transformers';

    /** Model types that listen instead of speaking; a common mix-up given the similar names. */
    private const SPEECH_RECOGNITION_TYPES = ['whisper', 'wav2vec2', 'wav2vec2-bert', 'hubert', 'moonshine'];

    public static function tool(): Tool
    {
        return Tool::TextToSpeech;
    }

    /**
     * @param string                        $model      Hugging Face model id, e.g. "facebook/mms-tts-eng"
     * @param string                        $text       text to read aloud
     * @param string                        $outputPath audio file to write, e.g. "hello.wav"; its extension picks the
     *                                                  format. A leading "~" is expanded
     * @param string|null                   $voice      voice of models that have several, e.g. a Bark preset like
     *                                                  "v2/en_speaker_6", or a speaker number
     * @param float|null                    $speed      speaking rate of VITS-style models, e.g. 0.8 slower, 1.2 faster;
     *                                                  null uses the model's own
     * @param int|null                      $seed       random seed, for reproducible audio
     * @param string|null                   $device     torch device such as "cpu", "cuda" or "mps"; null picks the best
     * @param (callable(string): void)|null $onOutput   receives the runner's progress output as it arrives
     *
     * @return string absolute path of the written audio file
     *
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException when the model cannot speak
     * @throws HomeDirectoryNotFoundException
     * @throws RunFailedException e.g. when the file extension is not a supported audio format
     */
    public function speak(
        string $model,
        string $text,
        string $outputPath,
        ?string $voice = null,
        ?float $speed = null,
        ?int $seed = null,
        ?string $device = null,
        ?callable $onOutput = null,
    ): string {
        $result = $this->run($model, [
            '--text' => $text,
            '--output' => Path::expandHome($outputPath),
            '--voice' => $voice,
            '--speed' => $speed,
            '--seed' => $seed,
            '--device' => $device,
        ], $onOutput);

        if (!is_string($result['output'] ?? null)) {
            throw new RunFailedException(0, 'The runner did not report the path of the written audio file.');
        }

        return $result['output'];
    }

    /**
     * The runner speaks with transformers models that need nothing but text.
     */
    protected function ensureModelIsSupported(string $model, string $modelPath): void
    {
        $problem = TransformersModel::problem($modelPath) ?? self::speechProblem($modelPath);

        if ($problem === null) {
            return;
        }

        throw new UnsupportedModelException($model, $modelPath, sprintf(
            '%s cannot read text aloud: %s Use a transformers text-to-speech model instead, e.g. %s (browse: %s).',
            $model,
            $problem,
            self::EXAMPLE_MODEL,
            self::COMPATIBLE_MODELS_URL,
        ));
    }

    private static function speechProblem(string $modelPath): ?string
    {
        $modelType = TransformersModel::config($modelPath)['model_type'] ?? null;

        return match (true) {
            $modelType === 'speecht5' => 'it needs a speaker embedding file to choose a voice, which this runner cannot provide.',
            is_string($modelType) && in_array($modelType, self::SPEECH_RECOGNITION_TYPES, true) => 'it transcribes speech instead of speaking; use it with speech-to-text.',
            default => null,
        };
    }
}
