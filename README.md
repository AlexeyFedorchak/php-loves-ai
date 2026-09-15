# php-loves-ai

Run small Hugging Face AI models locally from PHP — no Python installation required.

## How it works

The Composer package itself is tiny and contains only PHP code. The heavy parts live outside of it:

1. **Puller binary** — a Python script compiled with PyInstaller that pulls models from Hugging Face and saves them locally.
2. **Runner binaries** — one per task (e.g. text-to-image), each compiled with PyInstaller. A runner loads a locally
   saved model and runs it; within a task one runner serves many models (diffusers picks the right pipeline from
   the model's own config), while tasks get separate binaries because their dependencies differ.
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
vendor/bin/setup                  # downloads the puller (~17 MB)
vendor/bin/setup text-to-image    # optional: the image generation runner (a few hundred MB)
```

```
🧰 Setting up php-loves-ai (v0.1.0) for darwin-arm64
✅ The puller is installed.
🎉 All set! Happy hacking 🍪
👉 Pull a model: vendor/bin/pull <model>
```

Binaries are installed per package version into the per-user application data directory, where `pull` and
`text-to-image` find them without any configuration:

| OS      | Location                                                   |
|---------|------------------------------------------------------------|
| macOS   | `~/Library/Application Support/php-loves-ai/bin/<version>` |
| Linux   | `$XDG_DATA_HOME/php-loves-ai/bin/<version>` (default `~/.local/share/…`) |
| Windows | `%LOCALAPPDATA%\php-loves-ai\bin\<version>`                |

Upgrading the package switches to a new `<version>` directory, so run `vendor/bin/setup` again after an upgrade.
Set `PHP_LOVES_AI_HOME` to install elsewhere — e.g. in a Docker image, or when a web server runs PHP as a different
user than the one who ran `setup` (the variable must then be set for both). `setup --force` re-downloads, and
`setup --debug` shows the URLs and paths used.

Running a command before its binary is installed fails with `The puller is not installed yet.` and a hint to run
`setup`.

## Pulling models

The puller needs a Hugging Face API key in the `HUGGING_FACE_API_KEY` environment variable
(create one at https://huggingface.co/settings/tokens).

### From the command line

```bash
vendor/bin/pull openai-community/gpt2 [--dir=~/tmp/hugging-face/models] [--revision=main] [--log-file=PATH] [--debug]
```

```
☕ Pulling openai-community/gpt2… Big downloads take a moment — perfect time for a cup of tea and some cookies 🍪
If you wish to see all logs, re-run the command with the "--debug" option.
🎉 Pulled openai-community/gpt2 into /Users/you/tmp/hugging-face/models/openai-community/gpt2
```

The opening message is picked at random from a few cozy variants (see `PullCommand::INTROS`).

Pulls one model at a time into `<dir>/<model id>`. The puller's own output is hidden unless `--debug` is given.
To keep that output, set a log file — each run is appended to it with a timestamp, the puller's output and the
outcome. Output is colored on terminals; set `NO_COLOR=1` to disable colors.
Exit codes: `0` success, `1` pull failed, `2` invalid usage. Run `vendor/bin/pull --help` for details.

Defaults come from `config/pull.php`:

| Key          | Default                                      | Overridden by |
|--------------|----------------------------------------------|---------------|
| `revision`   | `main`                                       | `--revision`  |
| `models_dir` | `~/tmp/hugging-face/models` (`~` = home dir) | `--dir`       |
| `log_file`   | `null` (output discarded)                    | `--log-file`  |
| `binary`     | `null` (the one installed by `setup`)        | —             |

### From PHP

```php
use PhpLovesAi\Process\ModelPuller;

// Uses the binary installed by `vendor/bin/setup`; `new ModelPuller($binaryPath, $modelsDir)` takes an explicit one.
$puller = ModelPuller::installed(modelsDir: '~/tmp/hugging-face/models');

$paths = $puller->pull(
    ['openai-community/gpt2', 'distilbert/distilbert-base-uncased'],
    onProgress: fn (string $chunk) => fwrite(STDERR, $chunk),
);
// ['openai-community/gpt2' => '/Users/you/tmp/hugging-face/models/openai-community/gpt2', ...]
```

Failures throw exceptions implementing `PhpLovesAi\Exception\PhpLovesAiException`. `PullFailedException::$pulled`
lists the models that were pulled successfully before the failure.

The binary can also be used directly:

```bash
HUGGING_FACE_API_KEY=hf_... puller-darwin-arm64 --dir ~/tmp/hugging-face/models [--revision main] -- openai-community/gpt2
```

It writes one JSON line per pulled model (`{"model": "...", "path": "..."}`) to stdout and progress to stderr.
Exit codes: `0` success, `1` at least one model failed, `2` invalid arguments, `3` API key missing.

## Generating images

Pull a diffusers text-to-image model first, then:

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
| `--dir=DIR`              | Directory the model was pulled into                                 |
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
makes the run fail. Defaults come from `config/text-to-image.php` (`models_dir`, `output_dir`, `log_file`, `binary`).

### From PHP

```php
use PhpLovesAi\Process\TextToImageRunner;

// Uses the binary installed by `vendor/bin/setup text-to-image`.
$runner = TextToImageRunner::installed(modelsDir: '~/tmp/hugging-face/models');

$image = $runner->generate(
    model: 'stabilityai/sd-turbo',
    prompt: 'a cozy cat by the fireplace',
    outputPath: __DIR__ . '/cat.png',
    steps: 1,
    guidanceScale: 0.0,
);
// '/.../cat.png'
```

Throws `BinaryNotInstalledException` when `setup text-to-image` has not been run, `ModelNotFoundException` when the
model was not pulled into `modelsDir`, and `RunFailedException` (with the runner's error output) when generation fails.

## Releasing binaries

Publishing a GitHub release runs `.github/workflows/release-binaries.yml`, which builds every binary on each supported
platform and attaches `<tool>-<os>-<arch>.tar.gz` plus a `.sha256` checksum to the release. `setup` downloads from the
release matching the installed package version (development installs use the latest release). The workflow can also
be started by hand for an existing tag.

To build and pack locally (PyInstaller does not cross-compile, so this covers the current platform only):

```bash
python/puller/build.sh                    # → python/puller/dist/puller-<os>-<arch>
python/runners/text-to-image/build.sh     # → python/runners/text-to-image/dist/text-to-image-<os>-<arch>/
python/package.sh                         # → python/release/*.tar.gz + *.sha256
```

Linux binaries can be built from any Docker host (including a Mac): `python/build-in-docker.sh linux/amd64` or
`python/build-in-docker.sh linux/arm64` builds and packs them on an old glibc base, so they run on Debian 11+, Ubuntu
20.04+ and RHEL 9+. The release workflow uses the same script.

The text-to-image runner bundles torch and diffusers, so it is built as a directory (~700 MB, ~220 MB packed) rather
than a single file; Linux builds use CPU-only torch to stay within GitHub's release asset size limit.
To test `setup` against local assets, serve `python/release` over HTTP and set `PHP_LOVES_AI_DOWNLOAD_URL` to its URL.

## Structure

```
bin/                 CLI scripts exposed via vendor/bin (setup, pull, text-to-image)
config/              Package config (pull.php, text-to-image.php)
python/              Python sources compiled into standalone binaries (not shipped via Composer)
  puller/            Pulls models from Hugging Face and saves them locally
  runners/           One runner per task, running locally saved models
    text-to-image/   Generates images with diffusers models
src/
  Enum/              Model registry
  Binary/            Platform detection; installing (Installer) and locating (BinaryStore) the prebuilt binaries
  Config/            Config loading and validation
  Console/           CLI commands behind the bin/ scripts
  Filesystem/        Path helpers (~ expansion)
  Process/           PHP wrappers that invoke the binaries (ModelPuller, TextToImageRunner)
  Exception/         Package exceptions
tests/
  Unit/
  Integration/
```

## License

MIT
