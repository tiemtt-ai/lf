import json
import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE_PATH = Path(__file__).with_name("transcribe.py")
SPEC = importlib.util.spec_from_file_location("lf_stt_transcribe", MODULE_PATH)
transcribe = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(transcribe)


class FakeSegment:
    start = 0.0
    end = 1.0
    text = "안녕하세요, xin chào"
    avg_logprob = -0.1


class FakeModel:
    calls = []

    def __init__(self, *args, **kwargs):
        pass

    def transcribe(self, source, **kwargs):
        self.calls.append(kwargs)
        return iter([FakeSegment()]), object()


class TranscribeRuntimeTest(unittest.TestCase):
    def run_main(self, language_args):
        FakeModel.calls = []
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "source.wav"
            model = root / "model"
            output = root / "result.json"
            source.write_bytes(b"audio")
            model.mkdir()
            argv = [
                "transcribe.py", "--source", str(source), *language_args,
                "--model", str(model), "--output", str(output),
            ]
            with patch.object(transcribe, "WhisperModel", FakeModel), patch.object(sys, "argv", argv):
                self.assertEqual(0, transcribe.main())
            return FakeModel.calls[0], json.loads(output.read_text(encoding="utf-8"))

    def test_multi_locale_redetects_language_per_decoding_window(self):
        call, result = self.run_main(["--locales", "ko,vi"])

        self.assertIsNone(call["language"])
        self.assertTrue(call["multilingual"])
        self.assertEqual({"ko", "vi"}, {row["locale"] for row in result["units"][0]["languages"]})

    def test_single_locale_remains_explicitly_pinned(self):
        call, _ = self.run_main(["--locale", "vi"])

        self.assertEqual("vi", call["language"])
        self.assertFalse(call["multilingual"])


if __name__ == "__main__":
    unittest.main()
