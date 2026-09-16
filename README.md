# php-loves-ai

Run small Hugging Face AI models locally from PHP — no Python installation required.

## How it works

The Composer package itself is tiny and contains only PHP code. The heavy parts live outside of it:

1. **Puller binary** — a Python script compiled with PyInstaller that pulls models from Hugging Face and saves them locally.
2. **Runner binaries** — one per task (text-to-image, text-to-text, image-to-text, speech-to-text, text-to-speech),
   each compiled with PyInstaller. A runner loads a
   locally saved model and runs it; within a task one runner serves many models (diffusers and transformers pick the
   right architecture from the model's own config), while tasks get separate binaries because their dependencies differ.
3. The binaries are built per platform by GitHub Actions and attached to each GitHub release.
   `vendor/bin/setup` downloads the ones matching the current OS; the other commands find them automatically.
4. The user chooses which models to pull; weights are never shipped through Composer.
5. PHP code calls the binaries via Symfony Process and exposes a fluent, native-feeling API.

## Requirements

- PHP 8.2+
- `proc_open` enabled (used to invoke the binaries)
- `allow_url_fopen` and the `openssl` extension (used by `setup` to download the binaries)
- `tar` on the `PATH` (built into macOS, Linux and Windows 10+)

Supported platforms: macOS arm64, Linux x86_64, Linux arm64, Windows x86_64.

## Installation

```bash
composer require php-loves-ai/php-loves-ai
vendor/bin/setup                  # downloads the puller (~17 MB) and asks for your Hugging Face API key
vendor/bin/setup text-to-image    # optional: the image generation runner (a few hundred MB)
vendor/bin/setup text-to-text     # optional: the text generation runner (a few hundred MB)
vendor/bin/setup image-to-text    # optional: the image description runner (a few hundred MB)
vendor/bin/setup speech-to-text   # optional: the speech transcription runner (a few hundred MB)
vendor/bin/setup text-to-speech   # optional: the speech synthesis runner (a few hundred MB)
```

```
🧰 Setting up php-loves-ai (v0.2.0) for darwin-arm64
🔑 Hugging Face API key (optional)
   Public models, like stabilityai/sd-turbo, are pulled without a key. Private and gated models need one:
   create it at https://huggingface.co/settings/tokens
   Paste your key (hidden), or press Enter to use public models only:
✅ Saved your Hugging Face API key to /var/www/my-app/.local/huggingface/credentials.json
✅ The puller is installed at /var/www/my-app/.local/runners/puller-darwin-arm64
🎉 All set! Happy hacking 🍪
👉 Pull a model: vendor/bin/pull <model>
```

### Hugging Face API key

A key is optional. Public models are pulled without one; private and gated models need it
(create one at https://huggingface.co/settings/tokens).

`setup` asks for it once and saves the answer in `.local/huggingface/credentials.json`, readable only by its owner.
Pressing Enter is remembered too, so `setup` does not ask again. To save or replace a key later, run
`vendor/bin/setup --token=hf_...` or pass `--token=hf_...` to `pull`. When there is no terminal to ask in (Docker
builds, CI, deploy scripts), `setup` skips the question; pass `--token` there if you need a key.

The key is never read from environment variables, so every process running the project uses the same one.

### Where models and runners live

Everything is stored inside your project, next to `vendor/`, in one fixed place:

```
<project root>/.local/
├── models/        models pulled by `vendor/bin/pull`, as models/<model id>
├── runners/       binaries installed by `vendor/bin/setup`
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
`vendor/bin/setup --force` (plus `setup text-to-image --force` if you use it) to get the matching binaries.

Running a command before its binary is installed fails with `The puller is not installed yet.` and a hint to run
`setup`.

## Pulling models

Public models need no API key. Private and gated models use the key saved in the project (see
[Hugging Face API key](#hugging-face-api-key)).

### From the command line

```bash
vendor/bin/pull openai-community/gpt2 [--revision=main] [--token=hf_...] [--log-file=PATH] [--debug]
```

```
☕ Pulling openai-community/gpt2… Big downloads take a moment — perfect time for a cup of tea and some cookies 🍪
If you wish to see all logs, re-run the command with the "--debug" option.
🎉 Pulled openai-community/gpt2 into /var/www/my-app/.local/models/openai-community/gpt2
```

The opening message is picked at random from a few cozy variants (see `PullCommand::INTROS`).

When Hugging Face refuses a model, `pull` explains why and what to do:

```
Error: meta-llama/Llama-3.2-1B is not available: it is a gated model, which needs a Hugging Face API key.
Re-run with your key: vendor/bin/pull meta-llama/Llama-3.2-1B --token=<your Hugging Face API key> (create one at https://huggingface.co/settings/tokens)
```

`--token` saves the key for next time. A gated model also needs its terms accepted on its Hugging Face page, with the
account the key belongs to.

Pulls one model at a time into `.local/models/<model id>`. The puller's own output is hidden unless `--debug` is given.
To keep that output, set a log file — each run is appended to it with a timestamp, the puller's output and the
outcome. Output is colored on terminals; set `NO_COLOR=1` to disable colors.
Exit codes: `0` success, `1` pull failed, `2` invalid usage. Run `vendor/bin/pull --help` for details.

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
);
// ['openai-community/gpt2' => '/var/www/my-app/.local/models/openai-community/gpt2', ...]
```

Failures throw exceptions implementing `PhpLovesAi\Exception\PhpLovesAiException`. A model Hugging Face refuses
throws `ModelAccessDeniedException` (`$model`, `$reason`: `gated` or `not_found`, `$apiKeyUsed`); other failures throw
`PullFailedException`. Both list the models pulled before the failure in `$pulled`. To save a key from PHP, use
`(new PhpLovesAi\HuggingFace\Credentials())->saveApiKey('hf_...')`.

The binary can also be used directly:

```bash
[HUGGING_FACE_API_KEY=hf_...] .local/runners/puller-darwin-arm64 --dir .local/models [--revision main] -- openai-community/gpt2
```

It writes one JSON line per model to stdout, `{"model": "...", "path": "..."}` when pulled or
`{"model": "...", "error": "gated|not_found"}` when refused, and progress to stderr.
Exit codes: `0` success, `1` at least one model failed, `2` invalid arguments.

## Generating images

Pull a complete [Diffusers text-to-image model](https://huggingface.co/models?pipeline_tag=text-to-image&library=diffusers)
first, such as `stabilityai/sd-turbo`. Its repository has a `model_index.json`. Add-ons like embeddings or LoRAs, and
single-file checkpoints, cannot be used on their own: the runner rejects them with an explanation before starting.

Then:

### From the command line

```bash
vendor/bin/pull stabilityai/sd-turbo
vendor/bin/text-to-image stabilityai/sd-turbo "a cozy cat by the fireplace" --steps=1 --guidance=0
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
vendor/bin/pull Qwen/Qwen2.5-0.5B-Instruct
vendor/bin/text-to-text Qwen/Qwen2.5-0.5B-Instruct "Write a haiku about PHP." --system="You are a poet."
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
vendor/bin/pull HuggingFaceTB/SmolVLM-256M-Instruct
vendor/bin/image-to-text HuggingFaceTB/SmolVLM-256M-Instruct photo.jpg "What are the animals doing?"
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
vendor/bin/pull openai/whisper-tiny
vendor/bin/speech-to-text openai/whisper-tiny interview.m4a
vendor/bin/speech-to-text openai/whisper-tiny interview.m4a --timestamps
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
vendor/bin/pull facebook/mms-tts-eng
vendor/bin/text-to-speech facebook/mms-tts-eng "PHP loves AI, and now it can speak." --output=hello.wav
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
bin/                 CLI scripts exposed via vendor/bin (setup, pull, and one per runner)
config/              Package config (pull.php and one file per runner)
python/              Python sources compiled into standalone binaries (not shipped via Composer)
  puller/            Pulls models from Hugging Face and saves them locally
  runners/           One runner per task, running locally saved models
    text-to-image/   Generates images with diffusers models
    text-to-text/    Generates text with transformers models
    image-to-text/   Describes images with transformers models
    speech-to-text/  Transcribes speech with transformers models
    text-to-speech/  Reads text aloud with transformers models
src/
  Enum/              Model registry
  Binary/            Platform detection and installing (Installer) the prebuilt binaries
  Config/            Config loading and validation
  Console/           CLI commands behind the bin/ scripts
  Filesystem/        LocalStorage (the fixed paths inside .local) and path helpers
  HuggingFace/       Credentials: the optional API key saved in .local/huggingface/credentials.json
  Process/           PHP wrapper that invokes the puller binary (ModelPuller)
  Runner/            One class per task running pulled models (TextToImage, TextToText, ImageToText, SpeechToText,
                     TextToSpeech), sharing the Runner interface
  Exception/         Package exceptions
tests/
  Unit/
  Integration/
```

## License

MIT
