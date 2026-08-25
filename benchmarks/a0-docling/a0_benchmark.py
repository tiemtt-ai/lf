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


class ExtractedUnits(list):
    """Text units plus the page count observed by the extraction engine."""

    page_count = None


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
    return lcs_length(a, b) / max(1, len(a), len(b))


def kendall_tau(reference, candidate):
    pos = {value: i for i, value in enumerate(candidate)}
    seq = [pos[x] for x in reference if x in pos]
    if len(seq) < 2: return 1.0 if seq else 0.0
    discordant = sum(seq[i] > seq[j] for i in range(len(seq)) for j in range(i + 1, len(seq)))
    pairs = len(seq) * (len(seq) - 1) / 2
    return 1 - 2 * discordant / pairs


def observed_anchor_order(reference, candidate_text):
    candidate_tokens = tokens(candidate_text)
    observed = []
    for anchor in reference:
        needle = tokens(anchor)
        position = next((index for index in range(len(candidate_tokens) - len(needle) + 1)
                         if candidate_tokens[index:index + len(needle)] == needle), None)
        if position is not None:
            observed.append((position, anchor))
    return [anchor for _, anchor in sorted(observed)]


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


def artifact_component(value):
    value = str(value or "")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*", value):
        raise BenchmarkError("corrupt_source", f"unsafe artifact identifier: {value!r}")
    return value


QUALITY_KEYS = ("vi_cer_raw_max", "vi_cer_diacritic_stripped_max", "ko_cer_max", "page_coverage_min")


def document_eligibility(doc):
    expected = bool(doc.get("expected_error"))
    draft_truth = bool(doc.get("needs_owner_verification")) or str(doc.get("ground_truth_status", "")).startswith("draft")
    quality = bool(doc.get("quality_metrics_eligible", not expected and not draft_truth))
    performance = bool(doc.get("performance_metrics_eligible", not expected and not draft_truth))
    return {
        "expected_error": expected,
        "quality": quality,
        "performance": performance,
        "execute": expected or quality or performance,
        "reason": None if expected or quality or performance else "fixture is not eligible for quality or performance metrics",
    }


def expected_sha256(doc, corpus_root):
    declared = doc.get("sha256")
    if declared:
        return str(declared).lower()
    evidence = doc.get("source_approval_evidence")
    if not evidence:
        return None
    evidence_path = safe_path(corpus_root, evidence)
    if not evidence_path.is_file():
        return None
    match = re.search(r"SHA-256:\s*`?([0-9a-fA-F]{64})`?", evidence_path.read_text(encoding="utf-8"))
    return match.group(1).lower() if match else None


def validate_manifest(path, config, mode="official"):
    errors = []
    if mode not in {"official", "exploratory"}:
        return [f"unsupported benchmark mode: {mode}"]
    if not path.is_file(): return [f"corpus manifest missing: {path}"]
    try: data = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc: return [f"manifest invalid: {exc}"]
    approval = data.get("approval", {})
    if mode == "official" and (approval.get("status") != "approved" or not approval.get("approved_by") or not approval.get("approved_at")):
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
        today = datetime.now(timezone.utc).date().isoformat()
        if governance.get("retention_until") and governance["retention_until"] < today:
            errors.append("PII corpus retention period has expired")
        if governance.get("deletion_required_by") and governance["deletion_required_by"] <= today:
            errors.append("PII corpus deletion deadline has been reached")
    counts = Counter((d.get("locale"), d.get("stratum")) for d in data.get("documents", []))
    if mode == "official":
        for locale in ("vi", "ko"):
            for stratum, minimum in MINIMUMS.items():
                if counts[(locale, stratum)] < minimum:
                    errors.append(f"{locale}/{stratum}: {counts[(locale, stratum)]} < {minimum}")
    root = path.parent.resolve()
    for doc in data.get("documents", []):
        try: artifact_component(doc.get("id"))
        except BenchmarkError as exc: errors.append(str(exc))
        eligibility = document_eligibility(doc)
        if mode == "official" and not eligibility["expected_error"] and not eligibility["quality"]:
            errors.append(f"{doc.get('id')}: official fixture is not eligible for quality metrics")
        if doc.get("locale") not in config["locales"]:
            errors.append(f"{doc.get('id')}: unsupported or missing canonical locale")
        required_paths = ("source",) if doc.get("expected_error") else ("source", "ground_truth")
        for key in required_paths:
            try: candidate = safe_path(root, doc.get(key, ""))
            except BenchmarkError as exc: errors.append(f"{doc.get('id')}: {exc}"); continue
            if not candidate.is_file(): errors.append(f"{doc.get('id')}: missing {key} {candidate}")
        if eligibility["execute"]:
            try: source = safe_path(root, doc.get("source", ""))
            except BenchmarkError: continue
            expected_hash = expected_sha256(doc, root)
            if not expected_hash:
                errors.append(f"{doc.get('id')}: executable fixture requires a SHA-256 declaration or referenced approval evidence")
            elif source.is_file() and hashlib.sha256(source.read_bytes()).hexdigest() != expected_hash:
                errors.append(f"{doc.get('id')}: source SHA-256 mismatch")
    if mode == "official":
        missing = [key for key in QUALITY_KEYS if config["gates"].get(key) is None]
        if missing:
            errors.append("Owner quality thresholds not frozen: " + ", ".join(missing))
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
        from docling.datamodel.accelerator_options import AcceleratorDevice, AcceleratorOptions
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
        accelerator_options=AcceleratorOptions(
            device=AcceleratorDevice(config["docling"]["accelerator_device"]),
        ),
    )
    options.layout_options.engine_options.compile_model = bool(config["docling"]["compile_layout_model"])
    options.do_ocr = True
    options.do_table_structure = bool(config["docling"]["do_table_structure"])
    options.ocr_options = TesseractCliOcrOptions(
        lang=config["locales"][locale],
        force_full_page_ocr=bool(config["docling"]["force_full_page_ocr"]),
    )
    converter = DocumentConverter(format_options={InputFormat.PDF: PdfFormatOption(pipeline_options=options)})
    try: result = converter.convert(str(source), max_num_pages=config["contract"]["max_pages"])
    except Exception as exc: raise BenchmarkError("command_failed", str(exc)) from exc
    units = ExtractedUnits()
    actual_count = len(getattr(result.document, "pages", {})) or count
    units.page_count = actual_count
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
        answer = {
            "status": "ready",
            "error_code": None,
            "source_page_count": count,
            "page_count": getattr(units, "page_count", None) or count,
            "units": units,
        }
    except BenchmarkError as exc:
        answer = {"status": "failed", "error_code": exc.code, "message": str(exc), "page_count": None, "units": []}
    answer["latency_seconds"] = time.perf_counter() - start
    print(json.dumps(answer, ensure_ascii=False))


def measured_engine(engine, source, locale, config):
    payload = {"engine": engine, "source": str(source), "locale": locale, "config": config}
    proc = subprocess.Popen([sys.executable, __file__, "--worker", json.dumps(payload)], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    peak = 0
    resource_warnings = []
    try:
        import psutil
        watched = psutil.Process(proc.pid)
        while proc.poll() is None:
            try: peak = max(peak, watched.memory_info().rss + sum(c.memory_info().rss for c in watched.children(recursive=True)))
            except (psutil.Error, OSError) as exc:
                if not resource_warnings: resource_warnings.append(f"child RSS unavailable: {exc}")
                try: peak = max(peak, watched.memory_info().rss)
                except (psutil.Error, OSError): pass
            time.sleep(0.05)
    except ImportError: pass
    stdout, stderr = proc.communicate(timeout=10)
    if proc.returncode or not stdout.strip():
        return {"status": "failed", "error_code": "command_failed", "message": stderr[-1000:], "units": [], "latency_seconds": None, "peak_rss_bytes": peak}
    result = json.loads(stdout.strip().splitlines()[-1]); result["peak_rss_bytes"] = peak; result["resource_warnings"] = resource_warnings
    return result


def evaluate(engine_result, truth, doc, gates):
    by_page = {int(unit["page"]): unit["text"] for unit in engine_result.get("units", [])}
    truth_pages = {int(unit["page"]): normalize(unit.get("text", "")) for unit in truth["pages"]}
    metrics = {"cer_raw": [], "cer_stripped": [], "boundary": [], "adjacent_violation": False, "coverage": [], "per_page": []}
    for page, reference in truth_pages.items():
        candidate = by_page.get(page, "")
        if reference:
            raw = cer(reference, candidate)
            stripped = cer(strip_diacritics(reference), strip_diacritics(candidate))
            metrics["cer_raw"].append(raw)
            metrics["cer_stripped"].append(stripped)
            current = boundary_score(reference, candidate); metrics["boundary"].append(current)
            previous = boundary_score(reference, by_page.get(page - 1, ""))
            following = boundary_score(reference, by_page.get(page + 1, ""))
            adjacent = [previous, following]
            if adjacent and max(adjacent) > current: metrics["adjacent_violation"] = True
            metrics["coverage"].append(bool(candidate))
            metrics["per_page"].append({"page": page, "ground_truth_has_content": True, "output_has_text": bool(candidate), "cer_raw": raw, "cer_stripped": stripped, "boundary_score": current, "boundary_previous_page": previous, "boundary_next_page": following, "adjacent_violation": max(adjacent) > current})
        else:
            blank_violation = page in by_page
            if blank_violation: metrics.setdefault("blank_page_violation", True)
            metrics["per_page"].append({"page": page, "ground_truth_has_content": False, "output_has_text": bool(candidate), "blank_page_violation": blank_violation})
    order_ref = truth.get("reading_order", [])
    order_out = observed_anchor_order(order_ref, " ".join(by_page.values())) if order_ref else []
    reading_order_complete = not order_ref or len(order_out) == len(order_ref)
    reading_order_tau = kendall_tau(order_ref, order_out) if order_ref else None
    reading_order_pass = not order_ref or (
        reading_order_complete and reading_order_tau >= gates["reading_order_tau_min"]
    )
    return {
        "cer_raw": statistics.mean(metrics["cer_raw"]) if metrics["cer_raw"] else None,
        "cer_stripped": statistics.mean(metrics["cer_stripped"]) if metrics["cer_stripped"] else None,
        "boundary_min": min(metrics["boundary"]) if metrics["boundary"] else None,
        "adjacent_violation": metrics["adjacent_violation"],
        "coverage": sum(metrics["coverage"]) / len(metrics["coverage"]) if metrics["coverage"] else None,
        "blank_page_violation": metrics.get("blank_page_violation", False),
        "reading_order_tau": reading_order_tau,
        "reading_order_observed": order_out if order_ref else None,
        "reading_order_complete": reading_order_complete,
        "per_page_metrics": metrics["per_page"],
        "page_count_match": engine_result.get("page_count") == len(truth_pages),
        "citation_pass": (not metrics["boundary"] or min(metrics["boundary"]) >= gates["boundary_score_min"]) and not metrics["adjacent_violation"] and not metrics.get("blank_page_violation", False) and reading_order_pass and engine_result.get("page_count") == len(truth_pages),
    }


def per_page_evidence(run_id, engine, outcome, truth, doc, metrics, source_sha256):
    """Build trace evidence without changing the values used by evaluation."""
    output_pages = {int(unit["page"]): str(unit.get("text", "")) for unit in outcome.get("units", [])}
    truth_pages = {int(unit["page"]): unit for unit in truth.get("pages", [])}
    metric_pages = {int(item["page"]): item for item in metrics.get("per_page_metrics", [])}
    maximum = max(
        [int(outcome.get("page_count") or 0), *truth_pages.keys(), *output_pages.keys()],
        default=0,
    )
    records = []
    for page in range(1, maximum + 1):
        truth_item = truth_pages.get(page, {})
        truth_text = str(truth_item.get("text", ""))
        output_text = output_pages.get(page, "")
        normalized_truth = normalize(truth_text)
        normalized_output = normalize(output_text)
        page_metrics = metric_pages.get(page, {})
        records.append({
            "schema_version": 1,
            "run_id": run_id,
            "non_official": True,
            "document_id": doc["id"],
            "locale": doc["locale"],
            "stratum": doc["stratum"],
            "pipeline": engine,
            "page": page,
            "source_sha256": source_sha256,
            "ground_truth": {
                "text": truth_text,
                "normalized_text": normalized_truth,
                "sha256": hashlib.sha256(normalized_truth.encode("utf-8")).hexdigest(),
                "character_count": len(normalized_truth),
                "has_content": bool(normalized_truth),
                "anchors": truth_item.get("anchors", []),
            },
            "output": {
                "text": output_text,
                "normalized_text": normalized_output,
                "sha256": hashlib.sha256(normalized_output.encode("utf-8")).hexdigest(),
                "character_count": len(normalized_output),
                "has_text": bool(normalized_output),
            },
            "metrics": page_metrics,
            "document_reading_order": {
                "reference": truth.get("reading_order", []),
                "observed": metrics.get("reading_order_observed"),
                "complete": metrics.get("reading_order_complete"),
                "kendall_tau": metrics.get("reading_order_tau"),
            },
        })
    return records


def write_per_page_evidence(out, records):
    fields = (
        "document_id", "locale", "stratum", "pipeline", "page", "artifact_path",
        "source_sha256", "ground_truth_sha256", "ground_truth_character_count",
        "ground_truth_has_content", "output_sha256", "output_character_count",
        "output_has_text", "cer_raw", "cer_stripped", "boundary_score",
        "boundary_previous_page", "boundary_next_page", "adjacent_violation",
        "blank_page_violation", "reading_order_complete", "reading_order_tau",
    )
    csv_rows = []
    for record in records:
        document_id = artifact_component(record["document_id"])
        pipeline = artifact_component(record["pipeline"])
        relative = pathlib.Path("per_page") / pipeline / document_id / f"page-{record['page']}.json"
        target = out / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(record, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        page_metrics = record["metrics"]
        csv_rows.append({
            "document_id": document_id,
            "locale": record["locale"],
            "stratum": record["stratum"],
            "pipeline": pipeline,
            "page": record["page"],
            "artifact_path": relative.as_posix(),
            "source_sha256": record["source_sha256"],
            "ground_truth_sha256": record["ground_truth"]["sha256"],
            "ground_truth_character_count": record["ground_truth"]["character_count"],
            "ground_truth_has_content": record["ground_truth"]["has_content"],
            "output_sha256": record["output"]["sha256"],
            "output_character_count": record["output"]["character_count"],
            "output_has_text": record["output"]["has_text"],
            "cer_raw": page_metrics.get("cer_raw"),
            "cer_stripped": page_metrics.get("cer_stripped"),
            "boundary_score": page_metrics.get("boundary_score"),
            "boundary_previous_page": page_metrics.get("boundary_previous_page"),
            "boundary_next_page": page_metrics.get("boundary_next_page"),
            "adjacent_violation": page_metrics.get("adjacent_violation"),
            "blank_page_violation": page_metrics.get("blank_page_violation"),
            "reading_order_complete": record["document_reading_order"]["complete"],
            "reading_order_tau": record["document_reading_order"]["kendall_tau"],
        })
    with (out / "per_page.csv").open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=fields)
        writer.writeheader()
        writer.writerows(csv_rows)


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


def threshold_failure(row, gates):
    if not row.get("quality_eligible") or row.get("status") != "ready":
        return False
    limits = []
    if row["locale"] == "vi":
        limits += [("cer_raw", "vi_cer_raw_max"), ("cer_stripped", "vi_cer_diacritic_stripped_max")]
    elif row["locale"] == "ko":
        limits.append(("cer_raw", "ko_cer_max"))
    limits.append(("coverage", "page_coverage_min"))
    return any(row.get(metric) is not None and gates.get(key) is not None and row[metric] > gates[key]
               for metric, key in limits if metric != "coverage") or any(
        row.get(metric) is not None and gates.get(key) is not None and row[metric] < gates[key]
        for metric, key in limits if metric == "coverage"
    )


def performance_for(rows, engine):
    eligible = [row for row in rows if row["engine"] == engine and row["status"] == "ready" and row.get("performance_eligible", True)]
    page_latencies = [row["seconds_per_page"] for row in eligible if row["seconds_per_page"] is not None]
    document_latencies = [row["latency_seconds"] for row in eligible if row["latency_seconds"] is not None]
    worst_page = max(eligible, key=lambda row: row["seconds_per_page"] if row["seconds_per_page"] is not None else -1, default=None)
    worst_document = max(eligible, key=lambda row: row["latency_seconds"] if row["latency_seconds"] is not None else -1, default=None)
    return {
        "p50_seconds_per_page": percentile(page_latencies, .50),
        "p95_seconds_per_page": percentile(page_latencies, .95),
        "worst_seconds_per_page": worst_page.get("seconds_per_page") if worst_page else None,
        "worst_page_document_id": worst_page.get("document_id") if worst_page else None,
        "p99_document_seconds": percentile(document_latencies, .99),
        "worst_document_seconds": worst_document.get("latency_seconds") if worst_document else None,
        "worst_document_id": worst_document.get("document_id") if worst_document else None,
        "pages_per_minute": 60 / statistics.mean(page_latencies) if page_latencies else None,
        "peak_rss_bytes": max((row["peak_rss_bytes"] for row in eligible), default=0),
    }


def summarize(rows, config, validation_errors, mode):
    thresholds_missing = any(config["gates"].get(key) is None for key in QUALITY_KEYS)
    strata = {}
    for stratum in sorted(MINIMUMS):
        group = [row for row in rows if row["stratum"] == stratum]
        docling_rows = [row for row in group if row["engine"] == "docling"]
        failures = [row for row in docling_rows if not row["observation_ok"] or threshold_failure(row, config["gates"])]
        stratum_p95 = percentile([r["seconds_per_page"] for r in docling_rows if r.get("performance_eligible") and r["seconds_per_page"] is not None], .95)
        performance_failed = stratum_p95 is not None and stratum_p95 > config["gates"]["p95_seconds_per_page_max"]
        if mode == "exploratory":
            status = "OBSERVED_WITH_ERRORS" if failures else ("OBSERVED" if docling_rows else "NOT_RUN")
        else:
            status = "FAIL" if failures or performance_failed else ("DECISION_REQUIRED" if docling_rows and thresholds_missing else ("PASS" if docling_rows else "NOT_RUN"))
        strata[stratum] = {"documents": len(docling_rows), "status": status, "failures": len(failures), "p95_seconds_per_page": stratum_p95}
    pipeline_performance = {engine: performance_for(rows, engine) for engine in ("baseline", "docling")}
    performance = pipeline_performance["docling"]
    decision_fail = any(strata[s]["status"] == "FAIL" for s in DECISION_STRATA)
    incomplete = bool(validation_errors) or thresholds_missing or any(strata[s]["status"] in {"NOT_RUN", "DECISION_REQUIRED"} for s in DECISION_STRATA)
    perf_fail = performance["p95_seconds_per_page"] is not None and performance["p95_seconds_per_page"] > config["gates"]["p95_seconds_per_page_max"]
    verdict = "OWNER_DECISION_REQUIRED" if mode == "exploratory" else ("DECISION_REQUIRED" if incomplete else ("A0_FAIL" if decision_fail or perf_fail else "A0_PASS"))
    locale_metrics = {}
    for locale in ("vi", "ko", "en"):
        locale_metrics[locale] = {}
        for engine in ("baseline", "docling"):
            group = [r for r in rows if r["locale"] == locale and r["engine"] == engine and r.get("quality_eligible", True)]
            locale_metrics[locale][engine] = {
                "documents": len(group),
                "cer_raw_mean": statistics.mean(r["cer_raw"] for r in group if r.get("cer_raw") is not None) if any(r.get("cer_raw") is not None for r in group) else None,
                "cer_diacritic_stripped_mean": statistics.mean(r["cer_stripped"] for r in group if r.get("cer_stripped") is not None) if any(r.get("cer_stripped") is not None for r in group) else None,
                "coverage_mean": statistics.mean(r["coverage"] for r in group if r.get("coverage") is not None) if any(r.get("coverage") is not None for r in group) else None,
            }
    return verdict, strata, performance, pipeline_performance, locale_metrics


def report_markdown(result):
    lines = ["# LF A0 Docling Benchmark Report", "", f"Mode: **{result['mode']}**", f"Verdict: **{result['verdict']}**", f"Non-official: **{str(result['non_official']).lower()}**", f"Thresholds applied: **{str(result['thresholds_applied']).lower()}**"]
    if result["non_official"]:
        lines += ["", "> Exploratory evidence only. This report cannot authorize A1, runtime deployment, a provider binding, or a Tech Stack change."]
    lines += ["", "## Environment", ""]
    lines += [f"- `{key}`: `{value}`" for key, value in sorted(result["environment"].items())]
    lines += ["", "## Corpus manifest", "", f"- Corpus: `{result['corpus'].get('corpus_id', 'unavailable')}`", f"- Revision: `{result['corpus'].get('revision', 'unavailable')}`", f"- Documents: `{len(result['corpus'].get('documents', []))}`"]
    if result["blockers"]: lines += ["", "## Blockers / limits", ""] + [f"- {item}" for item in result["blockers"]]
    if result.get("decision_requirements"): lines += ["", "## Pending Owner decisions / corpus gaps", ""] + [f"- {item}" for item in result["decision_requirements"]]
    if result.get("metric_unavailable"): lines += ["", "## Metrics not available", ""] + [f"- {item}" for item in result["metric_unavailable"]]
    observed_errors = {(row.get("engine"), row.get("error_code"), row.get("error_message")) for row in result["per_document"] if row.get("error_code") and row.get("error_code") != row.get("expected_error")}
    if observed_errors:
        lines += ["", "## Observed engine errors", "", "| Pipeline | Error code | Message |", "|---|---|---|"]
        for engine, code, message in sorted(observed_errors, key=lambda item: (str(item[0]), str(item[1]), str(item[2]))):
            escaped_message = str(message or "").replace("|", "\\|")
            lines.append(f"| {engine} | {code} | {escaped_message} |")
    lines += ["", "## S1–S10 decision gates", "", "| Stratum | Documents | Status | Failures |", "|---|---:|---|---:|"]
    for stratum in [f"S{i}" for i in range(1, 11)]:
        item = result["strata"][stratum]; lines.append(f"| {stratum} | {item['documents']} | {item['status']} | {item['failures']} |")
    lines += ["", "## S11–S14 resource/error strata", "", "| Stratum | Documents | Status | Failures |", "|---|---:|---|---:|"]
    for stratum in [f"S{i}" for i in range(11, 15)]:
        item = result["strata"][stratum]; lines.append(f"| {stratum} | {item['documents']} | {item['status']} | {item['failures']} |")
    lines += ["", "## Performance by pipeline", "", "| Pipeline | p50 s/page | p95 s/page | worst s/page | p99 document s | worst document s | peak RSS bytes |", "|---|---:|---:|---:|---:|---:|---:|"]
    for engine, item in result["pipeline_performance"].items():
        lines.append(f"| {engine} | {item['p50_seconds_per_page']} | {item['p95_seconds_per_page']} | {item['worst_seconds_per_page']} | {item['p99_document_seconds']} | {item['worst_document_seconds']} | {item['peak_rss_bytes']} |")
    lines += ["", "## Locale-specific quality", "", "| Locale | Engine | Documents | CER raw | CER stripped | Coverage |", "|---|---|---:|---:|---:|---:|"]
    for locale, engines in result["locale_metrics"].items():
        for engine, item in engines.items():
            lines.append(f"| {locale} | {engine} | {item['documents']} | {item['cer_raw_mean']} | {item['cer_diacritic_stripped_mean']} | {item['coverage_mean']} |")
    coverage_regressions = [row for row in result["per_document"] if row.get("quality_eligible") and row.get("coverage") is not None and row["coverage"] < 1]
    if coverage_regressions:
        lines += ["", "## Page coverage regressions", "", "| Document | Pipeline | Coverage | Missing content pages |", "|---|---|---:|---|"]
        for row in coverage_regressions:
            missing = [str(page["page"]) for page in row.get("per_page_metrics", []) if page.get("ground_truth_has_content") and not page.get("output_has_text")]
            lines.append(f"| {row['document_id']} | {row['engine']} | {row['coverage']} | {', '.join(missing) or 'unavailable'} |")
    parity_regressions = [row for row in result["per_document"] if row.get("quality_eligible") and row.get("status") == "ready" and not row.get("citation_pass", False)]
    if parity_regressions:
        lines += ["", "## Citation / layout parity regressions", "", "| Document | Pipeline | Boundary min | Adjacent violation | Reading order complete/tau | Blank violation | Page count match |", "|---|---|---:|---|---|---|---|"]
        for row in parity_regressions:
            lines.append(f"| {row['document_id']} | {row['engine']} | {row.get('boundary_min')} | {row.get('adjacent_violation')} | {row.get('reading_order_complete')}/{row.get('reading_order_tau')} | {row.get('blank_page_violation')} | {row.get('page_count_match')} |")
    lines += ["", "Budget: p95 ≤ 33 seconds/page; every decision stratum must pass independently. VI and KO metrics remain separate in `result.json` and `per_document.csv`; no pooled-only decision is used.", "", "## Evidence and decision boundary", "", "- Expected-error fixtures are preflight-only and excluded from OCR, quality, and performance metrics.", "- A metric is `unavailable` when corpus eligibility or ground truth does not support it; it is never replaced with zero or pass.", "- Threshold recommendations are withheld until Owner-approved VI/KO ground truth and representative real-source coverage are sufficient.", "- Per-process timings include model cold start because each fixture is isolated; they are evidence for this harness run, not steady-state AWS capacity sizing.", "", "## A1 gates", "", "This report does not authorize A1 or runtime deployment. Before A1, Owner must explicitly approve the corpus and four quality thresholds, review all A0 regressions, approve a Tech Stack amendment, establish pinned local/AWS binary-model-config parity, apply PII retention/deletion controls, and pass Architecture Review.", ""]
    return "\n".join(lines)


def decision_package_markdown(result):
    failing = [row for row in result["per_document"] if row.get("quality_eligible") and row.get("status") == "ready" and (row.get("coverage") is not None and row["coverage"] < 1 or not row.get("citation_pass", False))]
    lines = ["# A0 Owner Decision Package", "", f"Status: **{result['verdict']}**", "", "This package is exploratory, non-official evidence and cannot authorize A1 or runtime deployment.", "", "## Evidence available", "", "- Python 3.11 offline Docling and deterministic baseline results are recorded per document, locale, stratum, pipeline, and page.", "- S13 expected-error handling, blank-page behavior, boundary/adjacent-page checks, reading order, CER, coverage, latency, and memory are recorded where eligible.", "", "## Unresolved evidence", ""]
    lines += [f"- {item}" for item in result.get("metric_unavailable", [])] or ["- None reported by the harness."]
    lines += ["", "## Regressions requiring review", ""]
    lines += [f"- `{row['document_id']}` / `{row['engine']}`: coverage={row.get('coverage')}, boundary_min={row.get('boundary_min')}, adjacent_violation={row.get('adjacent_violation')}, reading_order={row.get('reading_order_complete')}/{row.get('reading_order_tau')}." for row in failing] or ["- None observed in eligible fixtures."]
    lines += ["", "## Owner decisions before official A0", ""]
    lines += [f"- {item}" for item in result.get("decision_requirements", [])] or ["- Approve the corpus revision and all four non-null quality thresholds."]
    lines += ["", "## Gates before A1", "", "- Official A0 must complete under the approved corpus and thresholds.", "- Owner must explicitly approve a Tech Stack amendment; this package does not amend it.", "- Local/AWS binary, model, locale, and configuration parity must be reproducible.", "- PII access, audit, retention, and deletion policy must be operationally enforced.", "- Architecture Review and explicit Owner approval remain mandatory.", ""]
    return "\n".join(lines)


def run(args):
    config = json.loads((ROOT / "config.json").read_text(encoding="utf-8"))
    run_id = args.run_id or datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    manifest_path = pathlib.Path(args.corpus).resolve()
    errors = validate_manifest(manifest_path, config, args.mode)
    decision_requirements = [] if args.mode == "official" else validate_manifest(manifest_path, config, "official")
    manifest = {}
    if manifest_path.is_file():
        try: manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        except Exception: pass
    rows = []
    page_records = []
    excluded = []
    if not errors:
        corpus_root = manifest_path.parent
        for doc in manifest["documents"]:
            eligibility = document_eligibility(doc)
            if not eligibility["execute"]:
                excluded.append(f"{doc['id']}: {eligibility['reason']}")
                continue
            source = safe_path(corpus_root, doc["source"])
            expected = doc.get("expected_error")
            truth = {} if expected else json.loads(safe_path(corpus_root, doc["ground_truth"]).read_text(encoding="utf-8"))
            for engine in ("baseline", "docling"):
                outcome = measured_engine(engine, source, doc["locale"], config)
                metrics = evaluate(outcome, truth, doc, config["gates"]) if eligibility["quality"] and outcome["status"] == "ready" else {}
                if args.mode == "exploratory" and eligibility["quality"] and outcome["status"] == "ready":
                    page_records.extend(per_page_evidence(
                        run_id, engine, outcome, truth, doc, metrics,
                        expected_sha256(doc, corpus_root),
                    ))
                page_count = outcome.get("page_count") or len(truth.get("pages", [])) or None
                observation_ok = outcome.get("error_code") == expected if expected else outcome["status"] == "ready" and (not eligibility["quality"] or metrics.get("citation_pass", False))
                rows.append({"document_id": doc["id"], "locale": doc["locale"], "stratum": doc["stratum"], "engine": engine, "status": outcome["status"], "error_code": outcome.get("error_code"), "error_message": outcome.get("message"), "expected_error": expected, "quality_eligible": eligibility["quality"], "performance_eligible": eligibility["performance"], "observation_ok": observation_ok, "page_count": page_count, "latency_seconds": outcome.get("latency_seconds"), "seconds_per_page": outcome.get("latency_seconds") / page_count if eligibility["performance"] and outcome["status"] == "ready" and outcome.get("latency_seconds") and page_count else None, "peak_rss_bytes": outcome.get("peak_rss_bytes", 0), "resource_warnings": outcome.get("resource_warnings", []), **metrics})
    blockers = list(errors) + dependency_errors(config)
    metric_unavailable = list(excluded)
    if any(row.get("resource_warnings") for row in rows):
        metric_unavailable.append("child-process peak RSS is unavailable because the operating system denied process-tree inventory; main worker RSS is reported")
    for locale in ("vi", "ko"):
        if not any(r["locale"] == locale and r.get("quality_eligible") for r in rows):
            metric_unavailable.append(f"{locale}: no eligible quality result; CER and coverage are unavailable")
        for engine in ("baseline", "docling"):
            eligible = [r for r in rows if r["locale"] == locale and r["engine"] == engine and r.get("quality_eligible")]
            if eligible and not any(r.get("cer_raw") is not None for r in eligible):
                metric_unavailable.append(f"{locale}/{engine}: CER and coverage unavailable because no eligible fixture completed successfully")
    verdict, strata, performance, pipeline_performance, locale_metrics = summarize(rows, config, blockers, args.mode)
    out = pathlib.Path(args.output).resolve() / run_id; out.mkdir(parents=True, exist_ok=False)
    result = {"schema_version": 3, "run_id": run_id, "created_at": datetime.now(timezone.utc).isoformat(), "mode": args.mode, "non_official": args.mode == "exploratory", "thresholds_applied": args.mode == "official" and not any(config["gates"].get(key) is None for key in QUALITY_KEYS), "verdict": verdict, "environment": environment(config), "config": config, "corpus": manifest, "blockers": blockers, "decision_requirements": decision_requirements, "metric_unavailable": metric_unavailable, "strata": strata, "performance": performance, "pipeline_performance": pipeline_performance, "locale_metrics": locale_metrics, "per_document": rows}
    (out / "result.json").write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    fields = sorted({key for row in rows for key in row}) or ["document_id", "locale", "stratum", "engine", "status", "error_code", "observation_ok"]
    with (out / "per_document.csv").open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=fields); writer.writeheader(); writer.writerows(rows)
    if args.mode == "exploratory":
        write_per_page_evidence(out, page_records)
    (out / "report.md").write_text(report_markdown(result), encoding="utf-8")
    (out / "decision-package.md").write_text(decision_package_markdown(result), encoding="utf-8")
    print(f"{verdict}: {out}")
    return 0 if args.mode == "exploratory" and not blockers else (0 if verdict == "A0_PASS" else 2)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--corpus", default=str(ROOT / "corpus" / "manifest.json"))
    parser.add_argument("--output", default=str(ROOT / "results"))
    parser.add_argument("--run-id")
    parser.add_argument("--mode", choices=("official", "exploratory"), default="official")
    parser.add_argument("--worker")
    args = parser.parse_args()
    if args.worker: worker(json.loads(args.worker)); return 0
    return run(args)


if __name__ == "__main__": raise SystemExit(main())
