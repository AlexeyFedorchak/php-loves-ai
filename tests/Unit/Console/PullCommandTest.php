<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Config\PullConfig;
use PhpLovesAi\Console\PullCommand;
use PhpLovesAi\Process\ModelPuller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PullCommandTest extends TestCase
{
    private const FAKE_PULLER = __DIR__ . '/../../Fixtures/fake-puller';

    private string|false $originalApiKey;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private string $logDir;

    protected function setUp(): void
    {
        $this->originalApiKey = getenv(ModelPuller::API_KEY_ENV);
        putenv(ModelPuller::API_KEY_ENV . '=test-key');

        $this->stdout = self::memoryStream();
        $this->stderr = self::memoryStream();
        $this->logDir = sys_get_temp_dir() . '/pull-command-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        putenv($this->originalApiKey === false
            ? ModelPuller::API_KEY_ENV
            : ModelPuller::API_KEY_ENV . '=' . $this->originalApiKey);

        foreach (["{$this->logDir}/pull.log", "{$this->logDir}/nested/pull.log"] as $file) {
            is_file($file) && unlink($file);
        }
        foreach (["{$this->logDir}/nested", $this->logDir] as $dir) {
            is_dir($dir) && rmdir($dir);
        }
    }

    public function testPullsModelUsingConfigWithoutShowingPullerOutput(): void
    {
        self::assertSame(PullCommand::EXIT_OK, $this->runCommand(['openai-community/gpt2']));
        self::assertSame("Pulled openai-community/gpt2 into /models/openai-community/gpt2\n", $this->contents($this->stdout));
        self::assertSame('', $this->contents($this->stderr));
    }

    public function testOptionsOverrideConfig(): void
    {
        $log = "{$this->logDir}/nested/pull.log";

        $exitCode = $this->runCommand(['--dir', '/other', '--revision=v1.0', '--log-file', $log, 'openai-community/gpt2']);

        self::assertSame(PullCommand::EXIT_OK, $exitCode);
        self::assertSame('', $this->contents($this->stderr));
        self::assertStringContainsString('args: --dir /other --revision v1.0 -- openai-community/gpt2', (string) file_get_contents($log));
    }

    public function testAppendsPullerOutputAndOutcomeToConfiguredLogFile(): void
    {
        $log = "{$this->logDir}/pull.log";
        $config = new PullConfig(self::FAKE_PULLER, '/models', 'main', $log);

        $this->runCommand(['org/first'], $config);
        $this->runCommand(['org/second'], $config);

        $contents = (string) file_get_contents($log);
        self::assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [+-]\d{2}:\d{2}\] pull org\/first \(revision main\)$/m', $contents);
        self::assertStringContainsString('args: --dir /models --revision main -- org/first', $contents);
        self::assertStringContainsString('Pulled org/first into /models/org/first', $contents);
        self::assertStringContainsString('Pulled org/second into /models/org/second', $contents);
    }

    public function testShowsHelp(): void
    {
        self::assertSame(PullCommand::EXIT_OK, $this->runCommand(['--help']));
        self::assertStringContainsString('Usage: pull <model> [options]', $this->contents($this->stdout));
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidUsages')]
    public function testRejectsInvalidUsage(array $args, string $expectedError): void
    {
        self::assertSame(PullCommand::EXIT_USAGE, $this->runCommand($args));
        self::assertStringContainsString($expectedError, $this->contents($this->stderr));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidUsages(): iterable
    {
        yield 'no model' => [[], 'Missing model argument.'];
        yield 'two models' => [['org/a', 'org/b'], 'Only one model can be pulled at a time.'];
        yield 'binary is not an option' => [['org/a', '--binary=/bin/sh'], 'Unknown option: --binary=/bin/sh'];
        yield 'short option' => [['org/a', '-f'], 'Unknown option: -f'];
        yield 'option without value' => [['org/a', '--log-file'], 'Option --log-file requires a value.'];
        yield 'option followed by option' => [['org/a', '--dir', '--revision=main'], 'Option --dir requires a value.'];
        yield 'invalid model id' => [['../etc'], "Invalid Hugging Face model id: '../etc'"];
    }

    public function testFailedPullHidesPullerOutputAndSuggestsLogFile(): void
    {
        self::assertSame(PullCommand::EXIT_FAILURE, $this->runCommand(['broken/model']));

        self::assertSame(
            "Error: failed to pull broken/model (exit code 1).\n"
            . "Pass --log-file=PATH (or set 'log_file' in config/pull.php) to save the puller's output.\n",
            $this->contents($this->stderr),
        );
        self::assertSame('', $this->contents($this->stdout));
    }

    public function testFailedPullPointsToLogFile(): void
    {
        $log = "{$this->logDir}/pull.log";

        self::assertSame(PullCommand::EXIT_FAILURE, $this->runCommand(['broken/model', "--log-file={$log}"]));

        self::assertStringContainsString("See {$log} for details.", $this->contents($this->stderr));

        $contents = (string) file_get_contents($log);
        self::assertStringContainsString('[puller] Error: failed to pull broken/model', $contents);
        self::assertStringContainsString('Error: failed to pull broken/model (exit code 1).', $contents);
        self::assertStringNotContainsString('for details', $contents);
    }

    public function testReportsUnwritableLogFile(): void
    {
        self::assertSame(PullCommand::EXIT_FAILURE, $this->runCommand(['org/a', '--log-file=/nonexistent-root-dir/pull.log']));
        self::assertStringContainsString('Cannot write log file: /nonexistent-root-dir/pull.log', $this->contents($this->stderr));
    }

    public function testReportsMissingBinary(): void
    {
        $config = new PullConfig('/nonexistent/puller', '/models', 'main');

        self::assertSame(PullCommand::EXIT_FAILURE, $this->runCommand(['org/a'], $config));
        self::assertStringContainsString('Binary not found or not executable: /nonexistent/puller', $this->contents($this->stderr));
    }

    public function testReportsMissingApiKey(): void
    {
        putenv(ModelPuller::API_KEY_ENV);

        self::assertSame(PullCommand::EXIT_FAILURE, $this->runCommand(['org/a']));
        self::assertStringContainsString('Environment variable HUGGING_FACE_API_KEY is not set', $this->contents($this->stderr));
    }

    /**
     * @param list<string> $args
     */
    private function runCommand(array $args, ?PullConfig $config = null): int
    {
        $config ??= new PullConfig(self::FAKE_PULLER, '/models', 'main');

        return (new PullCommand($config, $this->stdout, $this->stderr))->run($args);
    }

    /**
     * @param resource $stream
     */
    private function contents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * @return resource
     */
    private static function memoryStream()
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        return $stream;
    }
}
