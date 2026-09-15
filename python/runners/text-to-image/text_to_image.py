"""Generate an image from a text prompt with a locally pulled Hugging Face diffusion model.

Compiled into a standalone binary with PyInstaller, so it runs without a Python
installation. Designed to be driven by the PHP wrapper:

  * stdout: one JSON object on success, e.g. {"output": "/abs/path/image.png"}
  * stderr: progress and error messages for humans
  * exit code: 0 success, 1 generation failed, 2 invalid arguments

Input is handed to diffusers as-is: values the model cannot handle (a prompt that
is too long, an unsupported size, an unknown device...) make the run fail.
"""

import argparse
import json
import multiprocessing
import os
import sys
import traceback

EXIT_OK = 0
EXIT_FAILED = 1


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="text-to-image",
        description="Generate an image from a text prompt with a locally pulled diffusion model.",
    )
    parser.add_argument("--model", required=True, help="Directory of a pulled diffusers model (contains model_index.json)")
    parser.add_argument("--prompt", required=True, help="Text describing the image")
    parser.add_argument("--output", required=True, help="Path of the image file to write, e.g. image.png")
    parser.add_argument("--negative-prompt", help="Text describing what the image should not contain")
    parser.add_argument("--steps", type=int, help="Number of inference steps (default: the pipeline's own)")
    parser.add_argument("--guidance", type=float, help="Classifier-free guidance scale (default: the pipeline's own)")
    parser.add_argument("--width", type=int, help="Image width in pixels (default: the model's native size)")
    parser.add_argument("--height", type=int, help="Image height in pixels (default: the model's native size)")
    parser.add_argument("--seed", type=int, help="Random seed, for reproducible images")
    parser.add_argument("--device", help="Torch device, e.g. cpu, cuda, mps (default: the best available)")
    return parser.parse_args(argv)


def log(message: str) -> None:
    print(message, file=sys.stderr, flush=True)


def main(argv: list[str]) -> int:
    args = parse_args(argv)

    # Models are loaded from disk only; never reach out to the Hub.
    os.environ["HF_HUB_OFFLINE"] = "1"

    # Imported after argument parsing so --help and usage errors stay fast.
    import torch
    from diffusers import DiffusionPipeline
    from diffusers.utils import logging as diffusers_logging
    from transformers.utils import logging as transformers_logging

    # Progress bars redraw with carriage returns, which garbles pipes and log files.
    show_progress = sys.stderr.isatty()
    if not show_progress:
        diffusers_logging.disable_progress_bar()
        transformers_logging.disable_progress_bar()

    try:
        device = args.device or best_device(torch)
        dtype = torch.float16 if device.startswith("cuda") else torch.float32

        log(f"[text-to-image] Loading {args.model} on {device}...")
        pipeline = DiffusionPipeline.from_pretrained(args.model, torch_dtype=dtype, local_files_only=True)
        pipeline = pipeline.to(device)
        pipeline.set_progress_bar_config(disable=not show_progress)

        options = {
            "negative_prompt": args.negative_prompt,
            "num_inference_steps": args.steps,
            "guidance_scale": args.guidance,
            "width": args.width,
            "height": args.height,
        }
        options = {name: value for name, value in options.items() if value is not None}
        if args.seed is not None:
            options["generator"] = torch.Generator(device="cpu").manual_seed(args.seed)

        log("[text-to-image] Generating image...")
        image = pipeline(prompt=args.prompt, **options).images[0]

        output = os.path.abspath(args.output)
        os.makedirs(os.path.dirname(output), exist_ok=True)
        image.save(output)
    except Exception as e:
        traceback.print_exc()
        log(f"[text-to-image] Error: {type(e).__name__}: {e}")
        return EXIT_FAILED

    print(json.dumps({"output": output}), flush=True)
    log(f"[text-to-image] Saved {output}.")
    return EXIT_OK


def best_device(torch) -> str:
    if torch.cuda.is_available():
        return "cuda"
    if torch.backends.mps.is_available():
        return "mps"
    return "cpu"


if __name__ == "__main__":
    # torch starts multiprocessing helpers by re-running sys.executable, which in the frozen binary is this
    # script; freeze_support() lets those helper invocations run instead of reaching the argument parser.
    multiprocessing.freeze_support()
    sys.exit(main(sys.argv[1:]))
