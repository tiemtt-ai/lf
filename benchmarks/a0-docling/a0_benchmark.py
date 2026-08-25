#!/usr/bin/env python3
"""Offline A0 comparison harness. It has no LearnForge runtime imports."""
from __future__ import annotations

import argparse
import csv
import hashlib
import importlib.metadata
import importlib.util
import json
import math
import os
import pathlib
import re
import shutil
import statistics
import subprocess
import sys
import tempfile
import time
import unicodedata
import zipfile
import xml.etree.ElementTree as ET
from collections import Counter, defaultdict
from datetime import datetime, timezone

ROOT = pathlib.Path(__file__).resolve().parent
MINIMUMS = {"S1": 10, "S2": 10, "S3": 15, "S4": 15, "S5": 10,
            "S6": 5, "S7": 5, "S8": 3, "S9": 5, "S10": 3,
            "S11": 10, "S12": 10, "S13": 6, "S14": 6}
DECISION_STRATA = {f"S{i}" for i in range(1, 11)}
SUPPORTED = {".pdf", ".docx", ".doc", ".xls", ".xlsx", ".ppt", ".pptx", ".txt"}


class BenchmarkError(RuntimeError):
    def __init__(self, code, message):
        super().__init__(message)
        self.code = code


def normalize(text):
    return " ".join(str(text or "").split())


def tokens(text):
    return re.findall(r"\w+", normalize(text).casefold(), flags=re.UNICODE)


def strip_diacritics(text):
    return "".join(c for c in unicodedata.normalize("NFD", text) if unicodedata.category(c) != "Mn")


def edit_distance(a, b):
    if len(a) < len(b): a, b = b, a
    row = list(range(len(b) + 1))
    for i, ca in enumerate(a, 1):
        nxt = [i]
        for j, cb in enumerate(b, 1):
            nxt.append(min(nxt[-1] + 1, row[j] + 1, row[j - 1] + (ca != cb)))
        row = nxt
    return row[-1]


def cer(reference, candidate):
    reference, candidate = normalize(reference), normalize(candidate)
    return edit_distance(reference, candidate) / max(1, len(reference))


def lcs_length(a, b):
    row = [0] * (len(b) + 1)
    for x in a:
        prev = 0
        for j, y in enumerate(b, 1):
            old = row[j]
            row[j] = prev + 1 if x == y else max(row[j], row[j - 1])
            prev = old
    return row[-1]


def boundary_score(reference, candidate):
    a, b = tokens(reference), tokens(candidate)
    return lcs_length(a, b) / max(1, len(a))


def kendall_tau(reference, candidate):
    pos = {value: i for i, value in enumerate(candidate)}
    seq = [pos[x] for x in reference if x in pos]
    if len(seq) < 2: return 1.0 if seq else 0.0
    discordant = sum(seq[i] > seq[j] for i in range(len(seq)) for j in range(i + 1, len(seq)))
    pairs = len(seq) * (len(seq) - 1) / 2
    return 1 - 2 * discordant / pairs


def percentile(values, pct):
    if not values: return None
    values = sorted(values)
    index = (len(values) - 1) * pct
    lower, upper = math.floor(index), math.ceil(index)
    if lower == upper: return values[lower]
    return values[lower] * (upper - index) + values[upper] * (index - lower)


def safe_path(root, relative):
    path = (root / relative).resolve()
    if path != root.resolve() and root.resolve() not in path.parents:
        raise BenchmarkError("source_unavailable", "path escapes corpus root")
    return path


def validate_manifest(path, config):
    errors = []
    if not path.is_file(): return [f"approved corpus manifest missing: {path}"]
    try: data = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc: return [f"manifest invalid: {exc}"]
    approval = data.get("approval", {})
    if approval.get("status") != "approved" or not approval.get("approved_by") or not approval.get("approved_at"):
        errors.append("manifest is not Owner-approved with identity and date")
    contains_pii = data.get("contains_pii")
    if not isinstance(contains_pii, bool):
        errors.append("contains_pii must be explicitly true or false")
    if contains_pii is True:
        governance = data.get("data_governance", {})
        required = (
            "storage_scope",
            "access_restriction",
            "retention_until",
            "deletion_required_by",
            "source_approval_evidence",
        )
        missing = [key for key in required if not governance.get(key)]
        if missing:
            errors.append(f"PII corpus governance missing: {', '.join(missing)}")
        if governance.get("external_processing_allowed") is not False:
            errors.append("PII corpus must explicitly disable external processing")
    counts = Counter((d.get("locale"), d.get("stratum")) for d in data.get("documents", []))
    for locale in ("vi", "ko"):
        for stratum, minimum in MINIMUMS.items():
            if counts[(locale, stratum)] < minimum:
                errors.append(f"{locale}/{stratum}: {counts[(locale, stratum)]} < {minimum}")
    root = path.parent.resolve()
    for doc in data.get("documents", []):
        if doc.get("locale") not in config["locales"]:
            errors.append(f"{doc.get('id')}: unsupported or missing canonical locale")
        for key in ("source", "ground_truth"):
            try: candidate = safe_path(root, doc.get(key, ""))
            except BenchmarkError as exc: errors.append(f"{doc.get('id')}: {exc}"); continue
            if not candidate.is_file(): errors.append(f"{doc.get('id')}: missing {key} {candidate}")
    return errors


def command(args, timeout, code="command_failed"):
    try:
        result = subprocess.run(args, capture_output=True, timeout=timeout, check=False)
    except subprocess.TimeoutExpired as exc:
        raise BenchmarkError("provider_timeout", str(exc)) from exc
    except OSError as exc:
        raise BenchmarkError("provider_unavailable", str(exc)) from exc
    if result.returncode:
        raise BenchmarkError(code, result.stderr.decode("utf-8", "replace")[-1000:])
    return result.stdout


def pdf_pages(source, config):
    output = command([config["binaries"]["pdfinfo"], str(source)], config["contract"]["command_timeout_seconds"], "corrupt_source").decode("utf-8", "replace")
    match = re.search(r"^Pages:\s+(\d+)$", output, re.MULTILINE)
    if not match: raise BenchmarkError("corrupt_source", "pdfinfo did not return page count")
    count = int(match.group(1))
    if count > config["contract"]["max_pages"]: raise BenchmarkError("page_limit_exceeded", f"{count} pages")
    return count


def docx_size(source, limit):
    try:
        with zipfile.ZipFile(source) as archive:
            info = archive.getinfo("word/document.xml")
            if info.file_size > limit: raise BenchmarkError("source_expansion_too_large", str(info.file_size))
            total = 0
            with archive.open(info) as stream:
                while chunk := stream.read(65536):
                    total += len(chunk)
                    if total > limit: raise BenchmarkError("source_expansion_too_large", str(total))
    except BenchmarkError: raise
    except Exception as exc: raise BenchmarkError("corrupt_source", str(exc)) from exc


def preflight(source, locale, config):
    if locale not in config["locales"]: raise BenchmarkError("unsupported_source", "unsupported canonical locale")
    if not source.is_file(): raise BenchmarkError("source_unavailable", str(source))
    suffix = source.suffix.lower()
    if suffix not in SUPPORTED: raise BenchmarkError("unsupported_source", suffix)
    if suffix == ".pdf": return pdf_pages(source, config)
    if suffix == ".docx": docx_size(source, config["contract"]["max_docx_xml_bytes"])
    return 1


def baseline_pdf(source, locale, count, config):
    timeout = config["contract"]["command_timeout_seconds"]
    raw = command([config["binaries"]["pdftotext"], "-layout", str(source), "-"], timeout, "command_failed").decode("utf-8", "replace")
    embedded = [{"page": i, "text": normalize(text)} for i, text in enumerate(raw.split("\f")[:count], 1) if normalize(text)]
    if embedded: return embedded
    units = []
    with tempfile.TemporaryDirectory(prefix="lf-a0-") as directory:
        prefix = pathlib.Path(directory) / "page"
        command([config["binaries"]["pdftoppm"], "-r", str(config["contract"]["ocr_dpi"]), "-png", str(source), str(prefix)], timeout)
        language = "+".join(config["locales"][locale])
        for page, image in enumerate(sorted(pathlib.Path(directory).glob("page-*.png")), 1):
            text = command([config["binaries"]["tesseract"], str(image), "stdout", "-l", language], timeout).decode("utf-8", "replace")
            if normalize(text): units.append({"page": page, "text": normalize(text)})
    return units


def baseline(source, locale, count, config):
    if source.suffix.lower() == ".pdf": return baseline_pdf(source, locale, count, config)
    if source.suffix.lower() == ".txt":
        text = source.read_text(encoding="utf-8")
        return [{"page": 1, "text": normalize(text)}] if normalize(text) else []
    if source.suffix.lower() == ".docx":
        try:
            with zipfile.ZipFile(source) as archive:
                root = ET.fromstring(archive.read("word/document.xml"))
            text = " ".join(node.text or "" for node in root.iter() if node.tag.endswith("}t"))
            if normalize(text): return [{"page": 1, "text": normalize(text)}]
        except Exception as exc: raise BenchmarkError("corrupt_source", str(exc)) from exc
    with tempfile.TemporaryDirectory(prefix="lf-a0-office-") as directory:
        profile = pathlib.Path(directory) / "profile"; profile.mkdir(mode=0o700)
        output = pathlib.Path(directory) / "pdf"; output.mkdir()
        command([config["binaries"]["soffice"], "-env:UserInstallation=" + profile.as_uri(), "--headless", "--convert-to", "pdf", "--outdir", str(output), str(source)], config["contract"]["office_timeout_seconds"], "office_conversion_failed")
        converted = output / (source.stem + ".pdf")
        if not converted.is_file(): raise BenchmarkError("office_conversion_failed", "LibreOffice did not create PDF")
        converted_count = pdf_pages(converted, config)
        return baseline_pdf(converted, locale, converted_count, config)


def docling(source, locale, count, config):
    try:
        from docling.datamodel.base_models import InputFormat
        from docling.datamodel.pipeline_options import PdfPipelineOptions, TesseractCliOcrOptions
        from docling.document_converter import DocumentConverter, PdfFormatOption
    except Exception as exc: raise BenchmarkError("provider_unavailable", str(exc)) from exc
    artifacts = ROOT / config["docling"]["artifacts_path"]
    if not artifacts.is_dir() or not any(artifacts.iterdir()):
        raise BenchmarkError("provider_unavailable", "prefetched Docling model artifacts missing")
    options = PdfPipelineOptions(
        artifacts_path=artifacts,
        document_timeout=config["contract"]["max_processing_seconds"],
        enable_remote_services=False,
        allow_external_plugins=False,
    )
    options.do_ocr = True
    options.do_table_structure = bool(config["docling"]["do_table_structure"])
    options.ocr_options = TesseractCliOcrOptions(
        lang=config["locales"][locale],
        force_full_page_ocr=bool(config["docling"]["force_full_page_ocr"]),
    )
    converter = DocumentConverter(format_options={InputFormat.PDF: PdfFormatOption(pipeline_options=options)})
    try: result = converter.convert(str(source), max_num_pages=config["contract"]["max_pages"])
    except Exception as exc: raise BenchmarkError("command_failed", str(exc)) from exc
    units = []
    actual_count = len(getattr(result.document, "pages", {})) or count
    if actual_count > config["contract"]["max_pages"]: raise BenchmarkError("page_limit_exceeded", str(actual_count))
    for page in range(1, actual_count + 1):
        text = normalize(result.document.export_to_text(page_no=page, traverse_pictures=True))
        if text: units.append({"page": page, "text": text})
    return units


def worker(payload):
    source = pathlib.Path(payload["source"])
    config, locale, engine = payload["config"], payload["locale"], payload["engine"]
    start = time.perf_counter()
    try:
        count = preflight(source, locale, config)
        units = baseline(source, locale, count, config) if engine == "baseline" else docling(source, locale, count, config)
        chars = sum(len(unit["text"]) for unit in units)
        if chars > config["contract"]["max_extracted_characters"]:
            raise BenchmarkError("extracted_text_too_large", str(chars))
        if not units: raise BenchmarkError("no_extractable_text", "no non-blank text unit")
        answer = {"status": "ready", "error_code": None, "page_count": count, "units": units}
    except BenchmarkError as exc:
        answer = {"status": "failed", "error_code": exc.code, "message": str(exc), "page_count": None, "units": []}
    answer["latency_seconds"] = time.perf_counter() - start
    print(json.dumps(answer, ensure_ascii=False))


def measured_engine(engine, source, locale, config):
    payload = {"engine": engine, "source": str(source), "locale": locale, "config": config}
    proc = subprocess.Popen([sys.executable, __file__, "--worker", json.dumps(payload)], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    peak = 0
    try:
        import psutil
        watched = psutil.Process(proc.pid)
        while proc.poll() is None:
            try: peak = max(peak, watched.memory_info().rss + sum(c.memory_info().rss for c in watched.children(recursive=True)))
            except psutil.Error: pass
            time.sleep(0.05)
    except ImportError: pass
    stdout, stderr = proc.communicate(timeout=10)
    if proc.returncode or not stdout.strip():
        return {"status": "failed", "error_code": "command_failed", "message": stderr[-1000:], "units": [], "latency_seconds": None, "peak_rss_bytes": peak}
    result = json.loads(stdout.strip().splitlines()[-1]); result["peak_rss_bytes"] = peak
    return result


def evaluate(engine_result, truth, doc, gates):
    by_page = {int(unit["page"]): unit["text"] for unit in engine_result.get("units", [])}
    truth_pages = {int(unit["page"]): normalize(unit.get("text", "")) for unit in truth["pages"]}
    metrics = {"cer_raw": [], "cer_stripped": [], "boundary": [], "adjacent_violation": False, "coverage": []}
    for page, reference in truth_pages.items():
        candidate = by_page.get(page, "")
        if reference:
            metrics["cer_raw"].append(cer(reference, candidate))
            metrics["cer_stripped"].append(cer(strip_diacritics(reference), strip_diacritics(candidate)))
            current = boundary_score(reference, candidate); metrics["boundary"].append(current)
            adjacent = [boundary_score(reference, by_page.get(page + offset, "")) for offset in (-1, 1)]
            if adjacent and max(adjacent) > current: metrics["adjacent_violation"] = True
            metrics["coverage"].append(bool(candidate))
        elif page in by_page: metrics.setdefault("blank_page_violation", True)
    order_ref = truth.get("reading_order", [])
    order_out = tokens(" ".join(by_page.values())) if order_ref else []
    return {
        "cer_raw": statistics.mean(metrics["cer_raw"]) if metrics["cer_raw"] else None,
        "cer_stripped": statistics.mean(metrics["cer_stripped"]) if metrics["cer_stripped"] else None,
        "boundary_min": min(metrics["boundary"]) if metrics["boundary"] else None,
        "adjacent_violation": metrics["adjacent_violation"],
        "coverage": sum(metrics["coverage"]) / max(1, len(metrics["coverage"])),
        "blank_page_violation": metrics.get("blank_page_violation", False),
        "reading_order_tau": kendall_tau(order_ref, order_out) if order_ref else None,
        "page_count_match": engine_result.get("page_count") == len(truth_pages),
        "citation_pass": (not metrics["boundary"] or min(metrics["boundary"]) >= gates["boundary_score_min"]) and not metrics["adjacent_violation"] and not metrics.get("blank_page_violation", False) and engine_result.get("page_count") == len(truth_pages),
    }


def environment(config):
    versions = {"python": sys.version.split()[0], "platform": sys.platform}
    for package in ("docling", "docling-core", "docling-ibm-models", "docling-parse", "huggingface-hub", "psutil"):
        try: versions[package] = importlib.metadata.version(package)
        except importlib.metadata.PackageNotFoundError: versions[package] = None
    for key, binary in config["binaries"].items():
        path = shutil.which(binary); versions[f"binary_{key}"] = path
        if path:
            flag = "--version" if key == "soffice" else "-v"
            probe = subprocess.run([path, flag], capture_output=True, text=True, timeout=10)
            versions[f"version_{key}"] = (probe.stdout or probe.stderr).splitlines()[0] if (probe.stdout or probe.stderr) else "unknown"
    versions["config_sha256"] = hashlib.sha256(json.dumps(config, sort_keys=True).encode()).hexdigest()
    artifacts = ROOT / config["docling"]["artifacts_path"]
    inventory = []
    if artifacts.is_dir():
        inventory = sorted(f"{path.relative_to(artifacts)}:{path.stat().st_size}" for path in artifacts.rglob("*") if path.is_file())
    versions["model_artifact_files"] = len(inventory)
    versions["model_inventory_sha256"] = hashlib.sha256("\n".join(inventory).encode()).hexdigest() if inventory else None
    return versions


def dependency_errors(config):
    errors = []
    for package in ("docling", "psutil"):
        if importlib.util.find_spec(package) is None: errors.append(f"benchmark dependency missing: {package}")
    for name, binary in config["binaries"].items():
        if shutil.which(binary) is None: errors.append(f"required baseline binary missing: {name} ({binary})")
    artifacts = ROOT / config["docling"]["artifacts_path"]
    if not artifacts.is_dir() or not any(artifacts.iterdir()): errors.append(f"prefetched Docling model artifacts missing: {artifacts}")
    return errors


def summarize(rows, config, manifest_errors):
    quality_keys = ("vi_cer_raw_max", "vi_cer_diacritic_stripped_max", "ko_cer_max", "page_coverage_min")
    thresholds_missing = any(config["gates"].get(key) is None for key in quality_keys)
    strata = {}
    for stratum in sorted(MINIMUMS):
        group = [row for row in rows if row["stratum"] == stratum]
        docling_rows = [row for row in group if row["engine"] == "docling"]
        failures = [row for row in docling_rows if not row["pass"]]
        stratum_p95 = percentile([r["seconds_per_page"] for r in docling_rows if r["seconds_per_page"] is not None], .95)
        performance_failed = stratum_p95 is not None and stratum_p95 > config["gates"]["p95_seconds_per_page_max"]
        status = "FAIL" if failures or performance_failed else ("DECISION_REQUIRED" if docling_rows and thresholds_missing else ("PASS" if docling_rows else "NOT_RUN"))
        strata[stratum] = {"documents": len(docling_rows), "status": status, "failures": len(failures), "p95_seconds_per_page": stratum_p95}
    latencies = [r["seconds_per_page"] for r in rows if r["engine"] == "docling" and r["seconds_per_page"] is not None]
    document_latencies = [r["latency_seconds"] for r in rows if r["engine"] == "docling" and r["latency_seconds"] is not None]
    performance = {"p50_seconds_per_page": percentile(latencies, .50), "p95_seconds_per_page": percentile(latencies, .95), "p99_document_seconds": percentile(document_latencies, .99), "pages_per_minute": 60 / statistics.mean(latencies) if latencies else None, "peak_rss_bytes": max((r["peak_rss_bytes"] for r in rows), default=0)}
    decision_fail = any(strata[s]["status"] == "FAIL" for s in DECISION_STRATA)
    incomplete = bool(manifest_errors) or thresholds_missing or any(strata[s]["status"] in {"NOT_RUN", "DECISION_REQUIRED"} for s in DECISION_STRATA)
    perf_fail = performance["p95_seconds_per_page"] is not None and performance["p95_seconds_per_page"] > config["gates"]["p95_seconds_per_page_max"]
    verdict = "DECISION_REQUIRED" if incomplete else ("A0_FAIL" if decision_fail or perf_fail else "A0_PASS")
    locale_metrics = {}
    for locale in ("vi", "ko", "en"):
        locale_metrics[locale] = {}
        for engine in ("baseline", "docling"):
            group = [r for r in rows if r["locale"] == locale and r["engine"] == engine]
            locale_metrics[locale][engine] = {
                "documents": len(group),
                "cer_raw_mean": statistics.mean(r["cer_raw"] for r in group if r.get("cer_raw") is not None) if any(r.get("cer_raw") is not None for r in group) else None,
                "cer_diacritic_stripped_mean": statistics.mean(r["cer_stripped"] for r in group if r.get("cer_stripped") is not None) if any(r.get("cer_stripped") is not None for r in group) else None,
                "coverage_mean": statistics.mean(r["coverage"] for r in group if r.get("coverage") is not None) if any(r.get("coverage") is not None for r in group) else None,
            }
    return verdict, strata, performance, locale_metrics


def report_markdown(result):
    lines = ["# LF A0 Docling Benchmark Report", "", f"Verdict: **{result['verdict']}**", "", "## Environment", ""]
    lines += [f"- `{key}`: `{value}`" for key, value in sorted(result["environment"].items())]
    lines += ["", "## Corpus manifest", "", f"- Corpus: `{result['corpus'].get('corpus_id', 'unavailable')}`", f"- Revision: `{result['corpus'].get('revision', 'unavailable')}`", f"- Documents: `{len(result['corpus'].get('documents', []))}`"]
    if result["blockers"]: lines += ["", "## Blockers / limits", ""] + [f"- {item}" for item in result["blockers"]]
    lines += ["", "## S1–S10 decision gates", "", "| Stratum | Documents | Status | Failures |", "|---|---:|---|---:|"]
    for stratum in [f"S{i}" for i in range(1, 11)]:
        item = result["strata"][stratum]; lines.append(f"| {stratum} | {item['documents']} | {item['status']} | {item['failures']} |")
    lines += ["", "## S11–S14 resource/error strata", "", "| Stratum | Documents | Status | Failures |", "|---|---:|---|---:|"]
    for stratum in [f"S{i}" for i in range(11, 15)]:
        item = result["strata"][stratum]; lines.append(f"| {stratum} | {item['documents']} | {item['status']} | {item['failures']} |")
    lines += ["", "## Performance", ""] + [f"- `{key}`: `{value}`" for key, value in result["performance"].items()]
    lines += ["", "## Locale-specific quality", "", "| Locale | Engine | Documents | CER raw | CER stripped | Coverage |", "|---|---|---:|---:|---:|---:|"]
    for locale, engines in result["locale_metrics"].items():
        for engine, item in engines.items():
            lines.append(f"| {locale} | {engine} | {item['documents']} | {item['cer_raw_mean']} | {item['cer_diacritic_stripped_mean']} | {item['coverage_mean']} |")
    lines += ["", "Budget: p95 ≤ 33 seconds/page; every decision stratum must pass independently. VI and KO metrics remain separate in `result.json` and `per_document.csv`; no pooled-only decision is used.", "", "## A1 boundary", "", "This report does not authorize A1 or runtime deployment. An `A0_PASS` would only provide evidence for Owner review of a Tech Stack amendment, pinned model/binary parity, and repeatable local/AWS parity.", ""]
    return "\n".join(lines)


def run(args):
    config = json.loads((ROOT / "config.json").read_text(encoding="utf-8"))
    manifest_path = pathlib.Path(args.corpus).resolve()
    errors = validate_manifest(manifest_path, config)
    manifest = {}
    if manifest_path.is_file():
        try: manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        except Exception: pass
    rows = []
    if not errors:
        corpus_root = manifest_path.parent
        for doc in manifest["documents"]:
            source = safe_path(corpus_root, doc["source"])
            truth = json.loads(safe_path(corpus_root, doc["ground_truth"]).read_text(encoding="utf-8"))
            for engine in ("baseline", "docling"):
                outcome = measured_engine(engine, source, doc["locale"], config)
                expected = doc.get("expected_error")
                metrics = evaluate(outcome, truth, doc, config["gates"]) if outcome["status"] == "ready" else {}
                page_count = outcome.get("page_count") or len(truth.get("pages", [])) or 1
                passed = outcome.get("error_code") == expected if expected else outcome["status"] == "ready" and metrics.get("citation_pass", False)
                rows.append({"document_id": doc["id"], "locale": doc["locale"], "stratum": doc["stratum"], "engine": engine, "status": outcome["status"], "error_code": outcome.get("error_code"), "pass": passed, "page_count": page_count, "latency_seconds": outcome.get("latency_seconds"), "seconds_per_page": outcome.get("latency_seconds") / page_count if outcome.get("latency_seconds") else None, "peak_rss_bytes": outcome.get("peak_rss_bytes", 0), **metrics})
    blockers = list(errors) + dependency_errors(config)
    missing_thresholds = [key for key in ("vi_cer_raw_max", "vi_cer_diacritic_stripped_max", "ko_cer_max", "page_coverage_min") if config["gates"].get(key) is None]
    if missing_thresholds:
        blockers.append("Owner quality thresholds not frozen: " + ", ".join(missing_thresholds))
    verdict, strata, performance, locale_metrics = summarize(rows, config, blockers)
    run_id = args.run_id or datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    out = pathlib.Path(args.output).resolve() / run_id; out.mkdir(parents=True, exist_ok=False)
    result = {"schema_version": 1, "run_id": run_id, "created_at": datetime.now(timezone.utc).isoformat(), "verdict": verdict, "environment": environment(config), "config": config, "corpus": manifest, "blockers": blockers, "strata": strata, "performance": performance, "locale_metrics": locale_metrics, "per_document": rows}
    (out / "result.json").write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    fields = sorted({key for row in rows for key in row}) or ["document_id", "locale", "stratum", "engine", "status", "error_code", "pass"]
    with (out / "per_document.csv").open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=fields); writer.writeheader(); writer.writerows(rows)
    (out / "report.md").write_text(report_markdown(result), encoding="utf-8")
    print(f"{verdict}: {out}")
    return 0 if verdict == "A0_PASS" else 2


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--corpus", default=str(ROOT / "corpus" / "manifest.json"))
    parser.add_argument("--output", default=str(ROOT / "results"))
    parser.add_argument("--run-id")
    parser.add_argument("--worker")
    args = parser.parse_args()
    if args.worker: worker(json.loads(args.worker)); return 0
    return run(args)


if __name__ == "__main__": raise SystemExit(main())
