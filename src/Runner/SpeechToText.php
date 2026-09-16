<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\AudioNotFoundException;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\HomeDirectoryNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\Path;

/**
 * Transcribes speech in audio or video files with transformers models pulled into the project, through the runner
 * installed by `vendor/bin/loves-ai setup speech-to-text`.
 *
 *     $transcript = (new SpeechToText())->transcribe('openai/whisper-tiny', __DIR__ . '/interview.mp3');
 *
 * Works with sequence-to-sequence models (e.g. Whisper, which can be told the spoken language and translate into
 * English) and CTC models (e.g. Wav2Vec2), from the Hugging Face task "automatic-speech-recognition". Audio of any
 * length and common formats (WAV, MP3, M4A, FLAC, OGG, the audio track of videos) is decoded by the runner itself.
 */
final class SpeechToText extends BinaryRunner
{
    /** A model the runner can load; used in error messages and hints. */
    public const EXAMPLE_MODEL = 'openai/whisper-tiny';

    /** Hugging Face models this runner can use. */
    public const COMPATIBLE_MODELS_URL = 'https://huggingface.co/models?pipeline_tag=automatic-speech-recognition&library=transformers';

    public static function tool(): Tool
    {
        return Tool::SpeechToText;
    }

    /**
     * @param string                        $model     Hugging Face model id, e.g. "openai/whisper-tiny"
     * @param string                        $audioPath audio or video file, e.g. "interview.mp3"; a leading "~" is expanded
     * @param string|null                   $language  spoken language for multilingual models such as Whisper, e.g.
     *                                                 "en" or "french"; null detects it
     * @param bool                          $translate translate the speech into English (Whisper-style models)
     * @param string|null                   $device    torch device such as "cpu", "cuda" or "mps"; null picks the best
     * @param (callable(string): void)|null $onOutput  receives the runner's progress output as it arrives
     *
     * @return string the transcript
     *
     * @throws AudioNotFoundException
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException when the model is not a transformers speech recognition model, or a language
     *                                   or translation is asked of a model that only knows one language
     * @throws HomeDirectoryNotFoundException
     * @throws RunFailedException e.g. when the file has no audio
     */
    public function transcribe(
        string $model,
        string $audioPath,
        ?string $language = null,
        bool $translate = false,
        ?string $device = null,
        ?callable $onOutput = null,
    ): string {
        return $this->transcription($model, $audioPath, $language, $translate, false, $device, $onOutput)['text'];
    }

    /**
     * Like transcribe(), split into timed segments: sentences or phrases for Whisper-style models, words for CTC models.
     *
     * @return list<array{start: float, end: float|null, text: string}> seconds from the start of the audio; the last
     *                                                                   segment may have no end
     *
     * @throws AudioNotFoundException
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException
     * @throws HomeDirectoryNotFoundException
     * @throws RunFailedException
     */
    public function transcribeWithTimestamps(
        string $model,
        string $audioPath,
        ?string $language = null,
        bool $translate = false,
        ?string $device = null,
        ?callable $onOutput = null,
    ): array {
        return $this->transcription($model, $audioPath, $language, $translate, true, $device, $onOutput)['segments'];
    }

    /**
     * Runs the checks transcribe() performs before starting the binary, including whether the model can be told a
     * language or translate, without transcribing anything.
     *
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException
     */
    public function ensureCanTranscribe(string $model, ?string $language = null, bool $translate = false): void
    {
        $this->ensureCanRun($model);

        // Multilingual models such as Whisper list the languages they can be told in generation_config.json.
        $generationConfig = json_decode((string) @file_get_contents($this->modelPath($model) . '/generation_config.json'), true);
        if (($language !== null || $translate) && !isset($generationConfig['lang_to_id'])) {
            throw new UnsupportedModelException($model, $this->modelPath($model), sprintf(
                '%s cannot be told the spoken language or translate: it only transcribes the language it was trained on. '
                . 'Leave out the language and translation, or use a multilingual model such as %s.',
                $model,
                self::EXAMPLE_MODEL,
            ));
        }
    }

    /**
     * The runner loads models with transformers; whisper.cpp and faster-whisper conversions of the same models are
     * common on Hugging Face but need other software.
     */
    protected function ensureModelIsSupported(string $model, string $modelPath): void
    {
        $problem = match (true) {
            !TransformersModel::has($modelPath, 'config.json') && TransformersModel::has($modelPath, 'ggml*.bin')
                => 'it is in whisper.cpp (GGML) format, which transformers cannot load.',
            TransformersModel::has($modelPath, 'model.bin') && !TransformersModel::has($modelPath, '*.safetensors')
                && !TransformersModel::has($modelPath, 'pytorch_model*.bin')
                => 'it is in faster-whisper (CTranslate2) format, which transformers cannot load.',
            default => TransformersModel::problem($modelPath) ?? self::audioProblem($modelPath),
        };

        if ($problem === null) {
            return;
        }

        throw new UnsupportedModelException($model, $modelPath, sprintf(
            '%s cannot transcribe speech: %s Use a transformers speech recognition model instead, e.g. %s (browse: %s).',
            $model,
            $problem,
            self::EXAMPLE_MODEL,
            self::COMPATIBLE_MODELS_URL,
        ));
    }

    /**
     * Speech models read audio through a feature extractor, configured in preprocessor_config.json (or, in some older
     * repositories, feature_extractor_config.json) with at least the audio's sampling rate.
     */
    private static function audioProblem(string $modelPath): ?string
    {
        $configs = [];
        foreach (['preprocessor_config.json', 'feature_extractor_config.json'] as $file) {
            $config = json_decode((string) @file_get_contents("{$modelPath}/{$file}"), true);
            if (is_array($config)) {
                $configs[] = $config;
            }
        }

        foreach ($configs as $config) {
            if (isset($config['feature_extractor_type']) || isset($config['sampling_rate'])) {
                return null;
            }
        }

        foreach ($configs as $config) {
            if (isset($config['image_processor_type'])) {
                return 'it reads images, not audio; use it with image-to-text.';
            }
        }

        return 'it cannot hear audio (it has no audio feature extractor in preprocessor_config.json). If it is a text model, use it with text-to-text.';
    }

    /**
     * @return array{text: string, segments: list<array{start: float, end: float|null, text: string}>}
     *
     * @throws AudioNotFoundException
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException
     * @throws HomeDirectoryNotFoundException
     * @throws RunFailedException
     */
    private function transcription(
        string $model,
        string $audioPath,
        ?string $language,
        bool $translate,
        bool $timestamps,
        ?string $device,
        ?callable $onOutput,
    ): array {
        $audioPath = Path::expandHome($audioPath);
        if (!is_file($audioPath) || !is_readable($audioPath)) {
            throw new AudioNotFoundException($audioPath);
        }

        $this->ensureCanTranscribe($model, $language, $translate);

        $result = $this->run($model, [
            '--audio' => $audioPath,
            '--language' => $language,
            '--translate' => $translate,
            '--timestamps' => $timestamps,
            '--device' => $device,
        ], $onOutput);

        if (!is_string($result['text'] ?? null)) {
            throw new RunFailedException(0, 'The runner did not report the transcript.');
        }

        $segments = [];
        foreach ($timestamps && is_array($result['segments'] ?? null) ? $result['segments'] : [] as $segment) {
            if (is_array($segment) && is_numeric($segment['start'] ?? null) && is_string($segment['text'] ?? null)) {
                $segments[] = [
                    'start' => (float) $segment['start'],
                    'end' => is_numeric($segment['end'] ?? null) ? (float) $segment['end'] : null,
                    'text' => $segment['text'],
                ];
            }
        }

        return ['text' => $result['text'], 'segments' => $segments];
    }
}
