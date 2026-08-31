#!/usr/bin/env python3
"""Offline Docling layout adapter for LF structured extraction."""

from __future__ import annotations

import argparse
import json
import os
import pathlib
import sys

RESULT_PATH: pathlib.Path | None = None
COMPLETED_PAGES = 0

ROLE_MAP = {
    "title": "heading",
    "section_header": "heading",
    "text": "paragraph",
    "paragraph": "paragraph",
    "list_item": "list",
    "table": "table",
    "picture": "figure",
    "caption": "caption",
    "page_header": "header",
    "page_footer": "footer",
    "footnote": "other",
    "formula": "other",
}


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


def convert(source: pathlib.Path, locale: str, artifacts: pathlib.Path, max_pages: int) -> dict[str, object]:
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

    languages = {"vi": ["vie", "eng"], "ko": ["kor", "eng"], "en": ["eng"]}
    if locale not in languages:
        fail("unsupported_source", f"unsupported locale: {locale}")
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
        text = getattr(item, "text", None)
        region = {
            "locator_value": f"{page}#{ordinal}",
            "page": page,
            "ordinal": ordinal,
            "reading_order": len(regions) + 1,
            "role": role,
            "text": None if role == "figure" else (str(text).strip() if text else None),
            "bbox": bbox_for(prov, document),
            "extraction_method": "ocr" if "ocr" in str(getattr(prov, "charspan", "")).lower() else "embedded_text",
            "metadata": {"docling_label": label},
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
                    "has_header": True,
                    "extraction_method": "embedded_text",
                    "cells": cells,
                })
    return {"status": "ready", "regions": regions, "tables": tables, "page_count": len(document.pages)}


def main() -> None:
    global RESULT_PATH
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", required=True)
    parser.add_argument("--locale", required=True)
    parser.add_argument("--artifacts", required=True)
    parser.add_argument("--max-pages", type=int, default=100)
    parser.add_argument("--output")
    parser.add_argument("--summary", action="store_true")
    args = parser.parse_args()
    RESULT_PATH = pathlib.Path(args.output) if args.output else None
    result = convert(pathlib.Path(args.source), args.locale, pathlib.Path(args.artifacts), args.max_pages)
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
