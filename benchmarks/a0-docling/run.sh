#!/usr/bin/env bash
set -euo pipefail

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PYTHON="${A0_PYTHON:-/usr/local/opt/python@3.11/bin/python3.11}"
VENV="${A0_VENV:-$ROOT/.venv-py311}"

if [[ ! -x "$PYTHON" ]]; then
  echo "A0 Python 3.11 executable unavailable: $PYTHON" >&2
  exit 2
fi
if ! "$PYTHON" -c 'import sys; raise SystemExit(sys.version_info[:2] != (3, 11))'; then
  echo "A0 requires Python 3.11 exactly; refusing: $PYTHON" >&2
  exit 2
fi

if [[ "${A0_SKIP_INSTALL:-0}" != "1" ]] && { [[ ! -x "$VENV/bin/python" ]] || ! "$VENV/bin/python" -c 'import importlib.metadata as m, sys; expected={"docling":"2.119.0","psutil":"7.0.0","numpy":"1.26.4","transformers":"4.57.6"}; raise SystemExit(sys.version_info[:2] != (3, 11) or any(m.version(k) != v for k,v in expected.items()))' 2>/dev/null; }; then
  if [[ ! -x "$VENV/bin/python" ]]; then "$PYTHON" -m venv "$VENV"; fi
  "$VENV/bin/python" -m pip install --disable-pip-version-check -r "$ROOT/requirements.lock"
fi

EXEC="$VENV/bin/python"
if [[ ! -x "$EXEC" ]]; then
  echo "A0 Python 3.11 environment unavailable: $VENV" >&2
  exit 2
fi
export DOCLING_ARTIFACTS_PATH="$ROOT/models"
export HF_HUB_OFFLINE=1
export TRANSFORMERS_OFFLINE=1
exec "$EXEC" "$ROOT/a0_benchmark.py" "$@"
