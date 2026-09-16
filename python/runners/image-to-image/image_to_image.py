"""Turn an image into another image with a locally pulled Hugging Face model.

Compiled into a standalone binary with PyInstaller, so it runs without a Python
installation. Designed to be driven by the PHP wrapper:

  * stdout: one JSON object on success, e.g. {"output": "/abs/path/big.png", "width": 1024, "height": 768}
  * stderr: progress and error messages for humans
  * exit code: 0 success, 1 transformation failed, 2 invalid arguments

Two kinds of models are supported, told apart by the files in the model directory:
  * upscaling models (transformers, e.g. caidas/swin2SR-classical-sr-x2-64): they enlarge and clean
    up the image and take no prompt
  * diffusers image-to-image pipelines (e.g. stabilityai/sd-turbo, timbrooks/instruct-pix2pix):
    they redraw the image following a prompt

Input is handed to the model as-is: values it cannot handle make the run fail.
"""

import argparse
import json
import multiprocessing
import os
import sys
import traceback

EXIT_OK = 0
EXIT_FAILED = 1


class UserError(Exception):
    """A problem the user can fix, reported without a stack trace."""


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="image-to-image",
        description="Turn an image into another image with a locally pulled model.",
    )
    parser.add_argument("--model", required=True, help="Directory of a pulled model")
    parser.add_argument("--image", required=True, help="Path of the image to read, e.g. photo.jpg")
    parser.add_argument("--output", required=True, help="Path of the image file to write; its extension picks the format")
    parser.add_argument("--prompt", help="What the result should look like (diffusers models only)")
    parser.add_argument("--negative-prompt", help="What the result should not contain (diffusers models only)")
    parser.add_argument("--strength", type=float, help="How much of the original to keep, 0 to 1; higher changes more (default: the pipeline's own)")
    parser.add_argument("--steps", type=int, help="Number of inference steps (default: the pipeline's own)")
    parser.add_argument("--guidance", type=float, help="Classifier-free guidance scale; turbo models use 0 (default: the pipeline's own)")
    parser.add_argument("--seed", type=int, help="Random seed, for reproducible images")
    parser.add_argument("--device", help="Torch device, e.g. cpu, cuda, mps (default: the best available)")
    return parser.parse_args(argv)


def log(message: str) -> None:
    print(message, file=sys.stderr, flush=True)


def upscale(args, torch, image, device, dtype):
    """Enlarges the image with a transformers super-resolution model, e.g. Swin2SR."""
    import numpy as np
    from PIL import Image
    from transformers import AutoImageProcessor, AutoModelForImageToImage

    if args.prompt or args.negative_prompt:
        raise UserError("this model does not take a prompt; it only enlarges and cleans up the image")

    processor = AutoImageProcessor.from_pretrained(args.model, local_files_only=True)
    model = AutoModelForImageToImage.from_pretrained(args.model, dtype=dtype, local_files_only=True).to(device)
    model.eval()

    inputs = processor(image, return_tensors="pt").to(device)
    log("[image-to-image] Enlarging image...")
    with torch.no_grad():
        reconstruction = model(**inputs).reconstruction

    pixels = reconstruction.data.squeeze().float().cpu().clamp_(0, 1).numpy()
    pixels = (np.moveaxis(pixels, source=0, destination=-1) * 255.0).round().astype(np.uint8)
    result = Image.fromarray(pixels)

    # The model pads the image to a whole number of windows, so the result can overshoot the exact scale.
    scale = int(getattr(model.config, "upscale", 1) or 1)
    return result.crop((0, 0, image.width * scale, image.height * scale))


def redraw(args, torch, image, device, dtype):
    """Redraws the image following the prompt with a diffusers image-to-image pipeline."""
    from diffusers import AutoPipelineForImage2Image

    if not args.prompt:
        raise UserError("this model needs a prompt describing the result, e.g. 'a watercolor painting of a cat'")

    pipeline = AutoPipelineForImage2Image.from_pretrained(args.model, dtype=dtype, local_files_only=True)
    pipeline = pipeline.to(device)
    pipeline.set_progress_bar_config(disable=not sys.stderr.isatty())

    options = {
        "negative_prompt": args.negative_prompt,
        "strength": args.strength,
        "num_inference_steps": args.steps,
        "guidance_scale": args.guidance,
    }
    options = {name: value for name, value in options.items() if value is not None}
    if args.seed is not None:
        options["generator"] = torch.Generator(device="cpu").manual_seed(args.seed)

    log("[image-to-image] Redrawing image...")
    return pipeline(prompt=args.prompt, image=image, **options).images[0]


def main(argv: list[str]) -> int:
    args = parse_args(argv)

    # Models are loaded from disk only; never reach out to the Hub.
    os.environ["HF_HUB_OFFLINE"] = "1"

    # Imported after argument parsing so --help and usage errors stay fast.
    import torch
    from PIL import Image
    from transformers.utils import logging as transformers_logging

    # Progress bars redraw with carriage returns, which garbles pipes and log files.
    if not sys.stderr.isatty():
        transformers_logging.disable_progress_bar()

    try:
        device = args.device or best_device(torch)
        dtype = torch.float16 if device.startswith("cuda") else torch.float32

        image = Image.open(args.image).convert("RGB")

        log(f"[image-to-image] Loading {args.model} on {device}...")
        # A model_index.json describes a diffusers pipeline; anything else is a transformers model.
        transform = redraw if os.path.isfile(os.path.join(args.model, "model_index.json")) else upscale
        result = transform(args, torch, image, device, dtype)

        output = os.path.abspath(args.output)
        os.makedirs(os.path.dirname(output) or ".", exist_ok=True)
        result.save(output)
    except UserError as e:
        log(f"[image-to-image] Error: {e}")
        return EXIT_FAILED
    except Exception as e:
        traceback.print_exc()
        log(f"[image-to-image] Error: {type(e).__name__}: {e}")
        return EXIT_FAILED

    print(json.dumps({"output": output, "width": result.width, "height": result.height}), flush=True)
    log(f"[image-to-image] Saved {output} ({result.width}x{result.height}).")
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
