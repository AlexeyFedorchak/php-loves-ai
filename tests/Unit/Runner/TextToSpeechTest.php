<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Runner\TextToSpeech;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextToSpeechTest extends TestCase
{
    private FakeProject $project;

    protected function setUp(): void
    {
        $this->project = (new FakeProject())
            ->install(Tool::TextToSpeech, FakeProject::FAKE_TEXT_TO_SPEECH)
            ->addModel('org/voice', ['config.json', 'model.safetensors']);
        $this->project->addFile('.local/models/org/voice/config.json', '{"model_type": "vits"}');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testWritesAudioFile(): void
    {
        $args = '';
        $file = $this->textToSpeech()->speak('org/voice', 'Hello there', '/audio/hello.wav', onOutput: static function (string $chunk) use (&$args): void {
            $args .= $chunk;
        });

        self::assertSame('/audio/hello.wav', $file);
        self::assertSame("args: --model {$this->project->root}/.local/models/org/voice --text Hello there --output /audio/hello.wav\n", $args);
    }

    public function testPassesOptionalParameters(): void
    {
        $args = '';
        $this->textToSpeech()->speak(
            'org/voice',
            'Hello',
            '/audio/hello.mp3',
            voice: 'v2/en_speaker_6',
            speed: 0.8,
            seed: 42,
            device: 'mps',
            onOutput: static function (string $chunk) use (&$args): void {
                $args .= $chunk;
            },
        );

        self::assertStringEndsWith('--output /audio/hello.mp3 --voice v2/en_speaker_6 --speed 0.8 --seed 42 --device mps' . "\n", $args);
    }

    public function testThrowsWhenRunFails(): void
    {
        try {
            $this->textToSpeech()->speak('org/voice', 'fail', '/audio/hello.wav');
            self::fail('Expected RunFailedException.');
        } catch (RunFailedException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('produced no audio', $e->getMessage());
        }
    }

    public function testRequiresPulledModel(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->textToSpeech()->speak('org/missing', 'Hello', '/audio/hello.wav');
    }

    public function testRequiresInstalledRunner(): void
    {
        $project = (new FakeProject())->addModel('org/voice', ['config.json', 'model.safetensors']);

        try {
            (new TextToSpeech($project->storage))->speak('org/voice', 'Hello', '/audio/hello.wav');
            self::fail('Expected BinaryNotInstalledException.');
        } catch (BinaryNotInstalledException $e) {
            self::assertSame(Tool::TextToSpeech, $e->tool);
        } finally {
            $project->remove();
        }
    }

    /**
     * @param list<string> $files
     */
    #[DataProvider('unsupportedModels')]
    public function testRejectsUnsupportedModel(array $files, ?string $config, string $reason): void
    {
        $this->project->addModel('org/unsupported', $files);
        if ($config !== null) {
            $this->project->addFile('.local/models/org/unsupported/config.json', $config);
        }

        try {
            $this->textToSpeech()->speak('org/unsupported', 'Hello', '/audio/hello.wav');
            self::fail('Expected UnsupportedModelException.');
        } catch (UnsupportedModelException $e) {
            self::assertStringStartsWith("org/unsupported cannot read text aloud: {$reason}", $e->getMessage());
            self::assertStringEndsWith(
                'Use a transformers text-to-speech model instead, e.g. facebook/mms-tts-eng '
                . '(browse: https://huggingface.co/models?pipeline_tag=text-to-speech&library=transformers).',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{list<string>, string|null, string}>
     */
    public static function unsupportedModels(): iterable
    {
        yield 'speech recognition model' => [['config.json', 'model.safetensors'], '{"model_type": "whisper"}', 'it transcribes speech instead of speaking; use it with speech-to-text.'];
        yield 'SpeechT5' => [['config.json', 'model.safetensors'], '{"model_type": "speecht5"}', 'it needs a speaker embedding file to choose a voice, which this runner cannot provide.'];
        yield 'needs its own code' => [['config.json', 'model.safetensors'], '{"model_type": "kokoro", "auto_map": {"AutoModel": "modeling.Kokoro"}}', 'it needs its own Python code to run'];
        yield 'GGUF' => [['voice-q4.gguf'], null, 'it is in GGUF format'];
        yield 'no weights' => [['config.json'], '{"model_type": "vits"}', 'its weights (*.safetensors or *.bin files) are missing.'];
    }

    private function textToSpeech(): TextToSpeech
    {
        return new TextToSpeech($this->project->storage);
    }
}
