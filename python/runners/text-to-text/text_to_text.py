"""Generate text from a prompt with a locally pulled Hugging Face transformers model.

Compiled into a standalone binary with PyInstaller, so it runs without a Python
installation. Designed to be driven by the PHP wrapper:

  * stdout: one JSON object on success, e.g. {"text": "Once upon a time..."}
  * stderr: progress and error messages for humans, and the generated text as it is written
  * exit code: 0 success, 1 generation failed, 2 invalid arguments

Both kinds of text models are supported:
  * decoder-only models (task "text-generation"), e.g. SmolLM, Qwen, Llama, GPT-2;
    chat models get the prompt wrapped in their chat template
  * encoder-decoder models (task "text2text-generation"), e.g. FLAN-T5, BART

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

# Most models' own default stops after ~20 tokens, which cuts answers short.
DEFAULT_MAX_NEW_TOKENS = 256


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="text-to-text",
        description="Generate text from a prompt with a locally pulled transformers model.",
    )
    parser.add_argument("--model", required=True, help="Directory of a pulled transformers model (contains config.json)")
    parser.add_argument("--prompt", required=True, help="Text to respond to or continue")
    parser.add_argument("--system", help="System prompt for chat models, e.g. 'You are a helpful assistant.'")
    parser.add_argument(
        "--max-new-tokens",
        type=int,
        default=DEFAULT_MAX_NEW_TOKENS,
        help=f"Maximum number of tokens to generate (default: {DEFAULT_MAX_NEW_TOKENS})",
    )
    parser.add_argument("--temperature", type=float, help="Sampling temperature; 0 always picks the likeliest token (default: the model's own)")
    parser.add_argument("--top-p", type=float, help="Nucleus sampling probability, e.g. 0.9 (default: the model's own)")
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
    from transformers import AutoConfig, AutoModelForCausalLM, AutoModelForSeq2SeqLM, AutoTokenizer, TextStreamer
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

        log(f"[text-to-text] Loading {args.model} on {device}...")
        config = AutoConfig.from_pretrained(args.model, local_files_only=True)
        encoder_decoder = bool(getattr(config, "is_encoder_decoder", False))
        model_class = AutoModelForSeq2SeqLM if encoder_decoder else AutoModelForCausalLM

        tokenizer = AutoTokenizer.from_pretrained(args.model, local_files_only=True)
        model = model_class.from_pretrained(args.model, dtype=dtype, local_files_only=True).to(device)
        model.eval()

        chat = not encoder_decoder and getattr(tokenizer, "chat_template", None)
        if chat:
            messages = [{"role": "user", "content": args.prompt}]
            if args.system:
                messages.insert(0, {"role": "system", "content": args.system})
            inputs = tokenizer.apply_chat_template(
                messages, add_generation_prompt=True, return_tensors="pt", return_dict=True
            )
        else:
            text = f"{args.system}\n\n{args.prompt}" if args.system else args.prompt
            inputs = tokenizer(text, return_tensors="pt")
        inputs = {name: tensor.to(device) for name, tensor in inputs.items()}

        options = {"max_new_tokens": args.max_new_tokens}
        if args.temperature is not None:
            if args.temperature == 0:
                options["do_sample"] = False
            else:
                options["do_sample"] = True
                options["temperature"] = args.temperature
        if args.top_p is not None:
            options["top_p"] = args.top_p
        if tokenizer.pad_token_id is None and tokenizer.eos_token_id is not None:
            options["pad_token_id"] = tokenizer.eos_token_id
        if args.seed is not None:
            torch.manual_seed(args.seed)

        log("[text-to-text] Generating text...")
        with torch.no_grad():
            output_ids = model.generate(
                **inputs,
                **options,
                streamer=StderrStreamer(tokenizer, skip_prompt=True, skip_special_tokens=True),
            )

        # Decoder-only models return the prompt followed by the new tokens.
        new_ids = output_ids[0] if encoder_decoder else output_ids[0][inputs["input_ids"].shape[-1]:]
        result = tokenizer.decode(new_ids, skip_special_tokens=True).strip()
    except Exception as e:
        traceback.print_exc()
        log(f"[text-to-text] Error: {type(e).__name__}: {e}")
        return EXIT_FAILED

    print(json.dumps({"text": result}), flush=True)
    log("[text-to-text] Done.")
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
