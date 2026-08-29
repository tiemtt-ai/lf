# Offline STT benchmark

Harness này so sánh các model `faster-whisper` trong môi trường STT cô lập. Nó
không phải production provider và không được phép authorize runtime.

## Điều kiện đầu vào

Copy `manifest.example.json` thành một manifest ngoài Git. Mỗi fixture cần:

- audio do Owner cung cấp rõ đường dẫn;
- locale `vi`, `ko` hoặc `en`;
- transcript ground truth được đối chiếu trực tiếp;
- SHA-256 đúng;
- `synthetic` phân biệt giọng tổng hợp với người thật;
- approval/PII scope nếu nguồn có PII.

Fixture và kết quả dưới `fixtures/`, `results/` bị gitignore. Không upload audio,
ground truth, model hay output ra dịch vụ ngoài.

## Chạy

```bash
./runtime/stt/benchmark/run.sh \
  --manifest /absolute/path/manifest.json \
  --model /absolute/path/to/faster-whisper-small \
  --run-id exploratory-small-YYYYMMDD
```

Harness ép `HF_HUB_OFFLINE=1`, `TRANSFORMERS_OFFLINE=1` và
`HF_HUB_DISABLE_TELEMETRY=1`. Model phải tồn tại local; không tự tải.

Output gồm `result.json`, `per_fixture.csv`, `segments/*.json` và
`environment.json`. Transcript và segment text là dữ liệu local-only.

Không có fixture người thật có ground truth thì report ghi
`OWNER_FIXTURE_REQUIRED`; không thay bằng số 0 và không freeze engine/model.
