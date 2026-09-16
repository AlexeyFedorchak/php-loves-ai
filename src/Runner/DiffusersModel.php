<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

/**
 * Reads what kind of pipeline a pulled diffusers model is, from the model_index.json every pipeline ships.
 */
final class DiffusersModel
{
    /**
     * Pipelines that generate a still image, named so the video runners can point users to text-to-image
     * instead of failing inside the binary.
     */
    private const IMAGE_PIPELINES = [
        'StableDiffusion', 'Flux', 'Kandinsky', 'PixArt', 'IFPipeline', 'LatentConsistencyModel', 'Amused', 'Sana',
    ];

    /** Names of video pipelines contain one of these, e.g. LTXPipeline, WanPipeline, AnimateDiffPipeline. */
    private const VIDEO_PIPELINES = [
        'Video', 'AnimateDiff', 'Wan', 'LTX', 'CogVideoX', 'Mochi', 'Hunyuan', 'Latte', 'Allegro', 'Zeroscope',
    ];

    public static function isPipeline(string $modelPath): bool
    {
        return TransformersModel::has($modelPath, 'model_index.json');
    }

    /** The pipeline class the model declares, e.g. "LTXPipeline"; null when it declares none. */
    public static function className(string $modelPath): ?string
    {
        $index = json_decode((string) @file_get_contents("{$modelPath}/model_index.json"), true);
        $class = is_array($index) ? ($index['_class_name'] ?? null) : null;

        return is_string($class) ? $class : null;
    }

    /**
     * Whether the pipeline is known to produce a still image rather than a video. Unknown pipelines are not
     * rejected: new architectures appear constantly, and the runner reports what it cannot load.
     */
    public static function isStillImagePipeline(string $modelPath): bool
    {
        $class = self::className($modelPath);
        if ($class === null || self::matches($class, self::VIDEO_PIPELINES)) {
            return false;
        }

        return self::matches($class, self::IMAGE_PIPELINES);
    }

    /**
     * @param list<string> $needles
     */
    private static function matches(string $class, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($class, $needle)) {
                return true;
            }
        }

        return false;
    }
}
