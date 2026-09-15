<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Process;

use PhpLovesAi\Exception\BinaryNotFoundException;
use PhpLovesAi\Exception\HomeDirectoryNotFoundException;
use PhpLovesAi\Exception\InvalidModelIdException;
use PhpLovesAi\Exception\MissingApiKeyException;
use PhpLovesAi\Exception\PullFailedException;
use PhpLovesAi\Process\ModelPuller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelPullerTest extends TestCase
{
    private const FAKE_PULLER = __DIR__ . '/../../Fixtures/fake-puller';

    private string|false $originalApiKey;

    protected function setUp(): void
    {
        $this->originalApiKey = getenv(ModelPuller::API_KEY_ENV);
        putenv(ModelPuller::API_KEY_ENV . '=test-key');
    }

    protected function tearDown(): void
    {
        putenv($this->originalApiKey === false
            ? ModelPuller::API_KEY_ENV
            : ModelPuller::API_KEY_ENV . '=' . $this->originalApiKey);
    }

    public function testReturnsPathsOfPulledModels(): void
    {
        $puller = new ModelPuller(self::FAKE_PULLER, '/models');

        self::assertSame(
            [
                'openai-community/gpt2' => '/models/openai-community/gpt2',
                'distilgpt2' => '/models/distilgpt2',
            ],
            $puller->pull(['openai-community/gpt2', 'distilgpt2']),
        );
    }

    public function testPassesRevisionAndStreamsProgress(): void
    {
        $progress = '';

        (new ModelPuller(self::FAKE_PULLER, '/models'))->pull(
            ['openai-community/gpt2'],
            revision: 'v1.0',
            onProgress: static function (string $chunk) use (&$progress): void {
                $progress .= $chunk;
            },
        );

        self::assertStringContainsString('args: --dir /models --revision v1.0 -- openai-community/gpt2', $progress);
    }

    public function testFailureKeepsModelsPulledBeforeIt(): void
    {
        try {
            (new ModelPuller(self::FAKE_PULLER, '/models'))->pull(['good/model', 'broken/model']);
            self::fail('Expected PullFailedException.');
        } catch (PullFailedException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertSame(['good/model' => '/models/good/model'], $e->pulled);
            self::assertStringContainsString('failed to pull broken/model', $e->getMessage());
        }
    }

    public function testExpandsHomeDirectoryInModelsDir(): void
    {
        $this->withHome('/home/tester', function (): void {
            self::assertSame(
                ['org/model' => '/home/tester/tmp/models/org/model'],
                (new ModelPuller(self::FAKE_PULLER, '~/tmp/models'))->pull(['org/model']),
            );
        });
    }

    public function testKeepsTildeOutsideLeadingPosition(): void
    {
        self::assertSame(
            ['org/model' => '/data/~models/org/model'],
            (new ModelPuller(self::FAKE_PULLER, '/data/~models'))->pull(['org/model']),
        );
    }

    public function testFailsWhenHomeDirectoryIsUnknown(): void
    {
        $this->withHome(null, function (): void {
            $this->expectException(HomeDirectoryNotFoundException::class);

            new ModelPuller(self::FAKE_PULLER, '~/tmp/models');
        });
    }

    public function testRequiresApiKey(): void
    {
        putenv(ModelPuller::API_KEY_ENV);

        $this->expectException(MissingApiKeyException::class);

        (new ModelPuller(self::FAKE_PULLER, '/models'))->pull(['openai-community/gpt2']);
    }

    public function testRequiresExistingBinary(): void
    {
        $this->expectException(BinaryNotFoundException::class);

        (new ModelPuller('/nonexistent/puller', '/models'))->pull(['openai-community/gpt2']);
    }

    #[DataProvider('invalidModelIds')]
    public function testRejectsInvalidModelIds(string $model): void
    {
        $this->expectException(InvalidModelIdException::class);

        (new ModelPuller(self::FAKE_PULLER, '/models'))->pull([$model]);
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
     * Runs $test with HOME set to $home (unset when null) and USERPROFILE unset, restoring both afterwards.
     */
    private function withHome(?string $home, \Closure $test): void
    {
        $original = ['HOME' => getenv('HOME'), 'USERPROFILE' => getenv('USERPROFILE')];

        putenv($home === null ? 'HOME' : "HOME={$home}");
        putenv('USERPROFILE');

        try {
            $test();
        } finally {
            foreach ($original as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    }
}
