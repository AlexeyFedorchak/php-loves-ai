# php-loves-ai

Run small Hugging Face AI models locally from PHP — no Python installation required.

## How it works

The Composer package itself is tiny and contains only PHP code. The heavy parts live outside of it:

1. **Puller binary** — a Python script compiled with PyInstaller that pulls models from Hugging Face and saves them locally.
2. **Runner binary** — a Python script compiled with PyInstaller that loads a locally saved model and runs it.
3. The binaries are built per platform (Linux / macOS / Windows, x86_64 / arm64) and published as release assets.
   The PHP side downloads the one matching the current OS on demand.
4. The user chooses which models to pull; weights are never shipped through Composer.
5. PHP code calls the binaries via Symfony Process and exposes a fluent, native-feeling API.

## Requirements

- PHP 8.2+
- `proc_open` enabled (used to invoke the binaries)

## Installation

```bash
composer require php-loves-ai/php-loves-ai
```

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
| `binary`     | `python/puller/dist/puller-<os>-<arch>`      | —             |

### From PHP

```php
use PhpLovesAi\Process\ModelPuller;

$puller = new ModelPuller(
    binaryPath: '/path/to/puller-darwin-arm64',
    modelsDir: '~/tmp/hugging-face/models',
);

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

## Building the puller binary

```bash
python/puller/build.sh   # → python/puller/dist/puller-<os>-<arch>
```

PyInstaller does not cross-compile: run it on each target OS and CPU architecture.

## Structure

```
bin/                 CLI scripts exposed via vendor/bin (pull)
config/              Package config (pull.php)
python/              Python sources compiled into standalone binaries (not shipped via Composer)
  puller/            Pulls models from Hugging Face and saves them locally
  runner/            Runs a locally saved model
src/
  Enum/              Model registry
  Binary/            Platform detection, downloading and locating the prebuilt binaries
  Config/            Config loading and validation
  Console/           CLI commands behind the bin/ scripts
  Filesystem/        Path helpers (~ expansion)
  Process/           PHP wrappers that invoke the puller and runner binaries
  Exception/         Package exceptions
tests/
  Unit/
  Integration/
```

## License

MIT
