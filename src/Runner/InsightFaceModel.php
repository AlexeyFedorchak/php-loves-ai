<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

/**
 * Checks the pulled files of a model for what the face runner loads: an InsightFace pack holding a
 * detection graph and a recognition graph as ONNX, e.g. immich-app/buffalo_l.
 *
 *   detection/model.onnx    finds faces and their landmarks
 *   recognition/model.onnx  turns an aligned face into an embedding
 */
final class InsightFaceModel
{
    public const DETECTION = 'detection/model.onnx';

    public const RECOGNITION = 'recognition/model.onnx';

    /**
     * Explains, for a user who may not know Hugging Face formats, why the model in $modelPath cannot be loaded.
     *
     * @return string|null a reason starting with "it", e.g. "it is a chat model, …"; null when loadable
     */
    public static function problem(string $modelPath): ?string
    {
        $hasDetection = is_file("{$modelPath}/" . self::DETECTION);
        $hasRecognition = is_file("{$modelPath}/" . self::RECOGNITION);

        if ($hasDetection && $hasRecognition) {
            return null;
        }

        // Pointed at the task the model is actually for, which is the usual mistake.
        if (TransformersModel::has($modelPath, 'model_index.json')) {
            return 'it is an image generation model, made for text-to-image.';
        }

        if (TransformersModel::has($modelPath, 'config.json')) {
            return 'it is a transformers model, not a face recognition pack. To describe what is in a picture, '
                . 'use image-to-text.';
        }

        if ($hasDetection !== $hasRecognition) {
            return sprintf(
                'it only has the %s half of a face pack (%s is missing). The pull may not have finished.',
                $hasDetection ? 'detection' : 'recognition',
                $hasDetection ? self::RECOGNITION : self::DETECTION,
            );
        }

        if (TransformersModel::has($modelPath, '*.onnx') || TransformersModel::has($modelPath, '*/*.onnx')) {
            return sprintf(
                'its ONNX files are not laid out as a face pack (%s and %s).',
                self::DETECTION,
                self::RECOGNITION,
            );
        }

        return sprintf(
            'it is not an InsightFace pack (%s/%s is missing). It may be a model for another task, or a pull '
            . 'that did not finish.',
            $modelPath,
            self::DETECTION,
        );
    }
}
