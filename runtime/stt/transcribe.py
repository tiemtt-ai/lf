#!/usr/bin/env python3
import argparse
import json
import os
from pathlib import Path

os.environ["HF_HUB_OFFLINE"] = "1"
os.environ["TRANSFORMERS_OFFLINE"] = "1"
os.environ["HF_HUB_DISABLE_TELEMETRY"] = "1"

from faster_whisper import WhisperModel


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", required=True)
    parser.add_argument("--locale", required=True, choices=["vi", "ko", "en"])
    parser.add_argument("--model", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--compute-type", default="int8")
    parser.add_argument("--threads", type=int, default=0)
    args = parser.parse_args()

    source = Path(args.source)
    model_path = Path(args.model)
    if not source.is_file() or not model_path.is_dir():
        print(json.dumps({"status": "failed", "error_code": "provider_unavailable"}))
        return 2

    model = WhisperModel(str(model_path), device="cpu", compute_type=args.compute_type,
                         cpu_threads=args.threads)
    generated, _ = model.transcribe(str(source), language=args.locale,
                                    vad_filter=False, beam_size=5)
    units = []
    for segment in generated:
        text = segment.text.strip()
        if not text:
            continue
        start_ms = round(segment.start * 1000)
        end_ms = round(segment.end * 1000)
        units.append({
            "locator_type": "timespan",
            "locator_value": f"{start_ms}-{end_ms}",
            "text": text,
            "metadata": {"avg_logprob": segment.avg_logprob},
        })

    if not units:
        print(json.dumps({"status": "failed", "error_code": "no_extractable_text"}))
        return 0

    Path(args.output).write_text(json.dumps({"status": "ready", "units": units},
                                            ensure_ascii=False), encoding="utf-8")
    print(json.dumps({"status": "ready", "units": len(units)}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
