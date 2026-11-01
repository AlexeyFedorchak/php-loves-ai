"""Find, count, match and fingerprint faces in images with a locally pulled InsightFace model pack.

Compiled into a standalone binary with PyInstaller, so it runs without a Python
installation. Designed to be driven by the PHP wrapper:

  * stdout: one JSON object on success, e.g.
            {"count": 2, "faces": [{"id": "v1:256:3f8a...", "box": [...], ...}]}  one image
            {"similarity": 0.71, "same_person": true, "a": {...}, "b": {...}}     two images
  * stderr: progress and error messages for humans
  * exit code: 0 success, 1 run failed, 2 invalid arguments

The model directory holds two ONNX graphs, as published in the InsightFace model packs
(buffalo_l, buffalo_s, antelopev2):

  detection/model.onnx    SCRFD: a box, a score and five landmarks per face
  recognition/model.onnx  ArcFace: a 512-number embedding per aligned face

Unlike the other runners this one needs no torch: onnxruntime, numpy and Pillow are enough,
which keeps the binary at tens rather than hundreds of megabytes.

Faces are compared by the cosine similarity of their embeddings. The "id" is a
locality-sensitive hash of that same embedding, for backends that want one short string per
face; read the note on HASH_VERSION before storing it.
"""

import argparse
import json
import os
import sys
import traceback

EXIT_OK = 0
EXIT_FAILED = 1

# Size the detector resizes images to; InsightFace's own default, and the only size its own packs
# accept: they declare a dynamic input but keep static output shapes (see Detector.baked_size).
DEFAULT_DETECTION_SIZE = 640

# Minimum detector confidence for a box to count as a face.
DEFAULT_MIN_SCORE = 0.5

# Boxes overlapping more than this are treated as the same face.
NMS_IOU = 0.4

# ArcFace expects a 112x112 crop with the five landmarks moved onto these positions.
ALIGNED_SIZE = 112

# Bits in a face id. The expected Hamming distance between two ids is bits * angle / pi, so the
# spread around it (~sqrt(bits)/2) has to stay small next to the gap between same and different
# faces: at 64 bits the two overlap, at 256 they separate cleanly.
DEFAULT_HASH_BITS = 256
HASH_BIT_CHOICES = (64, 128, 256, 512)

# Cosine similarity above which two faces are called the same person. A starting point, not a
# truth: the right value depends on the model and on what a false match costs, and it has to be
# measured on your own images. Every result repeats the threshold it used.
DEFAULT_THRESHOLD = 0.40

# Bumped whenever the projection below changes, because that invalidates every stored id.
# Ids are only comparable when version, bit count AND recognition model all match: the hash
# describes a direction in one model's embedding space, so buffalo_l ids mean nothing to
# antelopev2. An id is a fingerprint for finding candidates, never a primary key: two photos of
# one person give two different ids a few bits apart, and a backend compares them by Hamming
# distance (or re-ranks the candidates by cosine similarity on the embeddings).
#
# Nor is an id bit-identical across execution providers: CPU and CoreML were measured one or two
# bits apart on the same image, because a float difference far below any threshold still flips the
# sign of a projection that sits almost exactly on its hyperplane. One more reason never to compare
# ids for equality.
HASH_VERSION = 1

# Fixed forever, so the same face always hashes the same way on every machine and release.
HASH_SEED = 0x7F10E5


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="face",
        description="Find, count, match and fingerprint faces with a locally pulled InsightFace model pack.",
    )
    parser.add_argument("--model", required=True, help="Directory of a pulled InsightFace pack (contains detection/model.onnx)")
    parser.add_argument("--image", required=True, help="Image to look for faces in")
    parser.add_argument("--compare", help="Second image; with it, the two images' best faces are matched instead")
    parser.add_argument("--min-score", type=float, default=DEFAULT_MIN_SCORE, help=f"Minimum detector confidence, from 0 to 1 (default: {DEFAULT_MIN_SCORE})")
    parser.add_argument("--max-faces", type=int, help="Keep only this many faces, the most confident first")
    parser.add_argument("--hash-bits", type=int, default=DEFAULT_HASH_BITS, choices=HASH_BIT_CHOICES, help=f"Bits in a face id (default: {DEFAULT_HASH_BITS})")
    parser.add_argument("--threshold", type=float, default=DEFAULT_THRESHOLD, help=f"Cosine similarity for 'same person' when comparing (default: {DEFAULT_THRESHOLD})")
    parser.add_argument("--det-size", type=int, default=DEFAULT_DETECTION_SIZE, help=f"Size images are resized to for detection (default: {DEFAULT_DETECTION_SIZE}); ignored by a detector built for one fixed size")
    parser.add_argument("--detect-only", action="store_true", help="Only locate faces: no embeddings, no ids, recognition model never loaded")
    parser.add_argument("--no-embeddings", action="store_true", help="Report ids but leave the 512 numbers out of the result")
    parser.add_argument("--device", help="Where to run: cpu, cuda, coreml (default: the best available)")
    return parser.parse_args(argv)


def log(message: str) -> None:
    print(message, file=sys.stderr, flush=True)


def main(argv: list[str]) -> int:
    args = parse_args(argv)

    if not 0 < args.min_score <= 1:
        log("[face] Error: --min-score must be greater than 0 and at most 1.")
        return EXIT_FAILED
    if args.max_faces is not None and args.max_faces < 1:
        log("[face] Error: --max-faces must be a positive integer.")
        return EXIT_FAILED
    if args.det_size < 64:
        log("[face] Error: --det-size must be at least 64.")
        return EXIT_FAILED

    # Imported after argument parsing so --help and usage errors stay fast.
    import numpy as np
    import onnxruntime as ort
    from PIL import Image

    try:
        providers = pick_providers(ort, args.device)
        log(f"[face] Loading {args.model} on {providers[0]}...")

        detector = Detector(ort, np, os.path.join(args.model, "detection", "model.onnx"), providers, args.det_size)
        recognizer = None if args.detect_only else Recognizer(
            ort, np, os.path.join(args.model, "recognition", "model.onnx"), providers, args.hash_bits
        )

        if args.compare is None:
            faces = read_faces(np, Image, args.image, detector, recognizer, args)
            result = {
                "model": os.path.basename(os.path.normpath(args.model)),
                "image": os.path.abspath(args.image),
                "count": len(faces),
                "faces": faces,
            }
        else:
            result = compare(np, Image, detector, recognizer, args)
    except Exception as e:
        traceback.print_exc()
        log(f"[face] Error: {type(e).__name__}: {e}")
        return EXIT_FAILED

    print(json.dumps(result), flush=True)
    log("[face] Done.")
    return EXIT_OK


def read_faces(np, Image, path: str, detector, recognizer, args) -> list[dict]:
    """Detects the faces in one image, newest-first by confidence, each with its id when recognizing."""
    image = load_image(Image, path)
    log(f"[face] Looking for faces in {path}...")

    boxes, landmarks = detector.detect(image, args.min_score)
    if args.max_faces is not None:
        boxes, landmarks = boxes[: args.max_faces], landmarks[: args.max_faces]

    log(f"[face] Found {len(boxes)} face(s) in {os.path.basename(path)}.")

    faces = []
    for box, points in zip(boxes, landmarks):
        face = {
            "box": [int(round(v)) for v in box[:4]],
            "score": round(float(box[4]), 4),
            "landmarks": [[int(round(x)), int(round(y))] for x, y in points],
        }

        if recognizer is not None:
            embedding = recognizer.embed(np, Image, image, points)
            face["id"] = recognizer.face_id(np, embedding)
            if not args.no_embeddings:
                face["embedding"] = [round(float(v), 6) for v in embedding]

        faces.append(face)

    return faces


def compare(np, Image, detector, recognizer, args) -> dict:
    """Matches the most confident face of each image; the result says which faces were used."""
    if recognizer is None:
        raise RuntimeError("comparing faces needs the recognition model, so --compare rules out --detect-only")

    # The embeddings are what the match is computed from, so they are always read here and only
    # dropped from the result afterwards when the caller did not ask for them.
    report_embeddings = not args.no_embeddings
    args.no_embeddings = False

    sides = {}
    for side, path in (("a", args.image), ("b", args.compare)):
        faces = read_faces(np, Image, path, detector, recognizer, args)
        sides[side] = {
            "image": os.path.abspath(path),
            "count": len(faces),
            "face": faces[0] if faces else None,
        }

    vectors = []
    for side in ("a", "b"):
        face = sides[side]["face"]
        if face is None:
            vectors.append(None)
            continue
        vectors.append(np.asarray(face["embedding"], dtype=np.float64))
        if not report_embeddings:
            del face["embedding"]

    if vectors[0] is None or vectors[1] is None:
        # Reported as a result, not an error: "no face in this one" is an answer the caller acts on.
        return {"similarity": None, "same_person": False, "threshold": args.threshold, **sides}

    # Both vectors are L2-normalized by the recognizer, so the dot product is the cosine similarity.
    similarity = float(np.dot(vectors[0], vectors[1]))

    return {
        "similarity": round(similarity, 4),
        "same_person": similarity >= args.threshold,
        "threshold": args.threshold,
        **sides,
    }


def load_image(Image, path: str):
    if not os.path.isfile(path):
        raise FileNotFoundError(f"image not found: {path}")

    with Image.open(path) as image:
        # EXIF-rotated photos would otherwise be read sideways, where faces are found poorly.
        from PIL import ImageOps

        return ImageOps.exif_transpose(image).convert("RGB")


def pick_providers(ort, device: str | None) -> list[str]:
    """onnxruntime execution providers to try, in order; a requested one that is missing is a hard error."""
    available = ort.get_available_providers()
    wanted = {
        "cpu": "CPUExecutionProvider",
        "cuda": "CUDAExecutionProvider",
        "coreml": "CoreMLExecutionProvider",
        "rocm": "ROCMExecutionProvider",
        "directml": "DmlExecutionProvider",
        "tensorrt": "TensorrtExecutionProvider",
    }

    if device is not None:
        name = wanted.get(device.lower(), device)
        if name not in available:
            raise RuntimeError(
                f"{device} is not available in this build of onnxruntime; it has {', '.join(available)}"
            )
        return [name] if name == "CPUExecutionProvider" else [name, "CPUExecutionProvider"]

    # CoreML is deliberately not in here. Measured on the InsightFace packs it runs several times
    # SLOWER than plain CPU, because partitioning and compiling graphs this small costs more than
    # the inference it saves; it stays reachable through --device for anyone who measures otherwise.
    # Azure is a cloud endpoint rather than a local device, so it is never picked either.
    preferred = ["CUDAExecutionProvider", "ROCMExecutionProvider", "CPUExecutionProvider"]

    return [name for name in preferred if name in available] or ["CPUExecutionProvider"]


class Detector:
    """SCRFD: finds faces and their five landmarks. Decoding follows InsightFace's own model_zoo/scrfd.py."""

    def __init__(self, ort, np, path: str, providers: list[str], det_size: int) -> None:
        if not os.path.isfile(path):
            raise FileNotFoundError(f"detection model not found: {path}")

        self.np = np
        self.session = ort.InferenceSession(path, providers=providers)
        self.input_name = self.session.get_inputs()[0].name
        self.outputs = [output.name for output in self.session.get_outputs()]
        self.size = det_size

        # SCRFD variants differ in how many feature maps and anchors they use; the output count tells which.
        count = len(self.outputs)
        if count in (6, 9):
            self.strides = [8, 16, 32]
        elif count in (10, 15):
            self.strides = [8, 16, 32, 64, 128]
        else:
            raise RuntimeError(f"{path} has {count} outputs; this is not an SCRFD face detector")

        self.fmc = len(self.strides)
        self.anchors = 2 if count in (9, 15) else 1
        self.use_landmarks = count in (9, 15)

        if not self.use_landmarks:
            raise RuntimeError(
                f"{path} does not predict landmarks, which are needed to align faces for recognition"
            )

        # The size the graph was exported for wins over --det-size, because running an SCRFD graph
        # at any other size fails outright or, worse, decodes against the wrong anchor grid.
        baked = self.baked_size()
        if baked is not None and baked != self.size:
            log(f"[face] This detector is fixed at {baked}px, so --det-size {self.size} is ignored.")
            self.size = baked

    def baked_size(self) -> int | None:
        """The input size the graph was exported for, or None when it really does accept any size.

        The declared input shape is often dynamic even when the graph is not: the InsightFace
        detectors publish ("?", "?") spatial dimensions but keep static output shapes. The stride-8
        score output has (size / 8) ** 2 * anchors rows, which gives the size back.
        """
        shape = self.session.get_inputs()[0].shape
        if isinstance(shape[2], int) and isinstance(shape[3], int):
            return shape[2]

        rows = self.session.get_outputs()[0].shape[0]
        if not isinstance(rows, int) or rows <= 0:
            return None

        side = round((rows / self.anchors) ** 0.5)

        return side * self.strides[0] if side > 0 else None

    def detect(self, image, min_score: float):
        np = self.np
        width, height = image.size

        # Resized to fit the square input, keeping the aspect ratio, and padded with black.
        from PIL import Image as PILImage

        scale = min(self.size / width, self.size / height)
        resized = image.resize((max(1, int(width * scale)), max(1, int(height * scale))))
        padded = PILImage.new("RGB", (self.size, self.size), (0, 0, 0))
        padded.paste(resized, (0, 0))

        blob = (np.asarray(padded, dtype=np.float32) - 127.5) / 128.0
        blob = np.ascontiguousarray(blob.transpose(2, 0, 1)[None])

        predictions = self.session.run(self.outputs, {self.input_name: blob})

        scores_all, boxes_all, landmarks_all = [], [], []
        for index, stride in enumerate(self.strides):
            scores = predictions[index].reshape(-1)
            deltas = predictions[index + self.fmc].reshape(scores.shape[0], -1) * stride
            points = predictions[index + self.fmc * 2].reshape(scores.shape[0], -1) * stride

            centers = self.centers(stride)
            keep = np.where(scores >= min_score)[0]
            if keep.size == 0:
                continue

            centers, deltas, points = centers[keep], deltas[keep], points[keep]

            # The network predicts distances from each anchor centre to the four box edges.
            boxes = np.stack([
                centers[:, 0] - deltas[:, 0],
                centers[:, 1] - deltas[:, 1],
                centers[:, 0] + deltas[:, 2],
                centers[:, 1] + deltas[:, 3],
            ], axis=-1)

            landmarks = points.reshape(points.shape[0], -1, 2) + centers[:, None, :]

            scores_all.append(scores[keep])
            boxes_all.append(boxes)
            landmarks_all.append(landmarks)

        if not scores_all:
            return np.zeros((0, 5), dtype=np.float32), np.zeros((0, 5, 2), dtype=np.float32)

        scores = np.concatenate(scores_all)
        boxes = np.concatenate(boxes_all) / scale
        landmarks = np.concatenate(landmarks_all) / scale

        order = scores.argsort()[::-1]
        scores, boxes, landmarks = scores[order], boxes[order], landmarks[order]

        kept = nms(np, boxes, scores, NMS_IOU)
        boxes = np.concatenate([boxes[kept], scores[kept, None]], axis=-1)

        # Boxes may stick out of the image where a face is cut off by its edge.
        boxes[:, 0:4:2] = boxes[:, 0:4:2].clip(0, width)
        boxes[:, 1:4:2] = boxes[:, 1:4:2].clip(0, height)

        return boxes, landmarks[kept]

    def centers(self, stride: int):
        """Centre of every anchor of one feature map, in input pixels; computed once per stride."""
        np = self.np
        cached = getattr(self, "_centers", None)
        if cached is None:
            cached = self._centers = {}
        if stride in cached:
            return cached[stride]

        side = self.size // stride
        grid = np.stack(np.mgrid[:side, :side][::-1], axis=-1).astype(np.float32) * stride
        points = grid.reshape(-1, 2)
        if self.anchors > 1:
            points = np.stack([points] * self.anchors, axis=1).reshape(-1, 2)

        cached[stride] = points

        return points


class Recognizer:
    """ArcFace: turns an aligned face into a 512-number embedding, and that into a face id."""

    def __init__(self, ort, np, path: str, providers: list[str], hash_bits: int) -> None:
        if not os.path.isfile(path):
            raise FileNotFoundError(f"recognition model not found: {path}")

        self.session = ort.InferenceSession(path, providers=providers)
        self.input_name = self.session.get_inputs()[0].name
        self.hash_bits = hash_bits
        self.projection = None

        shape = self.session.get_inputs()[0].shape
        self.size = shape[2] if isinstance(shape[2], int) else ALIGNED_SIZE

    def embed(self, np, Image, image, landmarks):
        aligned = align(np, Image, image, landmarks, self.size)

        blob = (np.asarray(aligned, dtype=np.float32) - 127.5) / 127.5
        blob = np.ascontiguousarray(blob.transpose(2, 0, 1)[None])

        embedding = self.session.run(None, {self.input_name: blob})[0][0].astype(np.float64)
        norm = np.linalg.norm(embedding)

        if norm == 0:
            raise RuntimeError("the recognition model returned an empty embedding")

        # Normalized here so every later comparison is a plain dot product.
        return embedding / norm

    def face_id(self, np, embedding) -> str:
        """Signs the embedding against fixed random hyperplanes: close directions share most bits."""
        if self.projection is None:
            # RandomState, not default_rng: NumPy freezes its stream forever, so ids stay stable
            # across NumPy versions. Changing this means bumping HASH_VERSION.
            self.projection = np.random.RandomState(HASH_SEED).standard_normal((embedding.shape[0], self.hash_bits))

        bits = (embedding @ self.projection) > 0

        return f"v{HASH_VERSION}:{self.hash_bits}:{np.packbits(bits).tobytes().hex()}"


def align(np, Image, image, landmarks, size: int):
    """Warps the face so its five landmarks land on ArcFace's reference positions."""
    template = np.array([
        [38.2946, 51.6963],
        [73.5318, 51.5014],
        [56.0252, 71.7366],
        [41.5493, 92.3655],
        [70.7299, 92.2041],
    ], dtype=np.float64) * (size / ALIGNED_SIZE)

    transform = similarity_transform(np, np.asarray(landmarks, dtype=np.float64), template)
    if transform is None:
        raise RuntimeError("the detected landmarks are degenerate, so the face cannot be aligned")

    # PIL maps output pixels back to input pixels, so it wants the inverse transform.
    inverse = np.linalg.inv(transform)[:2].reshape(-1).tolist()

    return image.transform((size, size), Image.AFFINE, data=inverse, resample=Image.BILINEAR)


def similarity_transform(np, src, dst):
    """Least-squares rotation, uniform scale and translation mapping src onto dst (Umeyama).

    Returns a 3x3 matrix, or None when the points are collinear and no transform exists.
    """
    count, dim = src.shape
    src_mean, dst_mean = src.mean(axis=0), dst.mean(axis=0)
    src_demean, dst_demean = src - src_mean, dst - dst_mean

    covariance = dst_demean.T @ src_demean / count
    signs = np.ones(dim)
    if np.linalg.det(covariance) < 0:
        signs[dim - 1] = -1

    u, singular, v = np.linalg.svd(covariance)
    rank = np.linalg.matrix_rank(covariance)
    if rank == 0:
        return None

    transform = np.eye(dim + 1)
    if rank == dim - 1 and np.linalg.det(u) * np.linalg.det(v) < 0:
        flipped = signs.copy()
        flipped[dim - 1] = -1
        transform[:dim, :dim] = u @ np.diag(flipped) @ v
    else:
        transform[:dim, :dim] = u @ np.diag(signs) @ v

    variance = src_demean.var(axis=0).sum()
    if variance == 0:
        return None

    scale = (singular @ signs) / variance
    transform[:dim, dim] = dst_mean - scale * (transform[:dim, :dim] @ src_mean)
    transform[:dim, :dim] *= scale

    return transform


def nms(np, boxes, scores, iou: float) -> list[int]:
    """Keeps the most confident of every group of overlapping boxes. Boxes must be sorted by score."""
    x1, y1, x2, y2 = boxes[:, 0], boxes[:, 1], boxes[:, 2], boxes[:, 3]
    areas = (x2 - x1 + 1) * (y2 - y1 + 1)
    order = np.arange(scores.shape[0])
    kept = []

    while order.size > 0:
        best, rest = order[0], order[1:]
        kept.append(int(best))

        width = np.maximum(0.0, np.minimum(x2[best], x2[rest]) - np.maximum(x1[best], x1[rest]) + 1)
        height = np.maximum(0.0, np.minimum(y2[best], y2[rest]) - np.maximum(y1[best], y1[rest]) + 1)
        overlap = width * height

        order = rest[overlap / (areas[best] + areas[rest] - overlap) <= iou]

    return kept


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
