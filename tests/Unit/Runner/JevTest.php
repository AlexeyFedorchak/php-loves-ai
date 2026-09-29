<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\MediaNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Runner\Jev;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JevTest extends TestCase
{
    private const MODEL_FILES = ['config.json', 'model.safetensors', 'processor_config.json', 'head.pt', 'decision_config.json'];

    private FakeProject $project;

    protected function setUp(): void
    {
        $this->project = (new FakeProject())
            ->install(Tool::Jev, FakeProject::FAKE_JEV)
            ->addModel('org/jev', self::MODEL_FILES);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testReturnsDecisionWithProbabilityOfEveryOption(): void
    {
        $args = '';
        $decision = $this->jev()->decide('org/jev', 'Has it started?', ['Yes', 'No'], onOutput: static function (string $chunk) use (&$args): void {
            $args .= $chunk;
        });

        self::assertSame('No', $decision->prediction);
        self::assertSame(1, $decision->index);
        self::assertSame(0.75, $decision->confidence);
        self::assertSame(['Yes' => 0.25, 'No' => 0.75], $decision->probabilities);
        self::assertSame("args: --model {$this->project->root}/.local/models/org/jev --question Has it started? --options [\"Yes\",\"No\"]\n", $args);
    }

    public function testPassesStateMediaAndOptionalParameters(): void
    {
        $video = $this->project->addFile('clips/the wall.mp4', 'video bytes');

        $args = '';
        $this->jev()->decide(
            'org/jev',
            'Who is speaking?',
            ['Ана', 'Bob'],
            state: 'Two people talk.',
            mediaPath: $video,
            modality: 'video',
            frames: 8,
            device: 'cuda',
            onOutput: static function (string $chunk) use (&$args): void {
                $args .= $chunk;
            },
        );

        self::assertStringEndsWith(
            "--question Who is speaking? --options [\"Ана\",\"Bob\"] --state Two people talk. --media {$video} --modality video --frames 8 --device cuda\n",
            $args,
        );
    }

    /**
     * @param list<string> $options
     */
    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInputBeforeStarting(array $options, ?string $modality, string $message): void
    {
        $output = '';

        try {
            $this->jev()->decide('org/jev', 'Which?', $options, modality: $modality, onOutput: static function (string $chunk) use (&$output): void {
                $output .= $chunk;
            });
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertSame('', $output, 'The runner binary is not started.');
    }

    /**
     * @return iterable<string, array{list<string>, string|null, string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'one option' => [['Yes'], null, 'At least two options are needed to choose from.'];
        yield 'too many options' => [array_map(strval(...), range(1, 257)), null, 'At most 256 options can be given, 257 were.'];
        yield 'duplicate options' => [['Yes', 'No', 'Yes'], null, 'Every option must be different.'];
        yield 'unknown modality' => [['Yes', 'No'], 'smell', "Unknown modality 'smell'; use one of: image, audio, video."];
    }

    public function testRequiresReadableMediaBeforeStarting(): void
    {
        try {
            $this->jev()->decide('org/jev', 'Which?', ['Yes', 'No'], mediaPath: "{$this->project->root}/missing.jpg");
            self::fail('Expected MediaNotFoundException.');
        } catch (MediaNotFoundException $e) {
            self::assertSame("{$this->project->root}/missing.jpg", $e->path);
            self::assertSame("Media file not found or not readable: {$this->project->root}/missing.jpg", $e->getMessage());
        }
    }

    public function testThrowsWhenRunFails(): void
    {
        try {
            $this->jev()->decide('org/jev', 'fail', ['Yes', 'No']);
            self::fail('Expected RunFailedException.');
        } catch (RunFailedException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('is not an image', $e->getMessage());
        }
    }

    public function testThrowsWhenResultDoesNotMatchTheOptions(): void
    {
        $this->expectException(RunFailedException::class);
        $this->expectExceptionMessage('The runner did not report a decision.');

        // The fake runner always reports two probabilities.
        $this->jev()->decide('org/jev', 'Which?', ['A', 'B', 'C']);
    }

    public function testRequiresPulledModel(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->jev()->decide('org/missing', 'Which?', ['Yes', 'No']);
    }

    public function testRequiresInstalledRunner(): void
    {
        $project = (new FakeProject())->addModel('org/jev', self::MODEL_FILES);

        try {
            (new Jev($project->storage))->decide('org/jev', 'Which?', ['Yes', 'No']);
            self::fail('Expected BinaryNotInstalledException.');
        } catch (BinaryNotInstalledException $e) {
            self::assertSame(Tool::Jev, $e->tool);
        } finally {
            $project->remove();
        }
    }

    /**
     * @param list<string> $files
     */
    #[DataProvider('unsupportedModels')]
    public function testRejectsUnsupportedModel(array $files, string $reason): void
    {
        $this->project->addModel('org/unsupported', $files);

        try {
            $this->jev()->decide('org/unsupported', 'Which?', ['Yes', 'No']);
            self::fail('Expected UnsupportedModelException.');
        } catch (UnsupportedModelException $e) {
            self::assertStringStartsWith("org/unsupported cannot answer questions with options: {$reason}", $e->getMessage());
            self::assertStringEndsWith(
                'Use a JEV decision classifier instead, e.g. akhilaaa3/Jev-Omni (browse: https://huggingface.co/models?search=jev).',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function unsupportedModels(): iterable
    {
        yield 'chat model' => [['config.json', 'model.safetensors', 'processor_config.json'], 'it has no decision head (head.pt and decision_config.json)'];
        yield 'head without its config' => [['config.json', 'model.safetensors', 'head.pt'], 'it has no decision head (head.pt and decision_config.json)'];
        yield 'image generation model' => [['model_index.json'], 'it is an image generation model, made for text-to-image.'];
        yield 'GGUF' => [['model-q4_k_m.gguf'], 'it is in GGUF format, which is made for llama.cpp and Ollama'];
        yield 'no weights' => [['config.json', 'head.pt', 'decision_config.json'], 'its weights (*.safetensors or *.bin files) are missing.'];
    }

    private function jev(): Jev
    {
        return new Jev($this->project->storage);
    }
}
