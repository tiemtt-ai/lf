import importlib.util
import json
import pathlib
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("a0", ROOT / "a0_benchmark.py")
A0 = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(A0)


class HarnessTest(unittest.TestCase):
    def test_contract_is_exact(self):
        config = json.loads((ROOT / "config.json").read_text())
        self.assertEqual(100, config["contract"]["max_pages"])
        self.assertEqual(500000, config["contract"]["max_extracted_characters"])
        self.assertEqual(8000000, config["contract"]["max_docx_xml_bytes"])
        self.assertEqual(200, config["contract"]["ocr_dpi"])
        self.assertEqual({"vi": ["vie", "eng"], "ko": ["kor", "eng"], "en": ["eng"]}, config["locales"])

    def test_metrics(self):
        self.assertEqual(0.0, A0.cer("abc", "abc"))
        self.assertGreater(A0.cer("abc", "axc"), 0)
        self.assertEqual("Tieng Viet", A0.strip_diacritics("Tiếng Việt"))
        self.assertEqual(1.0, A0.kendall_tau(["a", "b", "c"], ["a", "b", "c"]))
        self.assertEqual(1.0, A0.boundary_score("mot hai", "mot hai"))

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


if __name__ == "__main__":
    unittest.main()
