#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PYTHON="${MEDIA_STT_BENCHMARK_PYTHON:-$ROOT/runtime/stt/.venv/bin/python}"

if [[ ! -x "$PYTHON" ]]; then
  echo "STT benchmark Python is unavailable: $PYTHON" >&2
  exit 2
fi

export HF_HUB_OFFLINE=1
export TRANSFORMERS_OFFLINE=1
export HF_HUB_DISABLE_TELEMETRY=1

exec "$PYTHON" "$ROOT/runtime/stt/benchmark/stt_benchmark.py" "$@"
