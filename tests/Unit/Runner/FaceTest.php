<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\ImageNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\NoFaceDetectedException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Runner\Face;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FaceTest extends TestCase
{
    /** @var list<string> */
    private const MODEL_FILES = ['detection/model.onnx', 'recognition/model.onnx'];

    private FakeProject $project;

    private string $image;

    protected function setUp(): void
    {
        $this->project = (new FakeProject())
            ->install(Tool::Face, FakeProject::FAKE_FACE)
            ->addModel('org/pack', self::MODEL_FILES);

        $this->image = $this->project->addFile('photo.jpg', 'image bytes');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testReportsEveryFaceWithItsBoxScoreAndId(): void
    {
        $faces = $this->face()->analyze('org/pack', $this->image);

        self::assertCount(2, $faces);

        self::assertSame([120, 84, 210, 196], $faces[0]->box);
        self::assertSame(0.9731, $faces[0]->score);
        self::assertSame('v1:256:3f8a21c4d9e07b15000000000000000000000000000000000000000000000000', $faces[0]->id);
        self::assertSame([[130, 100], [160, 100], [145, 115], [134, 130], [157, 130]], $faces[0]->landmarks);
        self::assertSame([0.1, -0.2, 0.3], $faces[0]->embedding);

        self::assertSame(90, $faces[0]->width());
        self::assertSame(112, $faces[0]->height());

        self::assertSame(0.8812, $faces[1]->score);
        self::assertSame('v1:256:7c1e03ba55d9f402000000000000000000000000000000000000000000000000', $faces[1]->id);
    }

    public function testPassesEveryOptionToTheRunner(): void
    {
        $args = '';
        $this->face()->analyze(
            'org/pack',
            $this->image,
            minScore: 0.7,
            maxFaces: 3,
            hashBits: 512,
            withEmbeddings: true,
            detectionSize: 1024,
            device: 'cuda',
            onOutput: static function (string $chunk) use (&$args): void {
                $args .= $chunk;
            },
        );

        self::assertSame(
            "args: --model {$this->project->root}/.local/models/org/pack --image {$this->image}"
            . " --min-score 0.7 --max-faces 3 --hash-bits 512 --det-size 1024 --device cuda\n",
            $args,
        );
    }

    public function testLeavesEmbeddingsOutWhenNotAskedFor(): void
    {
        $args = '';
        $faces = $this->face()->analyze('org/pack', $this->image, withEmbeddings: false, onOutput: static function (string $chunk) use (&$args): void {
            $args .= $chunk;
        });

        self::assertStringContainsString('--no-embeddings', $args);
        self::assertNull($faces[0]->embedding);
        self::assertNotNull($faces[0]->id, 'the id is still reported');
    }

    public function testReportsNoFacesForAnImageWithout(): void
    {
        self::assertSame([], $this->face()->analyze('org/pack', $this->project->addFile('empty.jpg', 'image bytes')));
    }

    public function testCountsFacesWithoutRunningRecognition(): void
    {
        $args = '';
        $count = $this->face()->countFaces('org/pack', $this->image, onOutput: static function (string $chunk) use (&$args): void {
            $args .= $chunk;
        });

        self::assertSame(2, $count);
        self::assertStringContainsString('--detect-only', $args);
        self::assertStringNotContainsString('--hash-bits', $args, 'counting needs no face ids');
    }

    public function testCountsZeroFaces(): void
    {
        self::assertSame(0, $this->face()->countFaces('org/pack', $this->project->addFile('empty.jpg', 'image bytes')));
    }

    public function testHasFace(): void
    {
        self::assertTrue($this->face()->hasFace('org/pack', $this->image));
        self::assertFalse($this->face()->hasFace('org/pack', $this->project->addFile('empty.jpg', 'image bytes')));
    }

    public function testComparesTwoImages(): void
    {
        $other = $this->project->addFile('other.jpg', 'image bytes');

        $match = $this->face()->compare('org/pack', $this->image, $other);

        self::assertSame(0.7123, $match->similarity);
        self::assertTrue($match->samePerson);
        self::assertSame(0.4, $match->threshold);
        self::assertSame(2, $match->facesInA);
        self::assertSame(2, $match->facesInB);
        self::assertTrue($match->wasAmbiguous(), 'both images hold more than one face');
        self::assertSame([120, 84, 210, 196], $match->a->box);
        self::assertNull($match->a->embedding, 'embeddings are left out of a match unless asked for');
    }

    public function testComparePassesTheThresholdAndReportsItBack(): void
    {
        $match = $this->face()->compare('org/pack', $this->image, $this->project->addFile('other.jpg', 'x'), threshold: 0.9);

        self::assertFalse($match->samePerson, '0.7123 does not reach 0.9');
        self::assertSame(0.9, $match->threshold);
        self::assertSame(0.7123, $match->similarity, 'the similarity itself does not change with the threshold');
    }

    public function testCompareKeepsEmbeddingsWhenAskedFor(): void
    {
        $match = $this->face()->compare('org/pack', $this->image, $this->project->addFile('other.jpg', 'x'), withEmbeddings: true);

        self::assertSame([0.1, -0.2, 0.3], $match->a->embedding);
        self::assertSame([0.1, -0.2, 0.3], $match->b->embedding);
    }

    public function testCompareFailsWhenAnImageHasNoFace(): void
    {
        $empty = $this->project->addFile('empty.jpg', 'image bytes');

        $this->expectException(NoFaceDetectedException::class);
        $this->expectExceptionMessage("No face was found in {$empty}.");

        $this->face()->compare('org/pack', $this->image, $empty);
    }

    public function testFacesCanBeComparedByTheirIds(): void
    {
        $faces = $this->face()->analyze('org/pack', $this->image);

        self::assertSame(0, $faces[0]->distanceTo($faces[0]));
        self::assertGreaterThan(0, $faces[0]->distanceTo($faces[1]));
    }

    public function testReportsAFailedRun(): void
    {
        try {
            $this->face()->analyze('org/pack', $this->project->addFile('fail.jpg', 'broken'));
            self::fail('Expected RunFailedException.');
        } catch (RunFailedException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('cannot identify image file', $e->getMessage());
        }
    }

    public function testRejectsAMissingImage(): void
    {
        $this->expectException(ImageNotFoundException::class);

        $this->face()->analyze('org/pack', "{$this->project->root}/nowhere.jpg");
    }

    public function testRejectsAMissingSecondImage(): void
    {
        $this->expectException(ImageNotFoundException::class);

        $this->face()->compare('org/pack', $this->image, "{$this->project->root}/nowhere.jpg");
    }

    public function testRejectsAModelThatWasNotPulled(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->face()->analyze('org/missing', $this->image);
    }

    public function testRejectsAMissingBinary(): void
    {
        $project = new FakeProject();
        $project->addModel('org/pack', self::MODEL_FILES);

        try {
            (new Face($project->storage))->analyze('org/pack', $this->image);
            self::fail('Expected BinaryNotInstalledException.');
        } catch (BinaryNotInstalledException $e) {
            self::assertSame(Tool::Face, $e->tool);
        } finally {
            $project->remove();
        }
    }

    #[DataProvider('invalidOptions')]
    public function testRejectsInvalidOptions(\Closure $call, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $call($this->face(), 'org/pack', $this->image);
    }

    /**
     * @return iterable<string, array{\Closure, string}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'unknown bit count' => [
            static fn (Face $face, string $model, string $image): mixed => $face->analyze($model, $image, hashBits: 100),
            'A face id can have 64, 128, 256, 512 bits, not 100.',
        ];
        yield 'score above one' => [
            static fn (Face $face, string $model, string $image): mixed => $face->analyze($model, $image, minScore: 1.5),
            'A minimum score is a confidence, above 0 and at most 1',
        ];
        yield 'score of zero' => [
            static fn (Face $face, string $model, string $image): mixed => $face->analyze($model, $image, minScore: 0.0),
            'A minimum score is a confidence, above 0 and at most 1',
        ];
        yield 'no faces wanted' => [
            static fn (Face $face, string $model, string $image): mixed => $face->analyze($model, $image, maxFaces: 0),
            'The maxFaces must be a positive integer; got 0.',
        ];
        yield 'detection size below the floor' => [
            static fn (Face $face, string $model, string $image): mixed => $face->analyze($model, $image, detectionSize: 32),
            'The detection size must be at least 64 pixels; got 32.',
        ];
        yield 'threshold outside the cosine range' => [
            static fn (Face $face, string $model, string $image): mixed => $face->compare($model, $image, $image, threshold: 1.5),
            'A threshold is a cosine similarity, from -1 to 1; got 1.5.',
        ];
    }

    /**
     * @param list<string> $files
     */
    #[DataProvider('unsupportedModels')]
    public function testRejectsUnsupportedModel(array $files, string $reason): void
    {
        $this->project->addModel('org/unsupported', $files);

        try {
            $this->face()->analyze('org/unsupported', $this->image);
            self::fail('Expected UnsupportedModelException.');
        } catch (UnsupportedModelException $e) {
            self::assertStringStartsWith("org/unsupported cannot be used to recognize faces: {$reason}", $e->getMessage());
            self::assertStringEndsWith(
                'Use an InsightFace pack instead, e.g. immich-app/buffalo_l '
                . '(browse: https://huggingface.co/models?other=facial-recognition).',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function unsupportedModels(): iterable
    {
        yield 'a diffusers pipeline' => [
            ['model_index.json', 'unet/diffusion_pytorch_model.safetensors'],
            'it is an image generation model, made for text-to-image.',
        ];
        yield 'a transformers model' => [
            ['config.json', 'model.safetensors'],
            'it is a transformers model, not a face recognition pack.',
        ];
        yield 'only the detector' => [
            ['detection/model.onnx'],
            'it only has the detection half of a face pack (recognition/model.onnx is missing).',
        ];
        yield 'only the recognizer' => [
            ['recognition/model.onnx'],
            'it only has the recognition half of a face pack (detection/model.onnx is missing).',
        ];
        yield 'onnx laid out differently' => [
            ['model.onnx'],
            'its ONNX files are not laid out as a face pack',
        ];
        yield 'an empty directory' => [
            [],
            'it is not an InsightFace pack',
        ];
    }

    private function face(): Face
    {
        return new Face($this->project->storage);
    }
}
