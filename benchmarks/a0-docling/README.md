# LF A0 Docling offline benchmark

This directory is an isolated decision harness. It compares Docling with the
current Poppler/Tesseract behavior and cannot be selected by Laravel, `.env`, a
service binding, or a queue worker.

## Run

1. Bootstrap or verify the isolated Python 3.11 environment with
   `./benchmarks/a0-docling/run.sh --help`. By default this uses
   `/usr/local/opt/python@3.11/bin/python3.11`, creates `.venv-py311`, and
   installs only the pinned benchmark dependencies. Override only with an
   explicitly verified Python 3.11 executable via `A0_PYTHON` or an isolated
   environment via `A0_VENV`.
2. Prefetch the pinned Docling artifacts once while network access is explicitly
   allowed: `.venv/bin/docling-tools models download --output-dir benchmarks/a0-docling/models`
   (the exact command supported by the pinned release must be recorded with the
   corpus approval). Benchmark execution itself forces Hugging Face and
   Transformers offline mode.
3. Place the candidate or approved corpus as described in `corpus/README.md`. A PII-bearing
   corpus is eligible only under the local-only approval, access, provenance and
   retention/deletion policy in ADR-0018; PII approval never permits an
   external call.
4. Run one of the explicit modes below. The default is `official`.
5. Results are written to ignored `results/<run-id>/`: `result.json`,
   `per_document.csv`, and `report.md`.

The previous `.venv` is retained as Python 3.12 incompatibility evidence and is
not selected by the launcher. Docling 2.119.0/PyTorch 2.2.2 layout initialization
fails there with `Dynamo is not supported on Python 3.12+`; the A0 launcher
therefore refuses any interpreter other than Python 3.11.

The requirements also pin NumPy 1.26 and Transformers 4.x. On macOS x86_64,
Docling resolves PyTorch 2.2.2: NumPy 2.x breaks that wheel's ABI and
Transformers 5.x disables PyTorch versions below 2.4. These are benchmark-only
compatibility constraints.

Use `--corpus /absolute/path/manifest.json` for an externally mounted
corpus, `--output /path` to select a result root, and `--run-id ID` for a stable
run identity. `A0_SKIP_INSTALL=1` skips environment installation. No input is
uploaded. Model artifacts stay in the ignored benchmark directory and their
inventory fingerprint is recorded in every result.

Ground truth JSON contains `pages`, an array of `{page, text}` objects. Optional
`reading_order` is an array of tokens/region IDs. Blank pages are represented by
an empty `text`: the physical page remains in `page_map`, but no extracted text
unit is emitted. Optional manifest fields are `expected_error`, `tags`, and
`reading_order`.

An `expected_error` negative fixture does not require `ground_truth`. The runner
performs only the preflight needed to reproduce the named contract error; such a
row is excluded from CER, coverage and performance aggregation. In particular,
a PDF over 100 pages must stop at `pdfinfo` with `page_limit_exceeded`, before
Poppler text extraction, rendering, Tesseract or Docling model execution.

## Execution modes

Official mode is the default and remains fail-closed:

```bash
./benchmarks/a0-docling/run.sh \
  --mode official \
  --corpus /absolute/path/manifest.json
```

It does not process documents unless corpus approval, VI/KO stratum minima,
source integrity and all four Owner thresholds pass validation. Only this mode
may emit `A0_PASS` or `A0_FAIL`. Missing approval or thresholds produces
`DECISION_REQUIRED` with zero processing rows.

Exploratory mode collects evidence needed to propose thresholds:

```bash
./benchmarks/a0-docling/run.sh \
  --mode exploratory \
  --corpus /absolute/path/manifest.json
```

It may process eligible candidate fixtures while approval and thresholds remain
pending. Its verdict is always `OWNER_DECISION_REQUIRED`; result JSON records
`non_official: true` and `thresholds_applied: false`. It never substitutes
threshold values and cannot authorize A1/runtime.

Both modes enforce manifest structure, source SHA-256, canonical locale,
local-only PII governance, external-processing prohibition, retention/deletion,
safe corpus paths and resource preflight. Fixtures with draft ground truth or
both quality/performance eligibility disabled are excluded from those metrics.
Expected-error fixtures run only contract preflight and never contribute CER,
coverage or latency proposals.

The runner enforces canonical OCR languages (`vi=vie+eng`, `ko=kor+eng`,
`en=eng`), resource limits before/after conversion, per-page citation metrics,
VI raw/diacritic-stripped CER, KO CER, per-page coverage, p50/p95 latency, peak
RSS and pages/minute. Every S1–S10 stratum must pass independently; missing
corpus, model identity, or Owner thresholds produces `DECISION_REQUIRED`.

The macOS x86_64 benchmark pins Docling inference to CPU and disables layout
`torch.compile`. Auto device selection incorrectly selects an MPS/Inductor path
that PyTorch 2.2.2 cannot compile on this host. These settings are explicit in
`config.json`, included in its hash, and must be reproduced or deliberately
amended before comparing local and AWS runs.

Coverage counts only pages that contain text in ground truth. Blank pages are a
separate citation check: they emit no text unit while real page numbering remains
stable. The proposed target for S5 and ordinary documents is `1.00`; the
machine-readable `page_coverage_min` remains `null` until Owner approval, and a
result below `1.00` is investigation evidence rather than A0-pass evidence.

Run harness checks with:

```bash
./benchmarks/a0-docling/validate.sh
```
