#!/usr/bin/env bash
set -euo pipefail
ROOT="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PY="$ROOT/.venv/bin/python"
if [[ ! -x "$PY" ]]; then PYTHON="${A0_PYTHON:-python3}"; else PYTHON="$PY"; fi
"$PYTHON" -m unittest discover -s "$ROOT/tests" -p 'test_*.py' -v
"$PYTHON" -m py_compile "$ROOT/a0_benchmark.py"

