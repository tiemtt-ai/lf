# LF Docling runtime

Offline A1 adapter for `structured_extraction`. It emits observations only:
regions, normalized bbox, reading order and table cells. It does not interpret
figures or replace the canonical Poppler/Tesseract OCR path.

The `.venv`, models and output are deliberately gitignored. Build locally with
Python 3.11 and `requirements.lock`; production must use the same package/model
inventory rather than downloading at worker startup.

Successful payloads are written to a private per-job temporary JSON file instead
of buffered on process stdout. PHP rejects files larger than
MEDIA_DOCLING_MAX_OUTPUT_BYTES before decoding them.
