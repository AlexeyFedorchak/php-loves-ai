<?php

declare(strict_types=1);

namespace PhpLovesAi\Binary;

/**
 * The prebuilt binaries this package runs, with their release asset and install layout for the current platform.
 */
enum Tool: string
{
    case Puller = 'puller';
    case TextToImage = 'text-to-image';
    case TextToText = 'text-to-text';
    case ImageToText = 'image-to-text';
    case SpeechToText = 'speech-to-text';
    case TextToSpeech = 'text-to-speech';
    case ImageToImage = 'image-to-image';
    case TextToVideo = 'text-to-video';
    case ImageToVideo = 'image-to-video';

    public function label(): string
    {
        return match ($this) {
            self::Puller => 'puller',
            default => "{$this->value} runner",
        };
    }

    /** Command that installs this binary. */
    public function setupCommand(): string
    {
        return match ($this) {
            self::Puller => 'vendor/bin/loves-ai setup',
            default => "vendor/bin/loves-ai setup {$this->value}",
        };
    }

    /** What the binary lets users do, and how, e.g. 'Generate an image: vendor/bin/loves-ai text-to-image <model> "<prompt>"'. */
    public function usage(): string
    {
        return match ($this) {
            self::Puller => 'Pull a model: vendor/bin/loves-ai pull <model>',
            self::TextToImage => 'Generate an image: vendor/bin/loves-ai text-to-image <model> "<prompt>"',
            self::TextToText => 'Generate text: vendor/bin/loves-ai text-to-text <model> "<prompt>"',
            self::ImageToText => 'Describe an image: vendor/bin/loves-ai image-to-text <model> <image>',
            self::SpeechToText => 'Transcribe audio: vendor/bin/loves-ai speech-to-text <model> <audio>',
            self::TextToSpeech => 'Read text aloud: vendor/bin/loves-ai text-to-speech <model> "<text>"',
            self::ImageToImage => 'Transform an image: vendor/bin/loves-ai image-to-image <model> <image>',
            self::TextToVideo => 'Generate a video: vendor/bin/loves-ai text-to-video <model> "<prompt>"',
            self::ImageToVideo => 'Animate an image: vendor/bin/loves-ai image-to-video <model> <image>',
        };
    }

    /** Invitation to install a runner, e.g. "generate images". Null for the puller, which setup installs by default. */
    public function purpose(): ?string
    {
        return match ($this) {
            self::Puller => null,
            self::TextToImage => 'generate images',
            self::TextToText => 'generate text',
            self::ImageToText => 'describe images',
            self::SpeechToText => 'transcribe audio',
            self::TextToSpeech => 'read text aloud',
            self::ImageToImage => 'enlarge or redraw images',
            self::TextToVideo => 'generate videos',
            self::ImageToVideo => 'animate images',
        };
    }

    /** Release asset file name, e.g. "puller-darwin-arm64.tar.gz". */
    public function assetName(): string
    {
        return $this->value . '-' . Platform::current() . '.tar.gz';
    }

    /** Top-level entry inside the release archive: the binary itself, or the directory of a --onedir build. */
    public function archiveRoot(): string
    {
        return match ($this) {
            self::Puller => Platform::binaryName($this->value),
            default => $this->value . '-' . Platform::current(),
        };
    }

    /** Path of the executable relative to the directory the archive was unpacked into. */
    public function executablePath(): string
    {
        return match ($this) {
            self::Puller => $this->archiveRoot(),
            default => $this->archiveRoot() . '/' . Platform::binaryName($this->value),
        };
    }
}
