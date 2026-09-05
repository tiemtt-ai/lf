#!/usr/bin/env python3
import argparse
import json
import os
import re
import unicodedata
from pathlib import Path

os.environ["HF_HUB_OFFLINE"] = "1"
os.environ["TRANSFORMERS_OFFLINE"] = "1"
os.environ["HF_HUB_DISABLE_TELEMETRY"] = "1"

from faster_whisper import WhisperModel


def observed_languages(text, candidates):
    counts = {}
    hangul = sum(1 for char in text if ("\u1100" <= char <= "\u11ff") or
                 ("\u3130" <= char <= "\u318f") or ("\uac00" <= char <= "\ud7af"))
    if hangul and "ko" in candidates:
        counts["ko"] = hangul

    latin = sum(1 for char in text if "LATIN" in unicodedata.name(char, ""))
    latin_candidates = [locale for locale in candidates if locale in ("vi", "en")]
    if latin and len(latin_candidates) == 1:
        counts[latin_candidates[0]] = latin
    elif latin and "vi" in latin_candidates and re.search(
            r"[ăâđêôơưáàảãạấầẩẫậắằẳẵặéèẻẽẹếềểễệíìỉĩịóòỏõọốồổỗộớờởỡợúùủũụứừửữựýỳỷỹỵ]", text, re.I):
        counts["vi"] = latin

    return [{"locale": locale, "char_count": count}
            for locale, count in sorted(counts.items(), key=lambda item: (-item[1], item[0]))]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", required=True)
    language = parser.add_mutually_exclusive_group(required=True)
    language.add_argument("--locale", choices=["vi", "ko", "en"])
    language.add_argument("--locales")
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

    candidates = [args.locale] if args.locale else args.locales.split(",")
    if not candidates or len(candidates) > 3 or len(set(candidates)) != len(candidates) or \
            any(locale not in ("vi", "ko", "en") for locale in candidates):
        print(json.dumps({"status": "failed", "error_code": "speech_language_profile_invalid"}))
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
            "languages": observed_languages(text, candidates),
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
