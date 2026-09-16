"""Animate an image into a video with a locally pulled Hugging Face diffusers pipeline.

Compiled into a standalone binary with PyInstaller, so it runs without a Python
installation. Designed to be driven by the PHP wrapper:

  * stdout: one JSON object on success, e.g.
            {"output": "/abs/path/clip.mp4", "frames": 49, "width": 704, "height": 480, "fps": 8}
  * stderr: progress and error messages for humans
  * exit code: 0 success, 1 generation failed, 2 invalid arguments

Works with diffusers image-to-video pipelines, e.g. Stable Video Diffusion (image only), Wan I2V,
CogVideoX I2V or LTX-Video (image and prompt). A pipeline that only reads text is converted to its
image-to-video variant when diffusers has one.

The video is encoded with PyAV, which bundles FFmpeg's libraries: the output file's extension picks
the format (.mp4, .webm, .gif) without FFmpeg being installed.
"""

import argparse
import inspect
import json
import multiprocessing
import os
import sys
import traceback

EXIT_OK = 0
EXIT_FAILED = 1

DEFAULT_FPS = 8

# Output file extension -> encoder; .gif is written by Pillow instead.
CODECS = {"mp4": "libx264", "webm": "libvpx-vp9", "mkv": "libx264"}


class UserError(Exception):
    """A problem the user can fix, reported without a stack trace."""


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="image-to-video",
        description="Animate an image into a video with a locally pulled diffusers pipeline.",
    )
    parser.add_argument("--model", required=True, help="Directory of a pulled diffusers pipeline (contains model_index.json)")
    parser.add_argument("--image", required=True, help="Path of the image to animate, e.g. photo.jpg")
    parser.add_argument("--prompt", help="Text describing the video, for models that take one")
    parser.add_argument("--output", required=True, help="Video file to write; its extension picks the format, e.g. clip.mp4")
    parser.add_argument("--negative-prompt", help="Text describing what the video should not contain")
    parser.add_argument("--frames", type=int, help="Number of frames to generate (default: the pipeline's own)")
    parser.add_argument("--fps", type=int, default=DEFAULT_FPS, help=f"Frames per second of the written video (default: {DEFAULT_FPS})")
    parser.add_argument("--steps", type=int, help="Number of inference steps (default: the pipeline's own)")
    parser.add_argument("--guidance", type=float, help="Classifier-free guidance scale (default: the pipeline's own)")
    parser.add_argument("--width", type=int, help="Frame width in pixels (default: the pipeline's own)")
    parser.add_argument("--height", type=int, help="Frame height in pixels (default: the pipeline's own)")
    parser.add_argument("--seed", type=int, help="Random seed, for reproducible videos")
    parser.add_argument("--device", help="Torch device, e.g. cpu, cuda, mps (default: the best available)")
    return parser.parse_args(argv)


def log(message: str) -> None:
    print(message, file=sys.stderr, flush=True)


def write_video(path: str, frames, fps: int) -> tuple[int, int]:
    """Encodes PIL frames into path, in the format its extension asks for; returns the frame size."""
    os.makedirs(os.path.dirname(os.path.abspath(path)) or ".", exist_ok=True)
    extension = os.path.splitext(path)[1].lower().lstrip(".")

    if extension == "gif":
        frames[0].save(path, save_all=True, append_images=frames[1:], duration=round(1000 / fps), loop=0)
        return frames[0].width, frames[0].height

    codec = CODECS.get(extension)
    if codec is None:
        raise UserError(f"cannot write .{extension} files; use one of: .mp4, .webm, .mkv, .gif")

    import av

    # H.264 and VP9 encode in blocks, so both sides have to be even numbers of pixels.
    width, height = frames[0].width - frames[0].width % 2, frames[0].height - frames[0].height % 2

    with av.open(path, mode="w") as container:
        stream = container.add_stream(codec, rate=fps)
        stream.width, stream.height = width, height
        stream.pix_fmt = "yuv420p"

        for image in frames:
            frame = av.VideoFrame.from_image(image.convert("RGB").crop((0, 0, width, height)))
            for packet in stream.encode(frame):
                container.mux(packet)

        for packet in stream.encode(None):
            container.mux(packet)

    return width, height


def as_image_to_video(diffusers, pipeline):
    """Swaps a text-only pipeline for the image-to-video pipeline of the same family, e.g. LTX -> LTXImageToVideo."""
    if "image" in inspect.signature(pipeline.__call__).parameters:
        return pipeline

    name = type(pipeline).__name__
    variant = getattr(diffusers, name.replace("Pipeline", "ImageToVideoPipeline"), None)
    if variant is None:
        raise UserError(f"this model does not take an image ({name}); use it with text-to-video instead")

    return variant.from_pipe(pipeline)


def main(argv: list[str]) -> int:
    args = parse_args(argv)

    # Models are loaded from disk only; never reach out to the Hub.
    os.environ["HF_HUB_OFFLINE"] = "1"

    # Imported after argument parsing so --help and usage errors stay fast.
    import diffusers
    import torch
    from diffusers import DiffusionPipeline
    from diffusers.utils import logging as diffusers_logging
    from PIL import Image
    from transformers.utils import logging as transformers_logging

    # Progress bars redraw with carriage returns, which garbles pipes and log files.
    show_progress = sys.stderr.isatty()
    if not show_progress:
        diffusers_logging.disable_progress_bar()
        transformers_logging.disable_progress_bar()

    try:
        device = args.device or best_device(torch)
        dtype = torch.float16 if device.startswith("cuda") else torch.float32

        image = Image.open(args.image).convert("RGB")

        log(f"[image-to-video] Loading {args.model} on {device}...")
        pipeline = DiffusionPipeline.from_pretrained(args.model, dtype=dtype)
        pipeline = as_image_to_video(diffusers, pipeline)
        pipeline = pipeline.to(device)
        pipeline.set_progress_bar_config(disable=not show_progress)

        accepted = inspect.signature(pipeline.__call__).parameters
        if args.prompt is not None and "prompt" not in accepted:
            raise UserError(f"this model animates the image on its own and takes no prompt ({type(pipeline).__name__})")

        options = {
            "prompt": args.prompt if "prompt" in accepted else None,
            "negative_prompt": args.negative_prompt,
            "num_frames": args.frames,
            "num_inference_steps": args.steps,
            "guidance_scale": args.guidance,
            "width": args.width,
            "height": args.height,
        }
        # Models that need a prompt accept an empty one, which asks them to follow the image alone.
        if "prompt" in accepted and args.prompt is None:
            options["prompt"] = ""
        options = {name: value for name, value in options.items() if value is not None and name in accepted}
        if args.seed is not None:
            options["generator"] = torch.Generator(device="cpu").manual_seed(args.seed)

        log("[image-to-video] Generating video...")
        result = pipeline(image=image, **options)
        if not hasattr(result, "frames"):
            raise UserError("this model does not produce video frames; it may generate still images instead")

        frames = list(result.frames[0])
        if not frames:
            raise UserError("the model produced no frames")

        output = os.path.abspath(args.output)
        width, height = write_video(output, frames, args.fps)
    except UserError as e:
        log(f"[image-to-video] Error: {e}")
        return EXIT_FAILED
    except Exception as e:
        traceback.print_exc()
        log(f"[image-to-video] Error: {type(e).__name__}: {e}")
        return EXIT_FAILED

    print(json.dumps({"output": output, "frames": len(frames), "width": width, "height": height, "fps": args.fps}), flush=True)
    log(f"[image-to-video] Saved {output} ({len(frames)} frames, {width}x{height}).")
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
