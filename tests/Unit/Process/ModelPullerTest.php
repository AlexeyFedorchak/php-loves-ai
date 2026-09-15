<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Process;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\InvalidModelIdException;
use PhpLovesAi\Exception\MissingApiKeyException;
use PhpLovesAi\Exception\PullFailedException;
use PhpLovesAi\Process\ModelPuller;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelPullerTest extends TestCase
{
    private string|false $originalApiKey;

    private FakeProject $project;

    private string $modelsDir;

    protected function setUp(): void
    {
        $this->originalApiKey = getenv(ModelPuller::API_KEY_ENV);
        putenv(ModelPuller::API_KEY_ENV . '=test-key');

        $this->project = (new FakeProject())->install(Tool::Puller, FakeProject::FAKE_PULLER);
        $this->modelsDir = "{$this->project->root}/.local/models";
    }

    protected function tearDown(): void
    {
        putenv($this->originalApiKey === false
            ? ModelPuller::API_KEY_ENV
            : ModelPuller::API_KEY_ENV . '=' . $this->originalApiKey);

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

    public function testRequiresApiKey(): void
    {
        putenv(ModelPuller::API_KEY_ENV);

        $this->expectException(MissingApiKeyException::class);

        $this->puller()->pull(['openai-community/gpt2']);
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

    private function puller(): ModelPuller
    {
        return new ModelPuller($this->project->storage);
    }
}
