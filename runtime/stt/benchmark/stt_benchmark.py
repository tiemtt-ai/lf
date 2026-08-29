#!/usr/bin/env python3
import argparse
import csv
import hashlib
import json
import os
import platform
import re
import resource
import statistics
import subprocess
import sys
import time
import unicodedata
from pathlib import Path

from faster_whisper import WhisperModel
import ctranslate2
import faster_whisper

LOCALES = {"vi": "vi", "ko": "ko", "en": "en"}


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def edit_distance(left, right):
    previous = list(range(len(right) + 1))
    for i, lhs in enumerate(left, 1):
        current = [i]
        for j, rhs in enumerate(right, 1):
            current.append(min(current[-1] + 1, previous[j] + 1,
                               previous[j - 1] + (lhs != rhs)))
        previous = current
    return previous[-1]


def normalized_words(text: str):
    text = unicodedata.normalize("NFC", text).lower()
    return re.findall(r"\w+", text, flags=re.UNICODE)


def normalized_chars(text: str):
    text = unicodedata.normalize("NFC", text).lower()
    return [char for char in text if not char.isspace()]


def rate(reference, hypothesis, unit):
    ref = normalized_words(reference) if unit == "word" else normalized_chars(reference)
    hyp = normalized_words(hypothesis) if unit == "word" else normalized_chars(hypothesis)
    return None if not ref else edit_distance(ref, hyp) / len(ref)


def duration_seconds(path: Path) -> float:
    command = ["ffprobe", "-v", "error", "-show_entries", "format=duration",
               "-of", "default=noprint_wrappers=1:nokey=1", str(path)]
    return float(subprocess.check_output(command, text=True).strip())


def percentile(values, quantile):
    if not values:
        return None
    ordered = sorted(values)
    index = int(round((len(ordered) - 1) * quantile))
    return ordered[index]


def validate_manifest(data):
    if data.get("schema_version") != 1 or not isinstance(data.get("fixtures"), list):
        raise ValueError("manifest_invalid")
    for fixture in data["fixtures"]:
        required = {"id", "locale", "audio_path", "sha256", "reference_path",
                    "synthetic", "contains_pii", "purpose"}
        if required - fixture.keys() or fixture["locale"] not in LOCALES:
            raise ValueError(f"manifest_invalid:{fixture.get('id', 'unknown')}")


def main():
    parser = argparse.ArgumentParser(description="Offline faster-whisper exploratory benchmark")
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--model", required=True)
    parser.add_argument("--run-id", required=True)
    parser.add_argument("--output-root", default=str(Path(__file__).parent / "results"))
    parser.add_argument("--compute-type", default="int8")
    parser.add_argument("--threads", type=int, default=0)
    args = parser.parse_args()

    manifest_path = Path(args.manifest).resolve()
    model_path = Path(args.model).resolve()
    if not manifest_path.is_file() or not model_path.is_dir():
        raise SystemExit("manifest_or_local_model_unavailable")
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    validate_manifest(manifest)

    output = Path(args.output_root).resolve() / args.run_id
    segment_dir = output / "segments"
    segment_dir.mkdir(parents=True, exist_ok=False)

    model = WhisperModel(str(model_path), device="cpu", compute_type=args.compute_type,
                         cpu_threads=args.threads)
    rows = []
    latencies = []
    real_quality = 0

    for fixture in manifest["fixtures"]:
        audio = Path(fixture["audio_path"]).resolve()
        reference_path = Path(fixture["reference_path"]).resolve()
        if not audio.is_file() or not reference_path.is_file():
            raise RuntimeError(f"fixture_unavailable:{fixture['id']}")
        if sha256(audio) != fixture["sha256"]:
            raise RuntimeError(f"source_hash_mismatch:{fixture['id']}")
        reference = reference_path.read_text(encoding="utf-8")
        duration = duration_seconds(audio)
        started = time.perf_counter()
        generated, info = model.transcribe(str(audio), language=LOCALES[fixture["locale"]],
                                           vad_filter=False, beam_size=5)
        segments = []
        previous_end = None
        overlap = adjacent = zero_length = 0
        for index, segment in enumerate(generated, 1):
            start_ms = round(segment.start * 1000)
            end_ms = round(segment.end * 1000)
            if start_ms == end_ms:
                zero_length += 1
            if previous_end is not None:
                overlap += start_ms < previous_end
                adjacent += start_ms == previous_end
            segments.append({"sequence": index, "start_ms": start_ms,
                             "end_ms": end_ms, "text": segment.text})
            previous_end = end_ms
        latency = time.perf_counter() - started
        latencies.append(latency)
        hypothesis = " ".join(item["text"].strip() for item in segments).strip()
        metric = "cer" if fixture["locale"] == "ko" else "wer"
        score = rate(reference, hypothesis, "char" if metric == "cer" else "word")
        if not fixture["synthetic"] and fixture["purpose"] == "quality":
            real_quality += 1
        segment_payload = {
            "fixture_id": fixture["id"], "locale": fixture["locale"],
            "source_sha256": fixture["sha256"], "language_probability": info.language_probability,
            "segments": segments,
        }
        (segment_dir / f"{fixture['id']}.json").write_text(
            json.dumps(segment_payload, ensure_ascii=False, indent=2), encoding="utf-8")
        rows.append({
            "fixture_id": fixture["id"], "locale": fixture["locale"],
            "synthetic": fixture["synthetic"], "duration_seconds": round(duration, 3),
            "latency_seconds": round(latency, 3), "rtf": round(latency / duration, 4),
            "metric": metric, "error_rate": None if score is None else round(score, 6),
            "segments": len(segments), "adjacent_pairs": adjacent,
            "total_adjacent_pairs": max(0, len(segments) - 1),
            "overlap_pairs": overlap, "zero_length_segments": zero_length,
        })

    with (output / "per_fixture.csv").open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=list(rows[0].keys()) if rows else [])
        if rows:
            writer.writeheader()
            writer.writerows(rows)

    verdict = "OWNER_DECISION_REQUIRED" if real_quality else "OWNER_FIXTURE_REQUIRED"
    result = {
        "run_id": args.run_id, "verdict": verdict, "non_official": True,
        "engine": "faster-whisper", "engine_version": faster_whisper.__version__,
        "ctranslate2_version": ctranslate2.__version__, "model_path": str(model_path),
        "model_files_sha256": {str(path.relative_to(model_path)): sha256(path)
                               for path in sorted(model_path.rglob("*")) if path.is_file()},
        "manifest_sha256": sha256(manifest_path), "fixtures": rows,
        "latency_seconds": {"p50": percentile(latencies, .50),
                            "p95": percentile(latencies, .95),
                            "worst": max(latencies) if latencies else None},
        "peak_rss_mb": round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss /
                             (1024 * 1024 if sys.platform == "darwin" else 1024), 2),
        "quality_real_fixture_count": real_quality,
    }
    (output / "result.json").write_text(json.dumps(result, ensure_ascii=False, indent=2),
                                         encoding="utf-8")
    environment = {"python": sys.version, "platform": platform.platform(),
                   "offline": True, "compute_type": args.compute_type,
                   "threads": args.threads}
    (output / "environment.json").write_text(json.dumps(environment, indent=2), encoding="utf-8")
    print(json.dumps({"run_id": args.run_id, "verdict": verdict,
                      "fixtures": len(rows)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
