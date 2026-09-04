#!/usr/bin/env python3
"""Offline Docling layout adapter for LF structured extraction."""

from __future__ import annotations

import argparse
import json
import os
import pathlib
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

RESULT_PATH: pathlib.Path | None = None
COMPLETED_PAGES = 0

ROLE_MAP = {
    "title": "heading",
    "section_header": "heading",
    "text": "paragraph",
    "paragraph": "paragraph",
    "list_item": "list",
    "table": "table",
    "picture": "image",
    "image": "image",
    "chart": "chart",
    "diagram": "diagram",
    "geometry": "geometry",
    "caption": "caption",
    "page_header": "header",
    "page_footer": "footer",
    "footnote": "other",
    "formula": "formula",
}

CONTROL_CHARACTERS = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]")
MATH_OPERATORS = set("=<>±√∑Σ∫∆Δ∩∪∈∉⊂⊆∞≈≠≤≥×÷²³⁰¹⁴⁵⁶⁷⁸⁹₀₁₂₃₄₅₆₇₈₉")


def clean_text(value: object) -> str | None:
    if value is None:
        return None
    text = CONTROL_CHARACTERS.sub("", str(value)).strip()
    return text or None


def poppler_region_text(source: pathlib.Path, regions: list[dict[str, object]], binary: str = "pdftotext") -> None:
    """Replace Docling's fragmented PDF text with Poppler text in the same bbox.

    Some embedded Vietnamese fonts are decoded by Docling as ``nhi ệ t`` while
    Poppler reads the same glyphs as ``nhiệt``.  Geometry remains owned by
    Docling; this function changes text only when words whose centres fall in
    that exact region can be recovered.  Failure is optional because layout
    extraction must still work on deployments without Poppler.
    """
    try:
        result = subprocess.run(
            [binary, "-bbox-layout", str(source), "-"],
            check=True,
            capture_output=True,
            timeout=300,
        )
        root = ET.fromstring(result.stdout)
    except (OSError, subprocess.SubprocessError, ET.ParseError):
        return

    pages: dict[int, list[list[tuple[float, float, str]]]] = {}
    page_number = 0
    for page in (node for node in root.iter() if node.tag.endswith("page")):
        page_number += 1
        width = float(page.attrib.get("width", 0) or 0)
        height = float(page.attrib.get("height", 0) or 0)
        if width <= 0 or height <= 0:
            continue
        lines: list[list[tuple[float, float, str]]] = []
        for line in (node for node in page.iter() if node.tag.endswith("line")):
            words: list[tuple[float, float, str]] = []
            for word in (node for node in line if node.tag.endswith("word")):
                text = clean_text(word.text)
                if text is None:
                    continue
                x_min = float(word.attrib.get("xMin", 0) or 0)
                x_max = float(word.attrib.get("xMax", 0) or 0)
                y_min = float(word.attrib.get("yMin", 0) or 0)
                y_max = float(word.attrib.get("yMax", 0) or 0)
                words.append((((x_min + x_max) / 2) / width, ((y_min + y_max) / 2) / height, text))
            if words:
                lines.append(words)
        pages[page_number] = lines

    excluded_roles = {"image", "chart", "diagram", "geometry", "table"}
    for region in regions:
        bbox = region.get("bbox")
        if not isinstance(bbox, dict) or region.get("role") in excluded_roles:
            continue
        lines = pages.get(int(region.get("page", 0) or 0), [])
        left = float(bbox["x"])
        top = float(bbox["y"])
        right = left + float(bbox["width"])
        bottom = top + float(bbox["height"])
        recovered: list[str] = []
        for line in lines:
            selected = [text for x, y, text in line if left <= x <= right and top <= y <= bottom]
            if selected:
                recovered.append(" ".join(selected))
        text = clean_text("\n".join(recovered))
        if text is None:
            continue
        region["text"] = text
        region["metadata"]["text_source"] = "poppler_bbox"
        if region.get("role") == "formula" and isinstance(region.get("formula"), dict):
            region["formula"]["raw_text"] = text


def confidence_for(item: object, prov: object) -> float | None:
    """Return only confidence explicitly exposed by Docling; never invent one."""
    for owner in (item, prov):
        for attribute in ("confidence", "confidence_score", "score"):
            value = getattr(owner, attribute, None)
            if isinstance(value, (int, float)) and not isinstance(value, bool):
                score = float(value)
                if 0 <= score <= 1:
                    score *= 100
                if 0 <= score <= 100:
                    return round(score, 2)
    return None


# Language evidence lives in the PHP provider (`enrichRegionSignals`), which is
# the only place that resolves an observed script against the job profile. This
# script used to compute it too and was always overwritten, so two rules drifted
# apart for the same field. Layout extraction stays here; language does not.
def looks_like_formula(text: str | None) -> bool:
    """Promote only formula-dominant blocks; prose containing math stays prose."""
    if not text:
        return False
    operator_count = sum(char in MATH_OPERATORS for char in text)
    equation_count = text.count("=")
    words = re.findall(r"[^\W\d_]+", text, flags=re.UNICODE)
    return equation_count >= 2 or (operator_count >= 3 and len(words) <= 12)


def emit(payload: dict[str, object]) -> None:
    encoded = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
    if RESULT_PATH is None:
        print(encoded)
        return
    RESULT_PATH.write_text(encoded, encoding="utf-8")
    print(json.dumps({'status': 'written', 'completed_pages': COMPLETED_PAGES}))


def fail(code: str, detail: str = "") -> None:
    emit({"status": "failed", "error_code": code, "detail": detail[:500]})
    # Domain failures travel over the JSON protocol. A non-zero process exit
    # would make the PHP runner replace the stable error code with the generic
    # provider_command_failed error before the provider can decode it.
    raise SystemExit(0)


def bbox_for(prov: object, document: object) -> dict[str, float] | None:
    bbox = getattr(prov, "bbox", None)
    page_no = int(getattr(prov, "page_no", 0) or 0)
    page = getattr(document, "pages", {}).get(page_no)
    size = getattr(page, "size", None)
    width = float(getattr(size, "width", 0) or 0)
    height = float(getattr(size, "height", 0) or 0)
    if bbox is None or width <= 0 or height <= 0:
        return None
    try:
        bbox = bbox.to_top_left_origin(page_height=height)
    except Exception:
        pass
    left = float(getattr(bbox, "l", 0))
    top = float(getattr(bbox, "t", 0))
    right = float(getattr(bbox, "r", 0))
    bottom = float(getattr(bbox, "b", 0))
    x = max(0.0, min(1.0, left / width))
    y = max(0.0, min(1.0, top / height))
    box_width = max(0.0, min(1.0 - x, (right - left) / width))
    box_height = max(0.0, min(1.0 - y, (bottom - top) / height))
    if box_width <= 0 or box_height <= 0:
        return None
    return {"x": x, "y": y, "width": box_width, "height": box_height}


def table_cells(item: object, document: object) -> tuple[int, int, list[dict[str, object]]]:
    data = getattr(item, "data", None)
    rows = int(getattr(data, "num_rows", 0) or 0)
    columns = int(getattr(data, "num_cols", 0) or 0)
    cells: list[dict[str, object]] = []
    for cell in getattr(data, "table_cells", []) or []:
        cells.append({
            "row": int(cell.start_row_offset_idx) + 1,
            "column": int(cell.start_col_offset_idx) + 1,
            "row_span": int(cell.row_span),
            "column_span": int(cell.col_span),
            "is_header": bool(cell.column_header or cell.row_header),
            "text": str(cell.text or ""),
        })
    return rows, columns, cells


def table_has_header(cells: list[dict[str, object]]) -> bool:
    """Observed, never assumed: a header exists only when a cell reports one.

    `has_header` is documented as a shape observation, so it must agree with the
    per-cell `is_header` flags that travel in the same payload.
    """
    return any(bool(cell["is_header"]) for cell in cells)


def convert(source: pathlib.Path, locales: str, artifacts: pathlib.Path, max_pages: int, pdftotext: str = "pdftotext") -> dict[str, object]:
    os.environ["HF_HUB_OFFLINE"] = "1"
    os.environ["TRANSFORMERS_OFFLINE"] = "1"
    os.environ["DOCLING_SERVE_ENABLE_REMOTE_SERVICES"] = "false"
    try:
        from docling.datamodel.accelerator_options import AcceleratorDevice, AcceleratorOptions
        from docling.datamodel.base_models import InputFormat
        from docling.datamodel.pipeline_options import PdfPipelineOptions
        from docling.document_converter import DocumentConverter, PdfFormatOption
    except Exception as exc:
        fail("provider_unavailable", str(exc))

    supported = {"vi", "ko", "en"}
    requested = locales.split(",")
    if not requested or any(locale not in supported for locale in requested):
        fail("document_language_profile_unsupported", "unsupported document language profile")
    if not artifacts.is_dir():
        fail("provider_unavailable", "Docling artifacts directory is missing")

    options = PdfPipelineOptions(
        artifacts_path=artifacts,
        enable_remote_services=False,
        allow_external_plugins=False,
        accelerator_options=AcceleratorOptions(device=AcceleratorDevice.CPU),
    )
    options.layout_options.engine_options.compile_model = False
    # Hybrid A1: canonical text/OCR remains the existing Poppler/Tesseract job.
    # This process extracts layout only and must not launch its own OCR pool.
    options.do_ocr = False
    options.do_table_structure = True
    converter = DocumentConverter(format_options={InputFormat.PDF: PdfFormatOption(pipeline_options=options)})
    try:
        result = converter.convert(str(source), max_num_pages=max_pages)
    except Exception as exc:
        fail("provider_command_failed", str(exc))

    if str(getattr(result.status, "value", result.status)) != "success":
        fail("provider_command_failed", "incomplete conversion")
    global COMPLETED_PAGES
    document = result.document
    COMPLETED_PAGES = len(document.pages)
    page_ordinals: dict[int, int] = {}
    regions: list[dict[str, object]] = []
    tables: list[dict[str, object]] = []
    for item, _level in document.iterate_items():
        provenance = list(getattr(item, "prov", []) or [])
        if not provenance:
            continue
        prov = provenance[0]
        page = int(getattr(prov, "page_no", 0) or 0)
        if page < 1:
            continue
        page_ordinals[page] = page_ordinals.get(page, 0) + 1
        ordinal = page_ordinals[page]
        label = str(getattr(getattr(item, "label", None), "value", getattr(item, "label", "other")))
        role = ROLE_MAP.get(label, "other")
        text = clean_text(getattr(item, "text", None))
        bbox = bbox_for(prov, document)
        if role in {"paragraph", "other"} and looks_like_formula(text):
            role = "formula"
        metadata: dict[str, object] = {"docling_label": label}
        # Formula evidence is a child of a region that carries bbox and crop
        # (media_extracted_formulas.md). A block whose geometry Docling cannot
        # report can never satisfy that, and persistence rejects the whole
        # revision when it sees one. Keep the block as ordinary text instead and
        # record why, so one unmeasurable equation cannot cost the document.
        if role == "formula" and bbox is None:
            role = "paragraph" if text else "other"
            metadata["formula_demoted"] = "missing_bbox"
        region = {
            "locator_value": f"{page}#{ordinal}",
            "page": page,
            "ordinal": ordinal,
            "reading_order": len(regions) + 1,
            "role": role,
            "text": None if role in {"image", "chart", "diagram", "geometry"} else text,
            "bbox": bbox,
            "confidence_score": confidence_for(item, prov),
            "extraction_method": "ocr" if "ocr" in str(getattr(prov, "charspan", "")).lower() else "embedded_text",
            "metadata": metadata,
        }
        if role == "formula":
            raw = text
            region["formula"] = {
                "raw_text": raw,
                "normalized_format": None,
                "normalized_value": None,
                "normalization_status": "unavailable",
                "confidence_score": None,
            }
        region_index = len(regions)
        regions.append(region)
        if role == "table":
            rows, columns, cells = table_cells(item, document)
            if rows > 0 and columns > 0:
                tables.append({
                    "region_index": region_index,
                    "locator_type": "region",
                    "locator_value": region["locator_value"],
                    "sequence": len(tables) + 1,
                    "row_count": rows,
                    "column_count": columns,
                    "has_header": table_has_header(cells),
                    "extraction_method": "embedded_text",
                    "cells": cells,
                })
    poppler_region_text(source, regions, pdftotext)
    return {"status": "ready", "regions": regions, "tables": tables, "page_count": len(document.pages)}


def main() -> None:
    global RESULT_PATH
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", required=True)
    parser.add_argument("--locales", required=True)
    parser.add_argument("--artifacts", required=True)
    parser.add_argument("--max-pages", type=int, default=100)
    parser.add_argument("--pdftotext", default="pdftotext")
    parser.add_argument("--output")
    parser.add_argument("--summary", action="store_true")
    args = parser.parse_args()
    RESULT_PATH = pathlib.Path(args.output) if args.output else None
    result = convert(pathlib.Path(args.source), args.locales, pathlib.Path(args.artifacts), args.max_pages, args.pdftotext)
    if args.summary:
        roles: dict[str, int] = {}
        pages: dict[str, int] = {}
        for region in result.get("regions", []):
            role = str(region["role"])
            roles[role] = roles.get(role, 0) + 1
            page = str(region["page"])
            pages[page] = pages.get(page, 0) + 1
        result = {
            "status": result["status"],
            "page_count": result["page_count"],
            "region_count": len(result.get("regions", [])),
            "table_count": len(result.get("tables", [])),
            "roles": roles,
            "regions_per_page": pages,
            "table_pages": [str(table["locator_value"]).split("#", 1)[0] for table in result.get("tables", [])],
        }
    emit(result)


if __name__ == "__main__":
    main()
