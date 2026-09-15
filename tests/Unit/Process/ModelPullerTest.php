<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Process;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\InvalidModelIdException;
use PhpLovesAi\Exception\MissingApiKeyException;
use PhpLovesAi\Exception\ModelAccessDeniedException;
use PhpLovesAi\Exception\PullFailedException;
use PhpLovesAi\HuggingFace\Credentials;
use PhpLovesAi\Process\ModelPuller;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelPullerTest extends TestCase
{
    private const API_KEY_ENV = 'HUGGING_FACE_API_KEY';

    private FakeProject $project;

    private string $modelsDir;

    protected function setUp(): void
    {
        $this->project = (new FakeProject())->install(Tool::Puller, FakeProject::FAKE_PULLER);
        $this->modelsDir = "{$this->project->root}/.local/models";
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testPullsIntoProjectModelsDir(): void
    {
        self::assertSame(
            [
                'openai-community/gpt2' => "{$this->modelsDir}/openai-community/gpt2",
                'distilgpt2' => "{$this->modelsDir}/distilgpt2",
            ],
            $this->puller()->pull(['openai-community/gpt2', 'distilgpt2']),
        );
        self::assertDirectoryExists($this->modelsDir);
        self::assertFileExists("{$this->project->root}/.local/.gitignore");
    }

    public function testPassesRevisionAndStreamsProgress(): void
    {
        $progress = '';

        $this->puller()->pull(
            ['openai-community/gpt2'],
            revision: 'v1.0',
            onProgress: static function (string $chunk) use (&$progress): void {
                $progress .= $chunk;
            },
        );

        self::assertStringContainsString("args: --dir {$this->modelsDir} --revision v1.0 -- openai-community/gpt2", $progress);
    }

    public function testFailureKeepsModelsPulledBeforeIt(): void
    {
        try {
            $this->puller()->pull(['good/model', 'broken/model']);
            self::fail('Expected PullFailedException.');
        } catch (PullFailedException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertSame(['good/model' => "{$this->modelsDir}/good/model"], $e->pulled);
            self::assertStringContainsString('failed to pull broken/model', $e->getMessage());
        }
    }

    public function testPullsPublicModelsWithoutApiKey(): void
    {
        $progress = $this->pullAndCaptureProgress(['openai-community/gpt2']);

        self::assertStringContainsString('api key: none', $progress);
    }

    public function testPassesSavedApiKey(): void
    {
        (new Credentials($this->project->storage))->saveApiKey('hf_saved');

        self::assertSame(['private/model' => "{$this->modelsDir}/private/model"], $this->puller()->pull(['private/model']));
        self::assertStringContainsString('api key: hf_saved', $this->pullAndCaptureProgress(['org/model']));
    }

    public function testIgnoresApiKeyFromEnvironment(): void
    {
        putenv(self::API_KEY_ENV . '=hf_from_shell');

        try {
            self::assertStringContainsString('api key: none', $this->pullAndCaptureProgress(['org/model']));
        } finally {
            putenv(self::API_KEY_ENV);
        }
    }

    public function testReportsPrivateModelWithoutApiKey(): void
    {
        try {
            $this->puller()->pull(['org/first', 'private/model']);
            self::fail('Expected ModelAccessDeniedException.');
        } catch (ModelAccessDeniedException $e) {
            self::assertSame('private/model', $e->model);
            self::assertSame(ModelAccessDeniedException::NOT_FOUND, $e->reason);
            self::assertFalse($e->apiKeyUsed);
            self::assertSame(['org/first' => "{$this->modelsDir}/org/first"], $e->pulled);
            self::assertSame('private/model is not available: it does not exist, or it is private and needs a Hugging Face API key.', $e->getMessage());
        }
    }

    public function testReportsGatedModel(): void
    {
        (new Credentials($this->project->storage))->saveApiKey('hf_saved');

        try {
            $this->puller()->pull(['gated/model']);
            self::fail('Expected ModelAccessDeniedException.');
        } catch (ModelAccessDeniedException $e) {
            self::assertSame(ModelAccessDeniedException::GATED, $e->reason);
            self::assertTrue($e->apiKeyUsed);
            self::assertStringContainsString('Open https://huggingface.co/gated/model, accept its terms', $e->getMessage());
        }
    }

    public function testReportsOutdatedPullerThatRequiresApiKey(): void
    {
        $this->expectException(MissingApiKeyException::class);

        $this->puller()->pull(['outdated/model']);
    }

    public function testRequiresInstalledPuller(): void
    {
        $project = new FakeProject();

        try {
            (new ModelPuller($project->storage))->pull(['openai-community/gpt2']);
            self::fail('Expected BinaryNotInstalledException.');
        } catch (BinaryNotInstalledException $e) {
            self::assertSame(Tool::Puller, $e->tool);
        } finally {
            $project->remove();
        }
    }

    #[DataProvider('invalidModelIds')]
    public function testRejectsInvalidModelIds(string $model): void
    {
        $this->expectException(InvalidModelIdException::class);

        $this->puller()->pull([$model]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidModelIds(): iterable
    {
        yield 'flag' => ['--help'];
        yield 'path traversal' => ['org/../../etc'];
        yield 'too many segments' => ['a/b/c'];
        yield 'empty' => [''];
    }

    /**
     * @param list<string> $models
     */
    private function pullAndCaptureProgress(array $models): string
    {
        $progress = '';
        $this->puller()->pull($models, onProgress: static function (string $chunk) use (&$progress): void {
            $progress .= $chunk;
        });

        return $progress;
    }

    private function puller(): ModelPuller
    {
        return new ModelPuller($this->project->storage);
    }
}
