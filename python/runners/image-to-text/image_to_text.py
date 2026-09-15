"""Describe an image, or answer a question about it, with a locally pulled Hugging Face transformers model.

Compiled into a standalone binary with PyInstaller, so it runs without a Python
installation. Designed to be driven by the PHP wrapper:

  * stdout: one JSON object on success, e.g. {"text": "two cats sleeping on a pink couch"}
  * stderr: progress and error messages for humans, and the text as it is written
  * exit code: 0 success, 1 generation failed, 2 invalid arguments

Both kinds of image-to-text models are supported:
  * captioning models (task "image-to-text"), e.g. BLIP, GIT, ViT-GPT2; a prompt, when given,
    is the start of the caption
  * vision-language models (task "image-text-to-text"), e.g. SmolVLM, Qwen2-VL, LLaVA; they answer
    the prompt about the image through their chat template

Input is handed to transformers as-is: values the model cannot handle make the run fail.
"""

import argparse
import json
import multiprocessing
import os
import sys
import traceback

EXIT_OK = 0
EXIT_FAILED = 1

DEFAULT_MAX_NEW_TOKENS = 256

# What vision-language models are asked when no prompt is given.
DEFAULT_QUESTION = "Describe this image."


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="image-to-text",
        description="Describe an image, or answer a question about it, with a locally pulled transformers model.",
    )
    parser.add_argument("--model", required=True, help="Directory of a pulled transformers model (contains config.json)")
    parser.add_argument("--image", required=True, help="Path of the image file, e.g. photo.jpg")
    parser.add_argument(
        "--prompt",
        help=f"Question about the image for vision-language models (default: '{DEFAULT_QUESTION}'), "
        "or the start of the caption for captioning models",
    )
    parser.add_argument(
        "--max-new-tokens",
        type=int,
        default=DEFAULT_MAX_NEW_TOKENS,
        help=f"Maximum number of tokens to generate (default: {DEFAULT_MAX_NEW_TOKENS})",
    )
    parser.add_argument("--temperature", type=float, help="Sampling temperature; 0 always picks the likeliest token (default: the model's own)")
    parser.add_argument("--seed", type=int, help="Random seed, for reproducible text")
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
    from PIL import Image
    from transformers import (
        AutoImageProcessor,
        AutoModelForImageTextToText,
        AutoProcessor,
        AutoTokenizer,
        PreTrainedTokenizerBase,
        TextStreamer,
    )
    from transformers.utils import logging as transformers_logging

    # Progress bars redraw with carriage returns, which garbles pipes and log files.
    if not sys.stderr.isatty():
        transformers_logging.disable_progress_bar()

    class StderrStreamer(TextStreamer):
        """Writes the text to stderr as it is generated, keeping stdout for the result."""

        def on_finalized_text(self, text: str, stream_end: bool = False) -> None:
            print(text, file=sys.stderr, flush=True, end="" if not stream_end else "\n")

    try:
        device = args.device or best_device(torch)
        dtype = torch.float16 if device.startswith("cuda") else torch.float32

        image = Image.open(args.image).convert("RGB")

        log(f"[image-to-text] Loading {args.model} on {device}...")
        processor = AutoProcessor.from_pretrained(args.model, local_files_only=True)
        if isinstance(processor, PreTrainedTokenizerBase):
            # Models without a combined processor (e.g. ViT-GPT2) ship an image processor and a tokenizer separately.
            tokenizer, processor = processor, AutoImageProcessor.from_pretrained(args.model, local_files_only=True)
        else:
            tokenizer = getattr(processor, "tokenizer", None) or AutoTokenizer.from_pretrained(args.model, local_files_only=True)

        model = AutoModelForImageTextToText.from_pretrained(args.model, dtype=dtype, local_files_only=True).to(device)
        model.eval()

        chat = bool(getattr(processor, "chat_template", None))
        if chat:
            messages = [{"role": "user", "content": [{"type": "image"}, {"type": "text", "text": args.prompt or DEFAULT_QUESTION}]}]
            text = processor.apply_chat_template(messages, add_generation_prompt=True)
            inputs = processor(text=text, images=[image], return_tensors="pt")
        elif args.prompt and hasattr(processor, "tokenizer"):
            # The prompt is the start of the caption, e.g. "a photography of".
            inputs = processor(images=image, text=args.prompt, return_tensors="pt")
        else:
            inputs = processor(images=image, return_tensors="pt")
        inputs = {
            name: tensor.to(device, dtype=dtype) if tensor.is_floating_point() else tensor.to(device)
            for name, tensor in inputs.items()
        }

        options = {"max_new_tokens": args.max_new_tokens}
        if args.temperature is not None:
            if args.temperature == 0:
                options["do_sample"] = False
            else:
                options["do_sample"] = True
                options["temperature"] = args.temperature
        if args.seed is not None:
            torch.manual_seed(args.seed)

        log("[image-to-text] Generating text...")
        with torch.no_grad():
            output_ids = model.generate(
                **inputs,
                **options,
                streamer=StderrStreamer(tokenizer, skip_prompt=True, skip_special_tokens=True),
            )

        # Vision-language models return the chat prompt followed by the answer; captions keep their prompt as their start.
        new_ids = output_ids[0][inputs["input_ids"].shape[-1]:] if chat else output_ids[0]
        result = tokenizer.decode(new_ids, skip_special_tokens=True).strip()
    except Exception as e:
        traceback.print_exc()
        log(f"[image-to-text] Error: {type(e).__name__}: {e}")
        return EXIT_FAILED

    print(json.dumps({"text": result}), flush=True)
    log("[image-to-text] Done.")
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
