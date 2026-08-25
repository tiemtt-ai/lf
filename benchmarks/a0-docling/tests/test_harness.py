import importlib.util
import json
import pathlib
import tempfile
import unittest
from argparse import Namespace
from contextlib import redirect_stdout
from io import StringIO
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("a0", ROOT / "a0_benchmark.py")
A0 = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(A0)


class HarnessTest(unittest.TestCase):
    def config(self):
        return json.loads((ROOT / "config.json").read_text())

    def candidate(self, root, *, approved=False):
        source = root / "quality.txt"
        source.write_text("Xin chào benchmark", encoding="utf-8")
        truth = root / "truth.json"
        truth.write_text(json.dumps({"pages": [{"page": 1, "text": "Xin chào benchmark"}]}))
        import hashlib
        manifest = {
            "corpus_id": "candidate",
            "revision": "r1" if approved else "PENDING_OWNER_REVISION",
            "approval": {"status": "approved" if approved else "pending", "approved_by": "Owner" if approved else "PENDING_OWNER_APPROVAL", "approved_at": "2026-08-25" if approved else None},
            "contains_pii": False,
            "documents": [{"id": "vi-s1", "locale": "vi", "stratum": "S1", "source": "quality.txt", "ground_truth": "truth.json", "sha256": hashlib.sha256(source.read_bytes()).hexdigest()}],
        }
        path = root / "manifest.json"
        path.write_text(json.dumps(manifest))
        return path

    def test_contract_is_exact(self):
        config = json.loads((ROOT / "config.json").read_text())
        self.assertEqual(100, config["contract"]["max_pages"])
        self.assertEqual(500000, config["contract"]["max_extracted_characters"])
        self.assertEqual(8000000, config["contract"]["max_docx_xml_bytes"])
        self.assertEqual(200, config["contract"]["ocr_dpi"])
        self.assertEqual({"vi": ["vie", "eng"], "ko": ["kor", "eng"], "en": ["eng"]}, config["locales"])
        self.assertEqual("cpu", config["docling"]["accelerator_device"])
        self.assertFalse(config["docling"]["compile_layout_model"])

    def test_metrics(self):
        self.assertEqual(0.0, A0.cer("abc", "abc"))
        self.assertGreater(A0.cer("abc", "axc"), 0)
        self.assertEqual("Tieng Viet", A0.strip_diacritics("Tiếng Việt"))
        self.assertEqual(1.0, A0.kendall_tau(["a", "b", "c"], ["a", "b", "c"]))
        self.assertEqual(1.0, A0.boundary_score("mot hai", "mot hai"))
        self.assertEqual(2 / 3, A0.boundary_score("mot hai", "mot hai ba"))
        self.assertEqual(["VI-P1-C1", "VI-P1-C2"], A0.observed_anchor_order(["VI-P1-C1", "VI-P1-C2"], "VI P1 C1 text VI-P1-C2 text"))

    def test_unapproved_manifest_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            manifest = root / "manifest.json"
            manifest.write_text(json.dumps({"approval": {"status": "draft"}, "contains_pii": False, "documents": []}))
            errors = A0.validate_manifest(manifest, json.loads((ROOT / "config.json").read_text()))
            self.assertTrue(any("approved" in error for error in errors))

    def test_owner_approved_local_only_pii_is_not_rejected_for_pii_presence(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            manifest = root / "manifest.json"
            manifest.write_text(json.dumps({
                "approval": {
                    "status": "approved",
                    "approved_by": "Architecture Owner",
                    "approved_at": "2026-08-25",
                },
                "contains_pii": True,
                "data_governance": {
                    "storage_scope": "local encrypted mount",
                    "access_restriction": "benchmark owners",
                    "external_processing_allowed": False,
                    "retention_until": "2026-09-25",
                    "deletion_required_by": "2026-09-26",
                    "source_approval_evidence": "evidence/approval.md",
                },
                "documents": [],
            }))
            errors = A0.validate_manifest(manifest, json.loads((ROOT / "config.json").read_text()))
            self.assertFalse(any("PII corpus" in error or "contains_pii" in error for error in errors))

    def test_pii_without_governance_or_with_external_processing_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            manifest = root / "manifest.json"
            manifest.write_text(json.dumps({
                "approval": {
                    "status": "approved",
                    "approved_by": "Architecture Owner",
                    "approved_at": "2026-08-25",
                },
                "contains_pii": True,
                "data_governance": {"external_processing_allowed": True},
                "documents": [],
            }))
            errors = A0.validate_manifest(manifest, json.loads((ROOT / "config.json").read_text()))
            self.assertTrue(any("governance missing" in error for error in errors))
            self.assertTrue(any("disable external processing" in error for error in errors))

    def test_expected_error_fixture_does_not_require_ground_truth(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            (root / "boundary.pdf").write_bytes(b"fixture")
            manifest = root / "manifest.json"
            manifest.write_text(json.dumps({
                "approval": {
                    "status": "approved",
                    "approved_by": "Architecture Owner",
                    "approved_at": "2026-08-25",
                },
                "contains_pii": False,
                "documents": [{
                    "id": "ko-s13-page-limit",
                    "locale": "ko",
                    "stratum": "S13",
                    "source": "boundary.pdf",
                    "expected_error": "page_limit_exceeded",
                }],
            }))
            errors = A0.validate_manifest(manifest, json.loads((ROOT / "config.json").read_text()))
            self.assertFalse(any("ground_truth" in error for error in errors))

    def test_official_with_null_thresholds_does_not_process(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            manifest = self.candidate(root)
            args = Namespace(corpus=str(manifest), output=str(root / "out"), run_id="official-null", mode="official")
            with mock.patch.object(A0, "measured_engine") as measured, mock.patch.object(A0, "dependency_errors", return_value=[]), mock.patch.object(A0, "environment", return_value={}):
                self.assertEqual(2, A0.run(args))
            measured.assert_not_called()
            result = json.loads((root / "out" / "official-null" / "result.json").read_text())
            self.assertEqual("DECISION_REQUIRED", result["verdict"])
            self.assertFalse(result["non_official"])
            self.assertFalse(result["thresholds_applied"])

    def test_exploratory_with_null_thresholds_processes_candidate_quality_fixture(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            manifest = self.candidate(root)
            args = Namespace(corpus=str(manifest), output=str(root / "out"), run_id="exploratory", mode="exploratory")
            outcome = {"status": "ready", "error_code": None, "page_count": 1, "units": [{"page": 1, "text": "Xin chào benchmark"}], "latency_seconds": 0.1, "peak_rss_bytes": 10}
            with mock.patch.object(A0, "measured_engine", return_value=outcome) as measured, mock.patch.object(A0, "dependency_errors", return_value=[]), mock.patch.object(A0, "environment", return_value={}):
                self.assertEqual(0, A0.run(args))
            self.assertEqual(2, measured.call_count)
            result = json.loads((root / "out" / "exploratory" / "result.json").read_text())
            self.assertEqual("OWNER_DECISION_REQUIRED", result["verdict"])
            self.assertTrue(result["non_official"])
            self.assertFalse(result["thresholds_applied"])
            self.assertEqual(2, len(result["per_document"]))
            self.assertTrue((root / "out" / "exploratory" / "per_page.csv").is_file())
            for engine in ("baseline", "docling"):
                page = root / "out" / "exploratory" / "per_page" / engine / "vi-s1" / "page-1.json"
                evidence = json.loads(page.read_text())
                self.assertEqual("Xin chào benchmark", evidence["ground_truth"]["normalized_text"])
                self.assertEqual("Xin chào benchmark", evidence["output"]["normalized_text"])
                self.assertTrue(evidence["non_official"])

    def test_per_page_evidence_keeps_blank_and_missing_pages_traceable(self):
        truth = {
            "pages": [
                {"page": 1, "text": "Trang một"},
                {"page": 2, "text": ""},
                {"page": 3, "text": "Trang ba", "anchors": ["P3-A1"]},
            ],
            "reading_order": ["P3-A1"],
        }
        outcome = {
            "status": "ready",
            "page_count": 3,
            "units": [{"page": 1, "text": "Trang một"}],
        }
        doc = {"id": "vi-s5-trace", "locale": "vi", "stratum": "S5"}
        metrics = A0.evaluate(outcome, truth, doc, self.config()["gates"])
        records = A0.per_page_evidence("trace-run", "docling", outcome, truth, doc, metrics, "a" * 64)
        self.assertEqual([1, 2, 3], [record["page"] for record in records])
        self.assertFalse(records[1]["ground_truth"]["has_content"])
        self.assertFalse(records[1]["output"]["has_text"])
        self.assertTrue(records[2]["ground_truth"]["has_content"])
        self.assertFalse(records[2]["output"]["has_text"])
        with tempfile.TemporaryDirectory() as directory:
            out = pathlib.Path(directory)
            A0.write_per_page_evidence(out, records)
            page = json.loads((out / "per_page" / "docling" / "vi-s5-trace" / "page-3.json").read_text())
            self.assertEqual(1.0, page["metrics"]["cer_raw"])
            self.assertFalse(page["output"]["has_text"])
            self.assertIn("per_page/docling/vi-s5-trace/page-3.json", (out / "per_page.csv").read_text())

    def test_exploratory_summary_can_never_return_official_verdict(self):
        verdict, *_ = A0.summarize([], self.config(), [], "exploratory")
        self.assertEqual("OWNER_DECISION_REQUIRED", verdict)
        self.assertNotIn(verdict, {"A0_PASS", "A0_FAIL"})

    def test_over_limit_pdf_stops_before_extraction_or_model_load(self):
        payload = {"source": "/tmp/over-limit.pdf", "locale": "ko", "engine": "docling", "config": self.config()}
        with mock.patch.object(pathlib.Path, "is_file", return_value=True), mock.patch.object(A0, "command", return_value=b"Pages:          101\n"), mock.patch.object(A0, "docling") as docling_engine, redirect_stdout(StringIO()) as output:
            A0.worker(payload)
        docling_engine.assert_not_called()
        result = json.loads(output.getvalue())
        self.assertEqual("page_limit_exceeded", result["error_code"])

    def test_official_validation_requires_complete_approval_and_thresholds(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            source = root / "source.txt"; source.write_text("truth")
            truth = root / "truth.json"; truth.write_text(json.dumps({"pages": [{"page": 1, "text": "truth"}]}))
            import hashlib
            digest = hashlib.sha256(source.read_bytes()).hexdigest()
            documents = []
            for locale in ("vi", "ko"):
                for stratum, minimum in A0.MINIMUMS.items():
                    for index in range(minimum):
                        documents.append({"id": f"{locale}-{stratum}-{index}", "locale": locale, "stratum": stratum, "source": "source.txt", "ground_truth": "truth.json", "sha256": digest})
            manifest = root / "manifest.json"
            manifest.write_text(json.dumps({"corpus_id": "approved", "revision": "r1", "approval": {"status": "approved", "approved_by": "Owner", "approved_at": "2026-08-25"}, "contains_pii": False, "documents": documents}))
            config = self.config()
            for key in A0.QUALITY_KEYS: config["gates"][key] = 0.1 if key != "page_coverage_min" else 1.0
            self.assertEqual([], A0.validate_manifest(manifest, config, "official"))


if __name__ == "__main__":
    unittest.main()
