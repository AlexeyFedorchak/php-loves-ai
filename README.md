# php-loves-ai

```
██████╗ ██╗  ██╗██████╗      ████ ████      █████╗ ██╗
██╔══██╗██║  ██║██╔══██╗    ███████████    ██╔══██╗██║
██████╔╝███████║██████╔╝    ███████████    ███████║██║
██╔═══╝ ██╔══██║██╔═══╝      █████████     ██╔══██║██║
██║     ██║  ██║██║            █████       ██║  ██║██║
╚═╝     ╚═╝  ╚═╝╚═╝              █         ╚═╝  ╚═╝╚═╝
```

**Run AI models locally from PHP.** Generate images, write and answer text, describe pictures, transcribe speech,
read text aloud, enlarge photos and make short videos — on your own machine, from PHP code or the command line,
with any matching model from Hugging Face.

| Task              | What it does                                      | Example model                          |
|-------------------|---------------------------------------------------|----------------------------------------|
| `text-to-image`   | Generate an image from a text prompt              | `stabilityai/sd-turbo`                 |
| `image-to-image`  | Enlarge a photo, or redraw it following a prompt  | `caidas/swin2SR-classical-sr-x2-64`    |
| `text-to-text`    | Answer a prompt, or continue it                   | `Qwen/Qwen2.5-0.5B-Instruct`           |
| `image-to-text`   | Describe an image, or answer questions about it   | `HuggingFaceTB/SmolVLM-256M-Instruct`  |
| `speech-to-text`  | Transcribe speech in audio or video               | `openai/whisper-tiny`                  |
| `text-to-speech`  | Read text aloud into an audio file                | `facebook/mms-tts-eng`                 |
| `text-to-video`   | Generate a video from a text prompt               | `Wan-AI/Wan2.1-T2V-1.3B-Diffusers`     |
| `image-to-video`  | Animate an image into a video                     | `stabilityai/stable-video-diffusion-img2vid-xt` |

## Easy to start

```bash
composer require php-loves-ai/multimodal-ai-runner

vendor/bin/loves-ai setup                  # one-time setup
vendor/bin/loves-ai setup text-to-image    # add the task you need

vendor/bin/loves-ai pull stabilityai/sd-turbo
vendor/bin/loves-ai text-to-image stabilityai/sd-turbo "a cozy cat by the fireplace" --steps=1 --guidance=0
```

```
🎨 Painting your image with stabilityai/sd-turbo… Masterpieces take a moment — perfect time for a hot chocolate and a cookie 🍪
🎉 Image saved to /Users/you/tmp/hugging-face/images/20260916-142501-a3f09c.png
```

The same from PHP:

```php
use PhpLovesAi\Runner\TextToImage;

$image = (new TextToImage())->generate(
    model: 'stabilityai/sd-turbo',
    prompt: 'a cozy cat by the fireplace',
    outputPath: storage_path('app/cat.png'),
    steps: 1,
    guidanceScale: 0.0,
);
```

Run `vendor/bin/loves-ai` on its own to see every task and which ones are ready to use.

**No Python, no API keys, no cloud.** Models run on your machine; nothing is sent anywhere. A Hugging Face key is only
needed for private or gated models.

## Requirements

- PHP 8.2+
- `proc_open` enabled (used to invoke the binaries)
- `allow_url_fopen` and the `openssl` extension (used by `setup` to download the binaries)
- `tar` on the `PATH` (built into macOS, Linux and Windows 10+)

Supported platforms: macOS arm64, Linux x86_64, Linux arm64, Windows x86_64.

## Installation

```bash
composer require php-loves-ai/multimodal-ai-runner
vendor/bin/loves-ai setup                  # downloads the puller (~17 MB) and asks for your Hugging Face API key
vendor/bin/loves-ai setup text-to-image    # optional: the image generation runner (a few hundred MB)
vendor/bin/loves-ai setup text-to-text     # optional: the text generation runner (a few hundred MB)
vendor/bin/loves-ai setup image-to-text    # optional: the image description runner (a few hundred MB)
vendor/bin/loves-ai setup speech-to-text   # optional: the speech transcription runner (a few hundred MB)
vendor/bin/loves-ai setup text-to-speech   # optional: the speech synthesis runner (a few hundred MB)
vendor/bin/loves-ai setup image-to-image   # optional: the image enlarging and redrawing runner (a few hundred MB)
vendor/bin/loves-ai setup text-to-video    # optional: the video generation runner (a few hundred MB)
vendor/bin/loves-ai setup image-to-video   # optional: the image animation runner (a few hundred MB)
```

```
🧰 Setting up php-loves-ai (v6.0.0) for darwin-arm64
🔑 Hugging Face API key (optional)
   Public models, like stabilityai/sd-turbo, are pulled without a key. Private and gated models need one:
   create it at https://huggingface.co/settings/tokens
   Paste your key (hidden), or press Enter to use public models only:
✅ Saved your Hugging Face API key to /var/www/my-app/.local/huggingface/credentials.json
✅ The puller is installed at /var/www/my-app/.local/runners/puller-darwin-arm64
🎉 All set! Happy hacking 🍪
👉 Pull a model: vendor/bin/loves-ai pull <model>
```

### Commands

Everything runs through one command, `vendor/bin/loves-ai`, so nothing in `vendor/bin` clashes with other packages.
Running it without arguments lists the tasks and marks the runners that are installed:

```
🧰 php-loves-ai v6.0.0 — run small AI models locally, without installing Python

Usage: vendor/bin/loves-ai <command> [arguments] [options]

     setup           Download the runner binaries for this computer
     pull            Pull a model from the Hugging Face Hub

Tasks (✅ = runner installed, run `setup <task>` for the others):
  ✅ text-to-image   Generate an image from a text prompt
     image-to-image  Enlarge an image, or redraw it following a prompt
     text-to-video   Generate a video from a text prompt
     image-to-video  Animate an image into a video
  ✅ text-to-text    Generate text: answer a prompt or continue it
     image-to-text   Describe an image, or answer a question about it
     speech-to-text  Transcribe speech in an audio or video file
     text-to-speech  Read text aloud into an audio file

Run 'vendor/bin/loves-ai <command> --help' for a command's arguments and options.
```

`vendor/bin/loves-ai --version` prints the installed package version.

### Hugging Face API key

A key is optional. Public models are pulled without one; private and gated models need it
(create one at https://huggingface.co/settings/tokens).

`setup` asks for it once and saves the answer in `.local/huggingface/credentials.json`, readable only by its owner.
Pressing Enter is remembered too, so `setup` does not ask again. To save or replace a key later, run
`vendor/bin/loves-ai setup --token=hf_...` or pass `--token=hf_...` to `pull`. When there is no terminal to ask in (Docker
builds, CI, deploy scripts), `setup` skips the question; pass `--token` there if you need a key.

The key is never read from environment variables, so every process running the project uses the same one.

### Where models and runners live

Everything is stored inside your project, next to `vendor/`, in one fixed place:

```
<project root>/.local/
├── models/        models pulled by `vendor/bin/loves-ai pull`, as models/<model id>
├── runners/       binaries installed by `vendor/bin/loves-ai setup`
└── huggingface/   credentials.json with your Hugging Face API key
```

These paths cannot be changed. They depend only on the project directory, never on the user, `HOME`, the working
directory or environment variables. So the CLI, the web server (php-fpm running as `www-data`), queue workers and other
containers sharing the project directory all find the same models and runners, and no code or config ever needs a path.
On a server or in Docker, run `setup` and `pull` once in the project and forget about it. The web server's user needs
read and execute access to `.local`.

`setup` and `pull` report the path they installed to, and add a `.gitignore` to `.local`, so its hundreds of MB and
your API key are never committed. Add `.local/` to `.dockerignore` if you build images from the project directory.

`setup --force` re-downloads, and `setup --debug` shows the URLs and paths used. After upgrading the package, run
`vendor/bin/loves-ai setup --force` (plus `setup text-to-image --force` if you use it) to get the matching binaries.

Running a command before its binary is installed fails with `The puller is not installed yet.` and a hint to run
`setup`.

## Pulling models

Public models need no API key. Private and gated models use the key saved in the project (see
[Hugging Face API key](#hugging-face-api-key)).

### From the command line

```bash
vendor/bin/loves-ai pull openai-community/gpt2 [--revision=main] [--token=hf_...] [--all] [--log-file=PATH] [--debug]
```

```
☕ Pulling openai-community/gpt2… Big downloads take a moment — perfect time for a cup of tea and some cookies 🍪
If you wish to see all logs, re-run the command with the "--debug" option.
🎉 Pulled openai-community/gpt2 into /var/www/my-app/.local/models/openai-community/gpt2
   Skipped 3 files (450.0 MB) the runners cannot read: other frameworks or training leftovers.
```

### Only the files the runners can read

Hugging Face repositories usually publish the same weights several times over, for PyTorch, TensorFlow, Flax and ONNX.
The runners read PyTorch only, so everything else is downloaded for nothing. `pull` leaves those files out:

| Model                                  | Repository | Pulled   |
|----------------------------------------|-----------:|---------:|
| `openai/whisper-tiny`                  |    0.61 GB | **0.16 GB** |
| `SfinOe/stable-diffusion-v1.5`         |   10.96 GB | **5.48 GB** |
| `facebook/mms-tts-eng`                 |    0.29 GB | **0.15 GB** |
| `nlpconnect/vit-gpt2-image-captioning` |    0.98 GB | 0.98 GB  |

Skipped are weights for other frameworks (`.h5`, `.msgpack`, `.onnx`, `.gguf`, `.ckpt`), single-file copies of a
diffusers pipeline, alternative versions such as `fp16` and `non_ema`, leftovers from training (`optimizer.pt`,
`trainer_state.json`, `checkpoint-*`) and sample media. A `.bin` file is skipped **only** when the same repository
also publishes it as `.safetensors`, so models that ship `.bin` alone — the last row above — are pulled untouched.

Pass `--all` to download the repository as it is, if a model ever needs a file these rules leave out.

The opening message is picked at random from a few cozy variants (see `PullCommand::INTROS`).

When Hugging Face refuses a model, `pull` explains why and what to do:

```
Error: meta-llama/Llama-3.2-1B is not available: it is a gated model, which needs a Hugging Face API key.
Re-run with your key: vendor/bin/loves-ai pull meta-llama/Llama-3.2-1B --token=<your Hugging Face API key> (create one at https://huggingface.co/settings/tokens)
```

`--token` saves the key for next time. A gated model also needs its terms accepted on its Hugging Face page, with the
account the key belongs to.

Pulls one model at a time into `.local/models/<model id>`. The puller's own output is hidden unless `--debug` is given.
To keep that output, set a log file — each run is appended to it with a timestamp, the puller's output and the
outcome. Output is colored on terminals; set `NO_COLOR=1` to disable colors.
Exit codes: `0` success, `1` pull failed, `2` invalid usage. Run `vendor/bin/loves-ai pull --help` for details.

Defaults come from `config/pull.php`:

| Key        | Default                   | Overridden by |
|------------|---------------------------|---------------|
| `revision` | `main`                    | `--revision`  |
| `log_file` | `null` (output discarded) | `--log-file`  |

### From PHP

```php
use PhpLovesAi\Process\ModelPuller;

$paths = (new ModelPuller())->pull(
    ['openai-community/gpt2', 'distilbert/distilbert-base-uncased'],
    onProgress: fn (string $chunk) => fwrite(STDERR, $chunk),
    // allFiles: true,  // download every file, as `pull --all` does
    onSkipped: fn (string $model, int $files, int $bytes) => fwrite(STDERR, "{$model}: skipped {$files} files\n"),
);
// ['openai-community/gpt2' => '/var/www/my-app/.local/models/openai-community/gpt2', ...]
```

Failures throw exceptions implementing `PhpLovesAi\Exception\PhpLovesAiException`. A model Hugging Face refuses
throws `ModelAccessDeniedException` (`$model`, `$reason`: `gated` or `not_found`, `$apiKeyUsed`); other failures throw
`PullFailedException`. Both list the models pulled before the failure in `$pulled`. To save a key from PHP, use
`(new PhpLovesAi\HuggingFace\Credentials())->saveApiKey('hf_...')`.

The binary can also be used directly:

```bash
[HUGGING_FACE_API_KEY=hf_...] .local/runners/puller-darwin-arm64 --dir .local/models [--revision main] [--all] -- openai-community/gpt2
```

It writes one JSON line per model to stdout, `{"model": "...", "path": "...", "skipped_files": 3, "skipped_bytes": 471859200}` when pulled or
`{"model": "...", "error": "gated|not_found"}` when refused, and progress to stderr.
Exit codes: `0` success, `1` at least one model failed, `2` invalid arguments.

## Generating images

Pull a complete [Diffusers text-to-image model](https://huggingface.co/models?pipeline_tag=text-to-image&library=diffusers)
first, such as `stabilityai/sd-turbo`. Its repository has a `model_index.json`. Add-ons like embeddings or LoRAs, and
single-file checkpoints, cannot be used on their own: the runner rejects them with an explanation before starting.

Then:

### From the command line

```bash
vendor/bin/loves-ai pull stabilityai/sd-turbo
vendor/bin/loves-ai text-to-image stabilityai/sd-turbo "a cozy cat by the fireplace" --steps=1 --guidance=0
```

```
🎨 Painting your image with stabilityai/sd-turbo… Masterpieces take a moment — perfect time for a hot chocolate and a cookie 🍪
If you wish to see all logs, re-run the command with the "--debug" option.
🎉 Image saved to /Users/you/tmp/hugging-face/images/20260915-142501-a3f09c.png
```

| Option                   | Meaning                                                             |
|--------------------------|---------------------------------------------------------------------|
| `--output=PATH`          | Image file to write (default: timestamped `.png` in `output_dir`)   |
| `--negative-prompt=TEXT` | What the image should not contain                                   |
| `--steps=N`              | Inference steps (default: the model's own)                          |
| `--guidance=SCALE`       | Guidance scale; turbo models use `0` (default: the model's own)     |
| `--width=PX`, `--height=PX` | Image size (default: the model's native size)                    |
| `--seed=N`               | Random seed, for reproducible images                                |
| `--device=DEVICE`        | `cpu`, `cuda`, `mps`… (default: the best available)                 |
| `--log-file=PATH`        | Append the runner's output to this file                             |
| `--debug`                | Show the runner's output while generating                           |

Values are passed to the model as-is — one it cannot handle (a prompt that is too long, an unsupported size…)
makes the run fail. Defaults come from `config/text-to-image.php` (`output_dir`, `log_file`).

### From PHP

```php
use PhpLovesAi\Runner\TextToImage;

// Finds the runner and the pulled model in the project's .local directory by itself.
$image = (new TextToImage())->generate(
    model: 'stabilityai/sd-turbo',
    prompt: 'a cozy cat by the fireplace',
    outputPath: __DIR__ . '/cat.png',
    steps: 1,
    guidanceScale: 0.0,
);
// '/.../cat.png'
```

Throws `BinaryNotInstalledException` when `setup text-to-image` has not been run, `ModelNotFoundException` when the
model was not pulled yet, `UnsupportedModelException` when the pulled model is not a complete Diffusers text-to-image
model, and `RunFailedException` (with the runner's error output) when generation fails.

## Enlarging and redrawing images

Two kinds of [image-to-image models](https://huggingface.co/models?pipeline_tag=image-to-image) work, and the runner
tells them apart by the model's own files:

- **Upscaling models**, e.g. `caidas/swin2SR-classical-sr-x2-64` (2× larger) or
  `caidas/swin2SR-realworld-sr-x4-64-bsrgan-psnr` (4×). They enlarge a photo and clean it up, and take no prompt.
  This is the one to use for making images bigger than a plain resize can.
- **Diffusers image-to-image pipelines**, e.g. `stabilityai/sd-turbo` or `timbrooks/instruct-pix2pix`. They redraw the
  image following a prompt, keeping more or less of the original depending on `--strength`.

(For plain resizing to a smaller size, PHP's own GD or Imagick extension is faster and needs no model.)

### From the command line

```bash
vendor/bin/loves-ai pull caidas/swin2SR-classical-sr-x2-64
vendor/bin/loves-ai image-to-image caidas/swin2SR-classical-sr-x2-64 photo.jpg --output=photo-2x.png

vendor/bin/loves-ai pull stabilityai/sd-turbo
vendor/bin/loves-ai image-to-image stabilityai/sd-turbo photo.jpg --prompt="a watercolor painting" --strength=0.6 --steps=2 --guidance=0
```

```
🔎 Making it bigger and better with caidas/swin2SR-classical-sr-x2-64… How about a hot chocolate while you wait? ☕
If you wish to see all logs, re-run the command with the "--debug" option.
🎉 Image saved to /var/www/my-app/photo-2x.png
```

| Option                   | Meaning                                                                        |
|--------------------------|---------------------------------------------------------------------------------|
| `--output=PATH`          | Image file to write (default: a timestamped `.png` in `output_dir`)             |
| `--prompt=TEXT`          | What the result should look like; needed by diffusers models, refused by upscaling models |
| `--negative-prompt=TEXT` | What the result should not contain (diffusers models)                           |
| `--strength=N`           | How much of the original to keep, 0 to 1; higher changes more (diffusers models) |
| `--steps=N`              | Inference steps (default: the pipeline's own)                                   |
| `--guidance=SCALE`       | Guidance scale; turbo models use `0` (default: the pipeline's own)              |
| `--seed=N`               | Random seed, for reproducible images                                            |
| `--device=DEVICE`        | `cpu`, `cuda`, `mps`… (default: the best available)                             |
| `--log-file=PATH`        | Append the runner's output to this file                                         |
| `--debug`                | Show the runner's output while working                                          |

Defaults come from `config/image-to-image.php` (`output_dir`, `log_file`).

### From PHP

```php
use PhpLovesAi\Runner\ImageToImage;

// Finds the runner and the pulled model in the project's .local directory by itself.
$imageToImage = new ImageToImage();

$bigger = $imageToImage->transform(
    model: 'caidas/swin2SR-classical-sr-x2-64',
    imagePath: storage_path('app/photo.jpg'),
    outputPath: storage_path('app/photo-2x.png'),
);

$painting = $imageToImage->transform(
    model: 'stabilityai/sd-turbo',
    imagePath: storage_path('app/photo.jpg'),
    outputPath: storage_path('app/painting.png'),
    prompt: 'a watercolor painting',
    strength: 0.6,
    steps: 2,
    guidanceScale: 0.0,
);
```

Throws `ImageNotFoundException` when the image does not exist, `BinaryNotInstalledException` when
`setup image-to-image` has not been run, `ModelNotFoundException` when the model was not pulled yet,
`UnsupportedModelException` when the model does not produce images, and `RunFailedException` (with the runner's error
output) when the run fails, e.g. when a prompt is missing or given to a model that takes none.

Upscaling works on the whole image at once, so memory use grows with the picture: a large photo can need several GB.
Enlarging in a queue job, and shrinking very large photos first, keeps web requests safe.

## Generating text

Pull a [transformers text generation model](https://huggingface.co/models?pipeline_tag=text-generation&library=transformers)
first. Both kinds of text models work:

- **Chat and completion models** (task `text-generation`), e.g. `Qwen/Qwen2.5-0.5B-Instruct` or
  `HuggingFaceTB/SmolLM2-360M-Instruct`. Chat models get the prompt wrapped in their chat template, so they answer it;
  other models continue it.
- **Encoder-decoder models** (task `text2text-generation`), e.g. `google/flan-t5-base`.

The runner rejects models it cannot load before starting, with an explanation: GGUF files (made for llama.cpp and
Ollama), ONNX-only repositories, LoRA adapters, image models, and models that need their own Python code
(`trust_remote_code`), which it never runs.

### From the command line

```bash
vendor/bin/loves-ai pull Qwen/Qwen2.5-0.5B-Instruct
vendor/bin/loves-ai text-to-text Qwen/Qwen2.5-0.5B-Instruct "Write a haiku about PHP." --system="You are a poet."
```

```
✍️ Writing with Qwen/Qwen2.5-0.5B-Instruct… Good words take a moment — perfect time for a cup of tea and a cookie 🍪
If you wish to see all logs, re-run the command with the "--debug" option.
🎉 Qwen/Qwen2.5-0.5B-Instruct wrote:
<the generated haiku>
```

| Option                 | Meaning                                                                  |
|------------------------|--------------------------------------------------------------------------|
| `--system=TEXT`        | Instructions for chat models, e.g. `"You are a helpful assistant."`      |
| `--max-new-tokens=N`   | Maximum length of the answer in tokens (default: 256)                    |
| `--temperature=T`      | Randomness: `0` always picks the likeliest words (default: the model's own) |
| `--top-p=P`            | Nucleus sampling probability, e.g. `0.9` (default: the model's own)      |
| `--seed=N`             | Random seed, for reproducible text                                       |
| `--device=DEVICE`      | `cpu`, `cuda`, `mps`… (default: the best available)                      |
| `--log-file=PATH`      | Append the runner's output to this file                                  |
| `--debug`              | Show the runner's output, and the text as it is written                  |

Defaults come from `config/text-to-text.php` (`log_file`).

### From PHP

```php
use PhpLovesAi\Runner\TextToText;

// Finds the runner and the pulled model in the project's .local directory by itself.
$answer = (new TextToText())->generate(
    model: 'Qwen/Qwen2.5-0.5B-Instruct',
    prompt: 'Summarize in one sentence: PHP is a popular general-purpose scripting language...',
    systemPrompt: 'You are a concise assistant.',
    maxNewTokens: 100,
    temperature: 0.0,
);
```

Throws `BinaryNotInstalledException` when `setup text-to-text` has not been run, `ModelNotFoundException` when the
model was not pulled yet, `UnsupportedModelException` when the model is not a transformers text model, and
`RunFailedException` (with the runner's error output) when generation fails.

Small models run on CPU, but larger ones get slow quickly: a 0.5B model writes a few words per second on a laptop CPU,
and each run loads the model from disk again. Run generation in a queue job rather than in a web request.

## Describing images

Pull a [transformers image-to-text model](https://huggingface.co/models?pipeline_tag=image-text-to-text&library=transformers)
first. Both kinds work:

- **Vision-language models** (task `image-text-to-text`), e.g. `HuggingFaceTB/SmolVLM-256M-Instruct` or
  `HuggingFaceTB/SmolVLM-500M-Instruct`. They answer a question about the image; without one, they describe it.
- **Captioning models** (task `image-to-text`), e.g. `Salesforce/blip-image-captioning-base` or
  `nlpconnect/vit-gpt2-image-captioning`. They write a short caption; a prompt is the start of the caption, e.g.
  `"a photography of"`.

As with text models, the runner rejects models it cannot load before starting, with an explanation: text-only models,
GGUF files, ONNX-only repositories and models that need their own Python code (such as Florence-2 or Moondream).

### From the command line

```bash
vendor/bin/loves-ai pull HuggingFaceTB/SmolVLM-256M-Instruct
vendor/bin/loves-ai image-to-text HuggingFaceTB/SmolVLM-256M-Instruct photo.jpg "What are the animals doing?"
```

```
👀 Studying your picture with HuggingFaceTB/SmolVLM-256M-Instruct… Grab a warm drink while it finds the right words ☕
If you wish to see all logs, re-run the command with the "--debug" option.
🎉 HuggingFaceTB/SmolVLM-256M-Instruct says:
The animals are sleeping.
```

| Option                 | Meaning                                                                  |
|------------------------|--------------------------------------------------------------------------|
| `--max-new-tokens=N`   | Maximum length of the text in tokens (default: 256)                      |
| `--temperature=T`      | Randomness: `0` always picks the likeliest words (default: the model's own) |
| `--seed=N`             | Random seed, for reproducible text                                       |
| `--device=DEVICE`      | `cpu`, `cuda`, `mps`… (default: the best available)                      |
| `--log-file=PATH`      | Append the runner's output to this file                                  |
| `--debug`              | Show the runner's output, and the text as it is written                  |

Defaults come from `config/image-to-text.php` (`log_file`).

### From PHP

```php
use PhpLovesAi\Runner\ImageToText;

// Finds the runner and the pulled model in the project's .local directory by itself.
$imageToText = new ImageToText();

$description = $imageToText->generate('HuggingFaceTB/SmolVLM-256M-Instruct', storage_path('app/photo.jpg'));

$answer = $imageToText->generate(
    model: 'HuggingFaceTB/SmolVLM-256M-Instruct',
    imagePath: storage_path('app/photo.jpg'),
    prompt: 'Is there any text in this image? Write it out.',
    maxNewTokens: 100,
    temperature: 0.0,
);
```

Throws `ImageNotFoundException` when the image file does not exist, `BinaryNotInstalledException` when
`setup image-to-text` has not been run, `ModelNotFoundException` when the model was not pulled yet,
`UnsupportedModelException` when the model cannot read images, and `RunFailedException` (with the runner's error
output) when generation fails, e.g. because the file is not an image.

## Transcribing speech

Pull a [transformers speech recognition model](https://huggingface.co/models?pipeline_tag=automatic-speech-recognition&library=transformers)
first. Both kinds work:

- **Whisper-style models**, e.g. `openai/whisper-tiny`, `openai/whisper-base` or `openai/whisper-small` (larger is more
  accurate and slower). They are multilingual: they detect the spoken language, can be told it, and can translate the
  speech into English.
- **CTC models**, e.g. `facebook/wav2vec2-base-960h`. They transcribe the one language they were trained on.

The runner decodes audio itself, with no FFmpeg installation needed: WAV, MP3, M4A/AAC, FLAC, OGG/Opus and the audio
track of video files work, at any length. It rejects models it cannot load before starting, with an explanation,
including whisper.cpp (GGML) and faster-whisper (CTranslate2) conversions, which are common on Hugging Face.

### From the command line

```bash
vendor/bin/loves-ai pull openai/whisper-tiny
vendor/bin/loves-ai speech-to-text openai/whisper-tiny interview.m4a
vendor/bin/loves-ai speech-to-text openai/whisper-tiny interview.m4a --timestamps
```

```
🎧 Listening carefully with openai/whisper-tiny… Perfect time for a cup of tea and a cookie 🍪
If you wish to see all logs, re-run the command with the "--debug" option.
🎉 Transcript by openai/whisper-tiny:
[00:00.00 → 00:05.56] He hoped there would be stew for dinner, turnips and carrots and bruised potatoes and fat
[00:05.56 → 00:11.04] mutton pieces to be ladled out in thick peppered flower fatten sauce.
```

| Option                  | Meaning                                                                        |
|-------------------------|--------------------------------------------------------------------------------|
| `--language=LANGUAGE`   | Spoken language for Whisper-style models, e.g. `en` or `french` (default: detected) |
| `--translate`           | Translate the speech into English (Whisper-style models)                       |
| `--timestamps`          | Print when each segment is spoken: phrases for Whisper-style models, words for CTC models |
| `--device=DEVICE`       | `cpu`, `cuda`, `mps`… (default: the best available)                            |
| `--log-file=PATH`       | Append the runner's output to this file                                        |
| `--debug`               | Show the runner's output while transcribing                                    |

Defaults come from `config/speech-to-text.php` (`log_file`).

### From PHP

```php
use PhpLovesAi\Runner\SpeechToText;

// Finds the runner and the pulled model in the project's .local directory by itself.
$speechToText = new SpeechToText();

$transcript = $speechToText->transcribe('openai/whisper-tiny', storage_path('app/interview.m4a'));

$english = $speechToText->transcribe(
    model: 'openai/whisper-small',
    audioPath: storage_path('app/interview-uk.mp3'),
    language: 'uk',
    translate: true,
);

// For subtitles: seconds from the start of the audio; the last segment may have no end.
$segments = $speechToText->transcribeWithTimestamps('openai/whisper-tiny', storage_path('app/interview.m4a'));
// [['start' => 0.0, 'end' => 5.56, 'text' => 'He hoped there would be stew for dinner, …'], ...]
```

Throws `AudioNotFoundException` when the file does not exist, `BinaryNotInstalledException` when `setup speech-to-text`
has not been run, `ModelNotFoundException` when the model was not pulled yet, `UnsupportedModelException` when the model
cannot transcribe speech (or cannot be told a language or translate), and `RunFailedException` (with the runner's error
output) when transcription fails, e.g. because the file has no audio.

Small models (`whisper-tiny`, `whisper-base`) are quick; larger models and long recordings take a while, especially on
CPU, and each run loads the model from disk again, so run transcription in a queue job.

## Reading text aloud

Pull a [transformers text-to-speech model](https://huggingface.co/models?pipeline_tag=text-to-speech&library=transformers)
first, one that needs nothing but text:

- **VITS and MMS models**, e.g. `facebook/mms-tts-eng` (one repository per language, such as `mms-tts-deu` or
  `mms-tts-ukr`) or `kakao-enterprise/vits-ljs`. They are small and fast.
- **Bark**, e.g. `suno/bark-small`, which has named voices such as `v2/en_speaker_6`, chosen with `--voice`.

Models that need extra files or their own Python code are rejected before starting, with an explanation: SpeechT5
(which needs a speaker embedding file), Kokoro and Parler-TTS (which ship their own code), and speech recognition
models given to the wrong runner.

### From the command line

```bash
vendor/bin/loves-ai pull facebook/mms-tts-eng
vendor/bin/loves-ai text-to-speech facebook/mms-tts-eng "PHP loves AI, and now it can speak." --output=hello.wav
```

```
🎵 Turning your words into sound with facebook/mms-tts-eng… How about a hot chocolate while you wait? ☕
If you wish to see all logs, re-run the command with the "--debug" option.
🎉 Audio saved to /var/www/my-app/hello.wav
```

| Option             | Meaning                                                                                 |
|--------------------|------------------------------------------------------------------------------------------|
| `--output=PATH`    | Audio file to write; its extension picks the format: `.wav`, `.mp3`, `.m4a`, `.flac`, `.ogg` (default: a timestamped `.wav` in `output_dir`) |
| `--voice=VOICE`    | Voice of models that have several, e.g. a Bark preset like `v2/en_speaker_6`, or a speaker number |
| `--speed=RATE`     | Speaking rate of VITS-style models, e.g. `0.8` slower, `1.2` faster (default: the model's own) |
| `--seed=N`         | Random seed, for reproducible audio                                                      |
| `--device=DEVICE`  | `cpu`, `cuda`, `mps`… (default: the best available)                                      |
| `--log-file=PATH`  | Append the runner's output to this file                                                  |
| `--debug`          | Show the runner's output while speaking                                                  |

Defaults come from `config/text-to-speech.php` (`output_dir`, `log_file`).

### From PHP

```php
use PhpLovesAi\Runner\TextToSpeech;

// Finds the runner and the pulled model in the project's .local directory by itself.
$file = (new TextToSpeech())->speak(
    model: 'facebook/mms-tts-eng',
    text: 'PHP loves AI, and now it can speak.',
    outputPath: storage_path('app/hello.mp3'),
    speed: 0.9,
);
// '/var/www/my-app/storage/app/hello.mp3'
```

Throws `BinaryNotInstalledException` when `setup text-to-speech` has not been run, `ModelNotFoundException` when the
model was not pulled yet, `UnsupportedModelException` when the model cannot speak, and `RunFailedException` (with the
runner's error output) when generation fails, e.g. for an output format it cannot write.

Small voice models speak a sentence in a second or two on a laptop CPU, but each run loads the model again, so run
longer texts in a queue job.

## Generating videos

Pull a [diffusers video model](https://huggingface.co/models?pipeline_tag=text-to-video&library=diffusers) first, e.g.
`Wan-AI/Wan2.1-T2V-1.3B-Diffusers`, `zai-org/CogVideoX-2b` or an AnimateDiff pipeline.

**Video models are the heaviest thing here.** They are several gigabytes to pull, want a lot of memory, and a few
seconds of video takes minutes on a GPU and up to hours on a CPU. Start with the smallest model, few frames and a small
frame size, and always generate in a queue job.

### From the command line

```bash
vendor/bin/loves-ai pull Wan-AI/Wan2.1-T2V-1.3B-Diffusers
vendor/bin/loves-ai text-to-video Wan-AI/Wan2.1-T2V-1.3B-Diffusers "a cat walking through tall grass" --frames=33 --output=cat.mp4
```

```
🎬 Rolling the camera with Wan-AI/Wan2.1-T2V-1.3B-Diffusers… Films take their time — perfect for a pot of tea and some cookies 🍪
If you wish to see all logs, re-run the command with the "--debug" option.
🎉 Video saved to /var/www/my-app/cat.mp4
```

| Option                      | Meaning                                                                   |
|-----------------------------|---------------------------------------------------------------------------|
| `--output=PATH`             | Video file to write; its extension picks the format: `.mp4`, `.webm`, `.mkv`, `.gif` (default: a timestamped `.mp4` in `output_dir`) |
| `--negative-prompt=TEXT`    | What the video should not contain                                         |
| `--frames=N`                | Number of frames to generate (default: the pipeline's own)                |
| `--fps=N`                   | Frames per second of the written file (default: 8)                        |
| `--steps=N`                 | Inference steps (default: the pipeline's own)                             |
| `--guidance=SCALE`          | Guidance scale (default: the pipeline's own)                              |
| `--width=PX`, `--height=PX` | Frame size (default: the pipeline's own)                                  |
| `--seed=N`                  | Random seed, for reproducible videos                                      |
| `--device=DEVICE`           | `cpu`, `cuda`, `mps`… (default: the best available)                       |
| `--log-file=PATH`           | Append the runner's output to this file                                   |
| `--debug`                   | Show the runner's output while filming                                    |

Defaults come from `config/text-to-video.php` (`output_dir`, `log_file`).

### From PHP

```php
use PhpLovesAi\Runner\TextToVideo;

// Finds the runner and the pulled model in the project's .local directory by itself.
$clip = (new TextToVideo())->generate(
    model: 'Wan-AI/Wan2.1-T2V-1.3B-Diffusers',
    prompt: 'a cat walking through tall grass',
    outputPath: storage_path('app/cat.mp4'),
    frames: 33,
    fps: 16,
);
```

Throws `BinaryNotInstalledException` when `setup text-to-video` has not been run, `ModelNotFoundException` when the
model was not pulled yet, `UnsupportedModelException` when the model is not a diffusers video pipeline (a still-image
pipeline says so and points to `text-to-image`), and `RunFailedException` (with the runner's error output) when
generation fails, e.g. for an output format it cannot write or when memory runs out.

## Animating images

Pull a [diffusers image-to-video model](https://huggingface.co/models?pipeline_tag=image-to-video&library=diffusers)
first. They differ in what they take:

- **Image only**, e.g. `stabilityai/stable-video-diffusion-img2vid-xt`. It animates the picture on its own and refuses
  a prompt.
- **Image and prompt**, e.g. `Wan-AI/Wan2.1-I2V-14B-480P-Diffusers`, `zai-org/CogVideoX-5b-I2V` or LTX-Video. The
  prompt says what should happen in the clip.

A model whose repository only ships its text-to-video pipeline is converted to the image-to-video pipeline of the same
family automatically, when diffusers has one.

The same warning as for text-to-video applies: these models are large and slow, so generate in a queue job.

### From the command line

```bash
vendor/bin/loves-ai pull stabilityai/stable-video-diffusion-img2vid-xt
vendor/bin/loves-ai image-to-video stabilityai/stable-video-diffusion-img2vid-xt photo.jpg --output=clip.mp4
```

```
🎬 Bringing your picture to life with stabilityai/stable-video-diffusion-img2vid-xt… Films take their time — perfect for a pot of tea and some cookies 🍪
If you wish to see all logs, re-run the command with the "--debug" option.
🎉 Video saved to /var/www/my-app/clip.mp4
```

The options are the same as `text-to-video`, plus `--prompt=TEXT` for models that accept one. Defaults come from
`config/image-to-video.php` (`output_dir`, `log_file`).

### From PHP

```php
use PhpLovesAi\Runner\ImageToVideo;

// Finds the runner and the pulled model in the project's .local directory by itself.
$clip = (new ImageToVideo())->generate(
    model: 'Wan-AI/Wan2.1-I2V-14B-480P-Diffusers',
    imagePath: storage_path('app/photo.jpg'),
    outputPath: storage_path('app/clip.mp4'),
    prompt: 'the camera slowly zooms out',
    frames: 33,
    fps: 16,
);
```

Throws `ImageNotFoundException` when the image does not exist, `BinaryNotInstalledException` when
`setup image-to-video` has not been run, `ModelNotFoundException` when the model was not pulled yet,
`UnsupportedModelException` when the model is not a diffusers video pipeline, and `RunFailedException` when the run
fails, e.g. when the model takes no prompt but one was given.

## How it works

The Composer package is tiny and holds only PHP code; the heavy parts live outside of it:

1. **Puller binary** — a Python program that downloads models from Hugging Face and saves them in the project.
2. **Runner binaries** — one per task, each holding its own copy of PyTorch and the libraries that task needs. Within a
   task, one runner serves many models: diffusers and transformers pick the right architecture from the model's own
   files, so new models work without a new release.
3. Both are compiled with PyInstaller into standalone programs, built per platform by GitHub Actions and attached to
   each GitHub release. `setup` downloads the ones matching the current OS, and the other commands find them
   automatically. **Nothing needs Python installed.**
4. You choose which models to pull; weights never travel through Composer.
5. The PHP classes run those programs through Symfony Process and give you a plain, typed API.

## Releasing binaries

Publishing a GitHub release runs `.github/workflows/release-binaries.yml`, which builds every binary on each supported
platform and attaches `<tool>-<os>-<arch>.tar.gz` plus a `.sha256` checksum to the release. `setup` downloads from the
release matching the installed package version (development installs use the latest release). The workflow can also
be started by hand for an existing tag.

To build and pack locally (PyInstaller does not cross-compile, so this covers the current platform only):

```bash
python/puller/build.sh                    # → python/puller/dist/puller-<os>-<arch>
python/runners/text-to-image/build.sh     # → python/runners/text-to-image/dist/text-to-image-<os>-<arch>/
python/runners/text-to-text/build.sh      # → python/runners/text-to-text/dist/text-to-text-<os>-<arch>/
python/runners/image-to-text/build.sh     # → python/runners/image-to-text/dist/image-to-text-<os>-<arch>/
python/runners/speech-to-text/build.sh    # → python/runners/speech-to-text/dist/speech-to-text-<os>-<arch>/
python/runners/text-to-speech/build.sh    # → python/runners/text-to-speech/dist/text-to-speech-<os>-<arch>/
python/runners/image-to-image/build.sh    # → python/runners/image-to-image/dist/image-to-image-<os>-<arch>/
python/runners/text-to-video/build.sh     # → python/runners/text-to-video/dist/text-to-video-<os>-<arch>/
python/runners/image-to-video/build.sh    # → python/runners/image-to-video/dist/image-to-video-<os>-<arch>/
python/package.sh                         # → python/release/*.tar.gz + *.sha256
```

Linux binaries can be built from any Docker host (including a Mac): `python/build-in-docker.sh linux/amd64` or
`python/build-in-docker.sh linux/arm64` builds and packs them on an old glibc base, so they run on Debian 11+, Ubuntu
20.04+ and RHEL 9+. The release workflow uses the same script.

The runners bundle torch (plus diffusers or transformers, torchvision for image-to-text and PyAV with FFmpeg's
libraries for the audio runners), so they are built as directories (~600–700 MB, ~210–220 MB packed) rather than single files; Linux builds use CPU-only torch to stay within GitHub's release asset size limit.
To test `setup` against local assets, serve `python/release` over HTTP and set `PHP_LOVES_AI_DOWNLOAD_URL` to its URL.

## Structure

```
bin/                 loves-ai, the only script exposed via vendor/bin
config/              Package config (pull.php and one file per runner)
python/              Python sources compiled into standalone binaries (not shipped via Composer)
  puller/            Pulls models from Hugging Face and saves them locally
  runners/           One runner per task, running locally saved models
    text-to-image/   Generates images with diffusers models
    image-to-image/  Enlarges images, or redraws them with diffusers models
    text-to-video/   Generates videos with diffusers pipelines
    image-to-video/  Animates images with diffusers pipelines
    text-to-text/    Generates text with transformers models
    image-to-text/   Describes images with transformers models
    speech-to-text/  Transcribes speech with transformers models
    text-to-speech/  Reads text aloud with transformers models
src/
  Enum/              Model registry
  Binary/            Platform detection and installing (Installer) the prebuilt binaries
  Config/            Config loading and validation
  Console/           Application (the loves-ai command) and one Command per subcommand
  Filesystem/        LocalStorage (the fixed paths inside .local) and path helpers
  HuggingFace/       Credentials: the optional API key saved in .local/huggingface/credentials.json
  Process/           PHP wrapper that invokes the puller binary (ModelPuller)
  Runner/            One class per task running pulled models (TextToImage, ImageToImage, TextToVideo, ImageToVideo,
                     TextToText, ImageToText, SpeechToText, TextToSpeech), sharing the Runner interface
  Exception/         Package exceptions
tests/
  Unit/
  Integration/
```

## License

MIT
