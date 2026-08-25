#!/usr/bin/env bash
set -euo pipefail
ROOT="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PY="$ROOT/.venv-py311/bin/python"
if [[ ! -x "$PY" ]]; then PYTHON="${A0_PYTHON:-/usr/local/opt/python@3.11/bin/python3.11}"; else PYTHON="$PY"; fi
if ! "$PYTHON" -c 'import sys; raise SystemExit(sys.version_info[:2] != (3, 11))'; then
  echo "A0 validation requires Python 3.11" >&2
  exit 2
fi
"$PYTHON" -m unittest discover -s "$ROOT/tests" -p 'test_*.py' -v
"$PYTHON" -m py_compile "$ROOT/a0_benchmark.py"
