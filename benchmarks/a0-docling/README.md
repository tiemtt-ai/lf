# LF A0 Docling offline benchmark

This directory is an isolated decision harness. It compares Docling with the
current Poppler/Tesseract behavior and cannot be selected by Laravel, `.env`, a
service binding, or a queue worker.

## Run

1. Bootstrap or verify the isolated Python environment with
   `./benchmarks/a0-docling/run.sh --help`. This creates `.venv` and installs
   only the pinned benchmark dependencies.
2. Prefetch the pinned Docling artifacts once while network access is explicitly
   allowed: `.venv/bin/docling-tools models download --output-dir benchmarks/a0-docling/models`
   (the exact command supported by the pinned release must be recorded with the
   corpus approval). Benchmark execution itself forces Hugging Face and
   Transformers offline mode.
3. Place the approved corpus as described in `corpus/README.md`. A PII-bearing
   corpus is eligible only under the local-only approval, access, provenance and
   retention/deletion policy in ADR-0018; PII approval never permits an
   external call.
4. Run `./benchmarks/a0-docling/run.sh` from any directory.
5. Results are written to ignored `results/<run-id>/`: `result.json`,
   `per_document.csv`, and `report.md`.

The requirements also pin NumPy 1.26 and Transformers 4.x. On macOS x86_64,
Docling resolves PyTorch 2.2.2: NumPy 2.x breaks that wheel's ABI and
Transformers 5.x disables PyTorch versions below 2.4. These are benchmark-only
compatibility constraints.

Use `--corpus /absolute/path/manifest.json` for an externally mounted approved
corpus, `--output /path` to select a result root, and `--run-id ID` for a stable
run identity. `A0_SKIP_INSTALL=1` skips environment installation. No input is
uploaded. Model artifacts stay in the ignored benchmark directory and their
inventory fingerprint is recorded in every result.

Ground truth JSON contains `pages`, an array of `{page, text}` objects. Optional
`reading_order` is an array of tokens/region IDs. Blank pages are represented by
an empty `text`: the physical page remains in `page_map`, but no extracted text
unit is emitted. Optional manifest fields are `expected_error`, `tags`, and
`reading_order`.

The runner enforces canonical OCR languages (`vi=vie+eng`, `ko=kor+eng`,
`en=eng`), resource limits before/after conversion, per-page citation metrics,
VI raw/diacritic-stripped CER, KO CER, per-page coverage, p50/p95 latency, peak
RSS and pages/minute. Every S1–S10 stratum must pass independently; missing
corpus, model identity, or Owner thresholds produces `DECISION_REQUIRED`.

Coverage counts only pages that contain text in ground truth. Blank pages are a
separate citation check: they emit no text unit while real page numbering remains
stable. The proposed target for S5 and ordinary documents is `1.00`; the
machine-readable `page_coverage_min` remains `null` until Owner approval, and a
result below `1.00` is investigation evidence rather than A0-pass evidence.

Run harness checks with:

```bash
./benchmarks/a0-docling/validate.sh
```
