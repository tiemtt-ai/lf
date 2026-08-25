#!/usr/bin/env bash
set -euo pipefail

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PYTHON="${A0_PYTHON:-/Users/amin/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3}"
if [[ ! -x "$PYTHON" ]]; then PYTHON="${PYTHON3:-python3}"; fi

if [[ "${A0_SKIP_INSTALL:-0}" != "1" ]] && { [[ ! -x "$ROOT/.venv/bin/python" ]] || ! "$ROOT/.venv/bin/python" -c 'import importlib.metadata as m; expected={"docling":"2.119.0","psutil":"7.0.0","numpy":"1.26.4","transformers":"4.57.6"}; raise SystemExit(any(m.version(k) != v for k,v in expected.items()))' 2>/dev/null; }; then
  if [[ ! -x "$ROOT/.venv/bin/python" ]]; then "$PYTHON" -m venv "$ROOT/.venv"; fi
  "$ROOT/.venv/bin/python" -m pip install --disable-pip-version-check -r "$ROOT/requirements.lock"
fi

EXEC="$ROOT/.venv/bin/python"
if [[ ! -x "$EXEC" ]]; then EXEC="$PYTHON"; fi
export DOCLING_ARTIFACTS_PATH="$ROOT/models"
export HF_HUB_OFFLINE=1
export TRANSFORMERS_OFFLINE=1
exec "$EXEC" "$ROOT/a0_benchmark.py" "$@"
