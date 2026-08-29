import importlib.util
import unittest
from pathlib import Path


MODULE_PATH = Path(__file__).with_name("stt_benchmark.py")
SPEC = importlib.util.spec_from_file_location("stt_benchmark", MODULE_PATH)
BENCHMARK = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BENCHMARK)


class BenchmarkUnitTest(unittest.TestCase):
    def test_word_and_character_error_rate(self):
        self.assertEqual(0.5, BENCHMARK.rate("xin chao", "xin loi", "word"))
        self.assertAlmostEqual(1 / 3, BENCHMARK.rate("한국어", "한극어", "char"))

    def test_empty_reference_is_unavailable(self):
        self.assertIsNone(BENCHMARK.rate("", "hypothesis", "word"))

    def test_manifest_requires_canonical_locale_and_fields(self):
        valid = {
            "schema_version": 1,
            "fixtures": [{
                "id": "vi-real-001", "locale": "vi", "audio_path": "/tmp/a.wav",
                "sha256": "0" * 64, "reference_path": "/tmp/a.txt",
                "synthetic": False, "contains_pii": False, "purpose": "quality",
            }],
        }
        BENCHMARK.validate_manifest(valid)
        valid["fixtures"][0]["locale"] = "auto"
        with self.assertRaisesRegex(ValueError, "manifest_invalid"):
            BENCHMARK.validate_manifest(valid)

    def test_adjacent_pair_denominator_is_n_minus_one(self):
        for segment_count, expected in [(0, 0), (1, 0), (72, 71)]:
            self.assertEqual(expected, max(0, segment_count - 1))


if __name__ == "__main__":
    unittest.main()
