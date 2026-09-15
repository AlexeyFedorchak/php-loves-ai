<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\HuggingFace;

use PhpLovesAi\Exception\InvalidApiKeyException;
use PhpLovesAi\Exception\InvalidConfigException;
use PhpLovesAi\HuggingFace\Credentials;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CredentialsTest extends TestCase
{
    private FakeProject $project;

    private Credentials $credentials;

    protected function setUp(): void
    {
        $this->project = new FakeProject();
        $this->credentials = new Credentials($this->project->storage);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testIsNotConfiguredInitially(): void
    {
        self::assertSame("{$this->project->root}/.local/huggingface/credentials.json", $this->credentials->path());
        self::assertFalse($this->credentials->isConfigured());
        self::assertNull($this->credentials->apiKey());
    }

    public function testSavesApiKeyReadableOnlyByOwner(): void
    {
        $this->credentials->saveApiKey("  hf_abc123XYZ \n");

        self::assertTrue($this->credentials->isConfigured());
        self::assertSame('hf_abc123XYZ', $this->credentials->apiKey());
        self::assertSame(['api_key' => 'hf_abc123XYZ'], json_decode((string) file_get_contents($this->credentials->path()), true));
        self::assertSame(0600, fileperms($this->credentials->path()) & 0777);
        self::assertSame("*\n", file_get_contents("{$this->project->root}/.local/.gitignore"), 'The key is kept out of git.');
    }

    public function testRemembersDeclinedApiKey(): void
    {
        $this->credentials->declineApiKey();

        self::assertTrue($this->credentials->isConfigured());
        self::assertNull($this->credentials->apiKey());
    }

    public function testReplacesSavedApiKey(): void
    {
        $this->credentials->declineApiKey();
        $this->credentials->saveApiKey('hf_first');
        $this->credentials->saveApiKey('hf_second');

        self::assertSame('hf_second', $this->credentials->apiKey());
    }

    #[DataProvider('invalidApiKeys')]
    public function testRejectsInvalidApiKey(string $apiKey): void
    {
        try {
            $this->credentials->saveApiKey($apiKey);
            self::fail('Expected InvalidApiKeyException.');
        } catch (InvalidApiKeyException $e) {
            self::assertStringContainsString('is not a Hugging Face API key; keys start with hf_', $e->getMessage());
            self::assertStringNotContainsString('secret-value', $e->getMessage(), 'The whole value is never shown.');
        }

        self::assertFalse($this->credentials->isConfigured());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidApiKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'without hf_ prefix' => ['my-secret-value'];
        yield 'with spaces inside' => ['hf_abc secret-value'];
    }

    public function testRejectsEditedCredentialsFile(): void
    {
        mkdir(dirname($this->credentials->path()), 0777, true);
        file_put_contents($this->credentials->path(), '{"api_key": 42}');

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('must contain {"api_key": "hf_..."} or {"api_key": null}');

        $this->credentials->apiKey();
    }
}
