<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Config\TextToVideoConfig;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Runner\TextToVideo;

/**
 * CLI entry point behind `vendor/bin/text-to-video`: generates a video from a prompt via TextToVideo.
 */
final class TextToVideoCommand extends Command
{
    /**
     * Cozy messages shown before transforming, one picked at random: [emoji, lead ("{model}" is replaced), follow-up].
     *
     * @var list<array{string, string, string}>
     */
    public const INTROS = [
        ['🎬', 'Rolling the camera with {model}…', 'Films take their time — perfect for a pot of tea and some cookies 🍪'],
        ['🍿', 'Filming your idea with {model}…', 'Grab a warm drink and a snack; frames take a while ☕'],
        ['🎞️', 'Drawing frame after frame with {model}…', 'Cozy up with some cookies — this takes patience 🍪'],
        ['📽️', 'Directing your video with {model}…', 'How about a hot chocolate while the scene comes together? ☕'],
    ];

    protected const NAME = 'text-to-video';

    protected const OPTIONS = ['output', 'negative-prompt', 'frames', 'fps', 'steps', 'guidance', 'width', 'height', 'seed', 'device', 'log-file'];

    protected const USAGE = <<<'TXT'
        Usage: text-to-video <model> <prompt> [options]

        Generate a video from a text prompt with a diffusers pipeline pulled into .local/models in the project root.

        Arguments:
          model                   Hugging Face model id, e.g. Wan-AI/Wan2.1-T2V-1.3B-Diffusers (pull it first)
          prompt                  Text describing the video; wrap it in quotes

        Options:
          --output=PATH           Video file to write; its extension picks the format: .mp4, .webm, .mkv or .gif
                                  (default: a timestamped .mp4 in 'output_dir' in config/text-to-video.php)
          --negative-prompt=TEXT  Text describing what the video should not contain
          --frames=N              Number of frames to generate (default: the pipeline's own)
          --fps=N                 Frames per second of the written video (default: 8)
          --steps=N               Inference steps (default: the pipeline's own)
          --guidance=SCALE        Guidance scale (default: the pipeline's own)
          --width=PX, --height=PX Frame size (default: the pipeline's own)
          --seed=N                Random seed, for reproducible videos
          --device=DEVICE         Torch device: cpu, cuda, mps... (default: the best available)
          --log-file=PATH         Append the runner's output to this file (default: 'log_file' in config/text-to-video.php)
          --debug                 Show the runner's output while filming
          -h, --help              Show this help

        Video models are the heaviest of all: many gigabytes to pull, and minutes to hours per clip on a CPU.

        Environment:
          NO_COLOR                Disable colored output when set

        TXT;

    /**
     * @param TextToVideoConfig|null    $config    defaults to the package's config/text-to-video.php
     * @param resource|null             $stdout
     * @param resource|null             $stderr
     * @param (\Closure(int): int)|null $pickIntro see Command::__construct()
     * @param LocalStorage|null         $storage   defaults to the project's own
     */
    public function __construct(
        private ?TextToVideoConfig $config = null,
        $stdout = null,
        $stderr = null,
        ?\Closure $pickIntro = null,
        private readonly ?LocalStorage $storage = null,
    ) {
        parent::__construct($stdout, $stderr, $pickIntro);
    }

    protected function execute(array $positional, array $options): int
    {
        if (count($positional) !== 2) {
            throw new \InvalidArgumentException(count($positional) < 2
                ? 'Both a model and a prompt are required.'
                : 'Too many arguments; wrap the prompt in quotes.');
        }

        [$model, $prompt] = $positional;
        $config = $this->config ??= TextToVideoConfig::load();

        $runner = new TextToVideo($this->storage);
        $output = $options['output']
            ?? rtrim($config->outputDir, '/\\') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.mp4';

        $frames = self::intOption($options, 'frames');
        $fps = self::intOption($options, 'fps');
        $steps = self::intOption($options, 'steps');
        $guidance = self::floatOption($options, 'guidance');
        $width = self::intOption($options, 'width');
        $height = self::intOption($options, 'height');
        $seed = self::intOption($options, 'seed');

        $runner->ensureCanRun($model);

        $this->startLog($options['log-file'] ?? $config->logFile, "text-to-video {$model}: {$prompt}");
        $this->writeIntro(self::INTROS, ['{model}' => $model]);

        try {
            $file = $runner->generate(
                $model,
                $prompt,
                $output,
                negativePrompt: $options['negative-prompt'] ?? null,
                frames: $frames,
                fps: $fps,
                steps: $steps,
                guidanceScale: $guidance,
                width: $width,
                height: $height,
                seed: $seed,
                device: $options['device'] ?? null,
                onOutput: $this->binaryOutputHandler(),
            );
        } catch (RunFailedException $e) {
            return $this->binaryFailed("Error: failed to generate a video with {$model} (exit code {$e->exitCode}).");
        }

        return $this->succeeded("Video saved to {$file}");
    }

    protected function hintFor(PhpLovesAiException $e): ?string
    {
        return match (true) {
            $e instanceof ModelNotFoundException => "Pull it first with: vendor/bin/pull {$e->model}",
            $e instanceof UnsupportedModelException => 'To try one: vendor/bin/pull ' . TextToVideo::EXAMPLE_MODEL,
            default => parent::hintFor($e),
        };
    }

    /**
     * @param array<string, string> $options
     */
    private static function intOption(array $options, string $name): ?int
    {
        if (!isset($options[$name])) {
            return null;
        }

        $value = filter_var($options[$name], FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new \InvalidArgumentException("Option --{$name} must be an integer.");
        }

        return $value;
    }

    /**
     * @param array<string, string> $options
     */
    private static function floatOption(array $options, string $name): ?float
    {
        if (!isset($options[$name])) {
            return null;
        }

        $value = filter_var($options[$name], FILTER_VALIDATE_FLOAT);
        if ($value === false) {
            throw new \InvalidArgumentException("Option --{$name} must be a number.");
        }

        return $value;
    }
}
