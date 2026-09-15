<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\AudioNotFoundException;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Runner\SpeechToText;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpeechToTextTest extends TestCase
{
    private const AUDIO_PREPROCESSOR = '{"feature_extractor_type": "WhisperFeatureExtractor", "sampling_rate": 16000}';

    private FakeProject $project;

    private string $audio;

    protected function setUp(): void
    {
        $this->project = (new FakeProject())
            ->install(Tool::SpeechToText, FakeProject::FAKE_SPEECH_TO_TEXT)
            ->addModel('org/whisper', ['config.json', 'model.safetensors']);
        $this->project->addFile('.local/models/org/whisper/preprocessor_config.json', self::AUDIO_PREPROCESSOR);
        $this->project->addFile('.local/models/org/whisper/generation_config.json', '{"lang_to_id": {"<|en|>": 50259, "<|uk|>": 50280}}');
        $this->audio = $this->project->addFile('audio/interview.mp3', 'mp3 bytes');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testTranscribes(): void
    {
        $args = '';
        $text = $this->speechToText()->transcribe('org/whisper', $this->audio, onOutput: static function (string $chunk) use (&$args): void {
            $args .= $chunk;
        });

        self::assertSame('Hello from interview.mp3. Goodbye.', $text);
        self::assertSame("args: --model {$this->project->root}/.local/models/org/whisper --audio {$this->audio}\n", $args);
    }

    public function testPassesLanguageTranslateAndDevice(): void
    {
        $args = '';
        $this->speechToText()->transcribe('org/whisper', $this->audio, language: 'uk', translate: true, device: 'mps', onOutput: static function (string $chunk) use (&$args): void {
            $args .= $chunk;
        });

        self::assertStringEndsWith("--audio {$this->audio} --language uk --translate --device mps\n", $args);
    }

    #[DataProvider('languageOptions')]
    public function testRejectsLanguageOptionsForSingleLanguageModel(?string $language, bool $translate): void
    {
        $this->project->addModel('org/wav2vec2', ['config.json', 'model.safetensors']);
        $this->project->addFile('.local/models/org/wav2vec2/preprocessor_config.json', '{"sampling_rate": 16000}');

        $this->expectException(UnsupportedModelException::class);
        $this->expectExceptionMessage(
            'org/wav2vec2 cannot be told the spoken language or translate: it only transcribes the language it was trained on. '
            . 'Leave out the language and translation, or use a multilingual model such as openai/whisper-tiny.',
        );

        $this->speechToText()->transcribe('org/wav2vec2', $this->audio, $language, $translate);
    }

    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public static function languageOptions(): iterable
    {
        yield 'language' => ['fr', false];
        yield 'translate' => [null, true];
    }

    public function testTranscribesWithTimestamps(): void
    {
        $args = '';
        $segments = $this->speechToText()->transcribeWithTimestamps('org/whisper', $this->audio, onOutput: static function (string $chunk) use (&$args): void {
            $args .= $chunk;
        });

        self::assertSame(
            [
                ['start' => 0.0, 'end' => 1.5, 'text' => 'Hello from interview.mp3.'],
                ['start' => 3723.456, 'end' => null, 'text' => 'Goodbye.'],
            ],
            $segments,
        );
        self::assertStringEndsWith("--audio {$this->audio} --timestamps\n", $args);
    }

    public function testRequiresReadableAudioBeforeStarting(): void
    {
        $output = '';

        try {
            $this->speechToText()->transcribe('org/whisper', "{$this->project->root}/missing.wav", onOutput: static function (string $chunk) use (&$output): void {
                $output .= $chunk;
            });
            self::fail('Expected AudioNotFoundException.');
        } catch (AudioNotFoundException $e) {
            self::assertSame("{$this->project->root}/missing.wav", $e->path);
            self::assertSame("Audio file not found or not readable: {$this->project->root}/missing.wav", $e->getMessage());
        }

        self::assertSame('', $output, 'The runner binary is not started.');
    }

    public function testThrowsWhenRunFails(): void
    {
        try {
            $this->speechToText()->transcribe('org/whisper', $this->project->addFile('fail.wav'));
            self::fail('Expected RunFailedException.');
        } catch (RunFailedException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('has no audio track', $e->getMessage());
        }
    }

    public function testRequiresPulledModel(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->speechToText()->transcribe('org/missing', $this->audio);
    }

    public function testRequiresInstalledRunner(): void
    {
        $project = (new FakeProject())->addModel('org/whisper', ['config.json', 'model.safetensors']);

        try {
            (new SpeechToText($project->storage))->transcribe('org/whisper', $this->audio);
            self::fail('Expected BinaryNotInstalledException.');
        } catch (BinaryNotInstalledException $e) {
            self::assertSame(Tool::SpeechToText, $e->tool);
        } finally {
            $project->remove();
        }
    }

    /**
     * @param list<string> $files
     */
    #[DataProvider('unsupportedModels')]
    public function testRejectsUnsupportedModel(array $files, ?string $preprocessor, string $reason): void
    {
        $this->project->addModel('org/unsupported', $files);
        if ($preprocessor !== null) {
            $this->project->addFile('.local/models/org/unsupported/preprocessor_config.json', $preprocessor);
        }

        try {
            $this->speechToText()->transcribe('org/unsupported', $this->audio);
            self::fail('Expected UnsupportedModelException.');
        } catch (UnsupportedModelException $e) {
            self::assertStringStartsWith("org/unsupported cannot transcribe speech: {$reason}", $e->getMessage());
            self::assertStringEndsWith(
                'Use a transformers speech recognition model instead, e.g. openai/whisper-tiny '
                . '(browse: https://huggingface.co/models?pipeline_tag=automatic-speech-recognition&library=transformers).',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{list<string>, string|null, string}>
     */
    public static function unsupportedModels(): iterable
    {
        yield 'whisper.cpp' => [['ggml-base.en.bin', 'README.md'], null, 'it is in whisper.cpp (GGML) format'];
        yield 'faster-whisper' => [['config.json', 'model.bin', 'vocabulary.json'], self::AUDIO_PREPROCESSOR, 'it is in faster-whisper (CTranslate2) format'];
        yield 'text model' => [['config.json', 'model.safetensors'], null, 'it cannot hear audio (it has no audio feature extractor in preprocessor_config.json). If it is a text model, use it with text-to-text.'];
        yield 'image model' => [['config.json', 'model.safetensors'], '{"image_processor_type": "SiglipImageProcessor"}', 'it reads images, not audio; use it with image-to-text.'];
        yield 'GGUF' => [['whisper-q5.gguf'], null, 'it is in GGUF format'];
    }

    public function testAcceptsOlderAudioConfigWithoutFeatureExtractorType(): void
    {
        // As in facebook/wav2vec2-base-960h.
        $this->project->addModel('org/wav2vec2', ['config.json', 'pytorch_model.bin']);
        $this->project->addFile(
            '.local/models/org/wav2vec2/preprocessor_config.json',
            '{"do_normalize": true, "feature_size": 1, "padding_side": "right", "padding_value": 0.0, "return_attention_mask": false, "sampling_rate": 16000}',
        );

        self::assertSame('Hello from interview.mp3. Goodbye.', $this->speechToText()->transcribe('org/wav2vec2', $this->audio));
    }

    public function testAcceptsFeatureExtractorConfigFile(): void
    {
        $this->project->addModel('org/hubert', ['config.json', 'model.safetensors']);
        $this->project->addFile('.local/models/org/hubert/feature_extractor_config.json', '{"sampling_rate": 16000}');

        self::assertSame('Hello from interview.mp3. Goodbye.', $this->speechToText()->transcribe('org/hubert', $this->audio));
    }

    private function speechToText(): SpeechToText
    {
        return new SpeechToText($this->project->storage);
    }
}
