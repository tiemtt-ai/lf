# A0 Docling Closure Evidence

Version: 1.0

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-25

Document Path: quality/LF-A0-Docling-Closure-Evidence.md

Related Specification:
[LF-A0-Docling-Benchmark-Protocol](../../LF-A0-Docling-Benchmark-Protocol.md) v1.3

---

# Purpose

The A0 Closure Record cites benchmark run `a0-fair-baseline-20260825`. The
harness and its results directory were removed on 2026-08-25 by Owner decision,
so **this document is the only surviving copy of that report**. Reproducing the
run would require rebuilding the harness from scratch.

The report below is reproduced verbatim. It is evidence, not analysis: the
decision it supports is recorded in the protocol's Closure Record, not here.

# What this evidence is and is not

* Mode is `exploratory`. **A0 official was never run** — the corpus was never
  Owner-approved, 16 of 18 fixtures are synthetic, and the four quality
  thresholds were never frozen. The verdict line below reads
  `OWNER_DECISION_REQUIRED` and must not be read as `A0_FAIL`.
* The `baseline` pipeline in this run mirrors
  `LocalDocumentProcessingProvider::pdfUnits` **after** the all-or-nothing
  page-loss defect was removed. Earlier runs in the same results directory
  compared Docling against a baseline that silently dropped scanned pages of
  mixed documents; their numbers overstate Docling and must not be quoted.
* It carries no evidence about real scanned print material. Docling's
  multi-column advantage was measured on synthetic fixtures only.

---

## LF A0 Docling Benchmark Report

Mode: **exploratory**
Verdict: **OWNER_DECISION_REQUIRED**
Non-official: **true**
Thresholds applied: **false**

> Exploratory evidence only. This report cannot authorize A1, runtime deployment, a provider binding, or a Tech Stack change.

### Environment

- `binary_pdfinfo`: `/usr/local/bin/pdfinfo`
- `binary_pdftoppm`: `/usr/local/bin/pdftoppm`
- `binary_pdftotext`: `/usr/local/bin/pdftotext`
- `binary_soffice`: `/usr/local/bin/soffice`
- `binary_tesseract`: `/usr/local/bin/tesseract`
- `config_sha256`: `969781652813fb1365cb3b6c8a30fc72b22471e37c4e943629b66f9cc4e8452d`
- `docling`: `2.119.0`
- `docling-core`: `2.92.0`
- `docling-ibm-models`: `3.14.0`
- `docling-parse`: `7.15.0`
- `huggingface-hub`: `0.36.2`
- `model_artifact_files`: `60`
- `model_inventory_sha256`: `c3ffba780d5d4dffa6e4f469fc82bedbce1839d7d9e71deba60934196d1284b4`
- `platform`: `darwin`
- `psutil`: `7.0.0`
- `python`: `3.11.16`
- `version_pdfinfo`: `pdfinfo version 26.03.0`
- `version_pdftoppm`: `pdftoppm version 26.03.0`
- `version_pdftotext`: `pdftotext version 26.03.0`
- `version_soffice`: `LibreOffice 26.2.5.2 cd7284b4cbbfeb507e630c1aac019f4157393acb`
- `version_tesseract`: `tesseract 5.5.1`

### Corpus manifest

- Corpus: `lf-a0-PENDING_OWNER_ASSIGNED_ID`
- Revision: `PENDING_OWNER_REVISION`
- Documents: `18`

### Pending Owner decisions / corpus gaps

- manifest is not Owner-approved with identity and date
- vi/S1: 0 < 10
- vi/S2: 1 < 10
- vi/S3: 1 < 15
- vi/S4: 1 < 15
- vi/S5: 1 < 10
- vi/S6: 1 < 5
- vi/S7: 0 < 5
- vi/S8: 0 < 3
- vi/S9: 0 < 5
- vi/S10: 1 < 3
- vi/S11: 1 < 10
- vi/S12: 1 < 10
- vi/S13: 0 < 6
- vi/S14: 0 < 6
- ko/S1: 0 < 10
- ko/S2: 1 < 10
- ko/S3: 1 < 15
- ko/S4: 1 < 15
- ko/S5: 1 < 10
- ko/S6: 1 < 5
- ko/S7: 0 < 5
- ko/S8: 0 < 3
- ko/S9: 0 < 5
- ko/S10: 1 < 3
- ko/S11: 1 < 10
- ko/S12: 1 < 10
- ko/S13: 2 < 6
- ko/S14: 0 < 6
- Owner CER sample minimum not frozen: cer_documents_min_per_locale
- ko-s13-korean-beginner-100-001: official fixture is not eligible for quality metrics
- Owner quality thresholds not frozen: vi_cer_raw_max, vi_cer_diacritic_stripped_max, ko_cer_max, page_coverage_min

### Metrics not available

- ko-s13-korean-beginner-100-001: fixture is not eligible for quality or performance metrics
- child-process peak RSS is unavailable because the operating system denied process-tree inventory; main worker RSS is reported

### S1–S10 decision gates

| Stratum | Documents | Status | Failures |
|---|---:|---|---:|
| S1 | 0 | NOT_RUN | 0 |
| S2 | 2 | OBSERVED_WITH_ERRORS | 2 |
| S3 | 2 | OBSERVED_WITH_ERRORS | 1 |
| S4 | 2 | OBSERVED_WITH_ERRORS | 1 |
| S5 | 2 | OBSERVED_WITH_ERRORS | 1 |
| S6 | 2 | OBSERVED_WITH_ERRORS | 2 |
| S7 | 0 | NOT_RUN | 0 |
| S8 | 0 | NOT_RUN | 0 |
| S9 | 0 | NOT_RUN | 0 |
| S10 | 2 | OBSERVED | 0 |

### S11–S14 resource/error strata

| Stratum | Documents | Status | Failures |
|---|---:|---|---:|
| S11 | 2 | OBSERVED | 0 |
| S12 | 2 | OBSERVED | 0 |
| S13 | 1 | OBSERVED | 0 |
| S14 | 0 | NOT_RUN | 0 |

### Performance by pipeline

| Pipeline | p50 s/page | p95 s/page | worst s/page | p99 document s | worst document s | peak RSS bytes |
|---|---:|---:|---:|---:|---:|---:|
| baseline | 0.1344965171332054 | 1.731975281187431 | 1.7485093092495845 | 6.980810014548615 | 6.994037236998338 | 147615744 |
| docling | 3.775031041166888 | 4.005466041062732 | 4.016986498750157 | 16.05872962885069 | 16.067945995000628 | 2226974720 |

### Locale-specific quality

| Locale | Engine | Documents | CER raw | CER stripped | Coverage |
|---|---|---:|---:|---:|---:|
| vi | baseline | 8 | 0.05958620030444443 | 0.054699393643378114 | 1.0 |
| vi | docling | 8 | 0.06793932323212262 | 0.06680663661515608 | 1.0 |
| ko | baseline | 8 | 0.12897702647411605 | 0.12971638265521895 | 1.0 |
| ko | docling | 8 | 0.17213633102062978 | 0.15820405436611928 | 1.0 |
| en | baseline | 0 | None | None | None |
| en | docling | 0 | None | None | None |

### Evidence tiers

CER needs transcribed ground truth; coverage and parity need only a per-page content flag. The two tiers are counted separately and never pooled.

| Locale | Engine | CER documents | Coverage documents |
|---|---|---:|---:|
| vi | baseline | 8 | 8 |
| vi | docling | 8 | 8 |
| ko | baseline | 8 | 8 |
| ko | docling | 8 | 8 |
| en | baseline | 0 | 0 |
| en | docling | 0 | 0 |

### Citation / layout parity regressions

| Document | Pipeline | Boundary min | Adjacent violation | Reading order complete/tau | Blank violation | Page count match |
|---|---|---:|---|---|---|---|
| vi-s2-synthetic-multicol-001 | baseline | 0.6078431372549019 | False | False/1.0 | False | True |
| vi-s2-synthetic-multicol-001 | docling | 0.8235294117647058 | False | True/1.0 | False | True |
| vi-s6-synthetic-rotation-landscape-001 | docling | 0.6842105263157895 | False | True/None | False | True |
| vi-s12-synthetic-chart-diagram-001 | baseline | 0.7857142857142857 | False | True/None | False | True |
| ko-s2-synthetic-multicol-001 | baseline | 0.6571428571428571 | False | True/1.0 | False | True |
| ko-s2-synthetic-multicol-001 | docling | 0.782608695652174 | False | True/1.0 | False | True |
| ko-s3-synthetic-clean-scan-001 | baseline | 0.35714285714285715 | True | True/None | False | True |
| ko-s3-synthetic-clean-scan-001 | docling | 0.35714285714285715 | False | True/None | False | True |
| ko-s4-synthetic-poor-scan-001 | baseline | 0.5882352941176471 | False | True/None | False | True |
| ko-s4-synthetic-poor-scan-001 | docling | 0.4782608695652174 | False | True/None | False | True |
| ko-s5-synthetic-mixed-001 | baseline | 0.4444444444444444 | False | True/None | False | True |
| ko-s5-synthetic-mixed-001 | docling | 0.3076923076923077 | True | True/None | False | True |
| ko-s6-synthetic-rotation-landscape-001 | docling | 0.5 | False | True/None | False | True |
| ko-s12-synthetic-chart-diagram-001 | baseline | 0.7857142857142857 | False | True/None | False | True |

Budget: p95 ≤ 33 seconds/page; every decision stratum must pass independently. VI and KO metrics remain separate in `result.json` and `per_document.csv`; no pooled-only decision is used.

### Evidence and decision boundary

- Expected-error fixtures are preflight-only and excluded from OCR, quality, and performance metrics.
- A metric is `unavailable` when corpus eligibility or ground truth does not support it; it is never replaced with zero or pass.
- Threshold recommendations are withheld until Owner-approved VI/KO ground truth and representative real-source coverage are sufficient.
- Per-process timings include model cold start because each fixture is isolated; they are evidence for this harness run, not steady-state AWS capacity sizing.

### A1 gates

This report does not authorize A1 or runtime deployment. Before A1, Owner must explicitly approve the corpus and four quality thresholds, review all A0 regressions, approve a Tech Stack amendment, establish pinned local/AWS binary-model-config parity, apply PII retention/deletion controls, and pass Architecture Review.
