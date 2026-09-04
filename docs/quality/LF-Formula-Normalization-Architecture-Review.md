# Formula Normalization Architecture Review

Version: 1.0

Document Status: Approved

Implementation Status: Not Implemented

Last Updated: 2026-09-04

Document Path: quality/LF-Formula-Normalization-Architecture-Review.md

## Review decision

**PASS FOR IMPLEMENTATION — Owner approved 2026-09-04.**

Phạm vi được duyệt là job hậu xử lý có điều kiện, dựng trên structured revision;
không bật formula enrichment inline trong Docling.

## Invariants đã review

- Chỉ materialize khi current structured revision có qualifying formula evidence.
- Không formula: không job, không load model.
- Output ở `media_formula_normalizations`; không mutate formula evidence nguồn.
- Composite tenant/source keys và persist-time stale guard là bắt buộc.
- Một job xử lý tập crop của đúng một revision để cô lập deadline/failure và giữ
  một source identity; load model một lần là hygiene tài nguyên, không phải giả
  định rằng batching cải thiện throughput.
- Queue, retry và deadline tách khỏi structured extraction.
- Worker phải fail closed khi thiếu accelerator capability. CPU Intel không được claim.
- `ready` output được phép có `confidence_score = NULL`; không tự tạo confidence.
- Batch/deadline production chỉ freeze sau benchmark trên hardware đích. Batch 24
  hiện là deployment assumption, không phải benchmark hiệu năng.

## CPU baseline dẫn tới capability gate

Phép đo 2026-09-04 trên Intel x86_64, 6 thread, engine `auto_inline`: load model
7,2 giây; inference lẻ 247–294 giây/crop; batch 5 crop 1.279,7 giây, tương đương
khoảng 256 giây/crop; RSS đỉnh 4,0 GB. Batch không tạo lợi ích throughput đáng
kể và load-once chỉ tránh khoảng 7 giây. Vì vậy CPU Intel không được claim job;
hardware đích phải benchmark độc lập trước khi freeze batch/deadline.

## Gate implementation

Migration phải có preflight/rollback fail-closed và MariaDB test cho vocabulary,
tenant FK, unique, CHECK và stale source. Runtime test phải chứng minh conditional
materialization, no-model-load khi không có formula, load-once, retry/new output,
late callback rejection và CPU capability rejection.
