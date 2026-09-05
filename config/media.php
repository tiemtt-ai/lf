<?php

return [
    'disk' => env('MEDIA_DISK', 'media_local'),
    'bucket' => env(
        'MEDIA_STORAGE_BUCKET',
        env('MEDIA_AWS_BUCKET', env('AWS_BUCKET', 'local-media'))
    ),
    'region' => env('MEDIA_AWS_DEFAULT_REGION', env('AWS_DEFAULT_REGION')),
    'storage_class' => env('MEDIA_AWS_STORAGE_CLASS'),
    'signed_url_ttl_minutes' => (int) env('MEDIA_SIGNED_URL_TTL_MINUTES', 10),
    'max_upload_kilobytes' => (int) env('MEDIA_MAX_UPLOAD_KILOBYTES', 102400),
    'ffprobe_binary' => env('MEDIA_FFPROBE_BINARY', 'ffprobe'),
    'ffprobe_timeout_seconds' => (int) env('MEDIA_FFPROBE_TIMEOUT_SECONDS', 15),
    'processing' => [
        'document' => [
            'locales' => ['vi', 'ko', 'en'],
            'max_locales' => 3,
            'formula_normalization_min_confidence' => 80.0,
            // Formula evidence is atomic math, not an exercise paragraph which
            // happens to contain '=' or a superscript.
            'formula_evidence_max_characters' => 180,
            'formula_evidence_max_words' => 24,
            // ADR-0019 v1.8: evidence formula doi it nhat mot ky tu trong tap nay.
            // `+` va `-` co chu dich khong nam trong day: chung xuat hien day dac
            // trong mau bien doi ngu phap tieng Han (`무 + -아요/어요`) va la nguon
            // false positive da do duoc, khong phai dau hieu cua cong thuc.
            'formula_operators' => '=<>±√∑Σ∫∆Δ∩∪∈∉⊂⊆∞≈≠≤≥×÷²³⁰¹⁴⁵⁶⁷⁸⁹₀₁₂₃₄₅₆₇₈₉',
        ],
        'fake' => [
            'virus_infected' => (bool) env('MEDIA_FAKE_VIRUS_INFECTED', false),
        ],
        'local_document' => [
            'pdftotext_binary' => env('MEDIA_PDFTOTEXT_BINARY', 'pdftotext'),
            'pdftoppm_binary' => env('MEDIA_PDFTOPPM_BINARY', 'pdftoppm'),
            'pdfinfo_binary' => env('MEDIA_PDFINFO_BINARY', 'pdfinfo'),
            'tesseract_binary' => env('MEDIA_TESSERACT_BINARY', 'tesseract'),
            'soffice_binary' => env('MEDIA_SOFFICE_BINARY', 'soffice'),
            'command_timeout_seconds' => (int) env('MEDIA_DOCUMENT_COMMAND_TIMEOUT_SECONDS', 300),
            'office_timeout_seconds' => (int) env('MEDIA_OFFICE_TIMEOUT_SECONDS', 900),
            'max_processing_seconds' => (int) env('MEDIA_DOCUMENT_MAX_PROCESSING_SECONDS', 3300),
            'max_pages' => (int) env('MEDIA_DOCUMENT_MAX_PAGES', 100),
            'max_docx_xml_bytes' => (int) env('MEDIA_DOCX_MAX_XML_BYTES', 8000000),
            'ocr_dpi' => (int) env('MEDIA_OCR_DPI', 200),
            'max_extracted_characters' => (int) env('MEDIA_MAX_EXTRACTED_CHARACTERS', 500000),
        ],
        // Speech-to-text Phase 1. Gia tri contract theo
        // LF-Media-Processing-Contract § Speech-to-text resource controls,
        // khong phai tuning tuy y.
        'speech_to_text' => [
            'mime_types' => [
                'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav',
                'audio/ogg', 'audio/webm', 'audio/mp4',
            ],
            // Vuot tran thi FAIL CA JOB bang `audio_limit_exceeded`, khong cat bot:
            // transcript cua 120 phut dau tren file ba gio khien consumer khong
            // phan biet duoc "het noi dung" voi "bi cat", ma citation van hop le.
            'max_bytes' => (int) env('MEDIA_STT_MAX_BYTES', 1073741824),
            'max_duration_seconds' => (int) env('MEDIA_STT_MAX_DURATION_SECONDS', 7200),
            // Provider deadline phai nho hon job timeout 3600s; retry_after la
            // 3900s. Neu bang nhau, worker co the giet job truoc khi provider
            // tra loi co kiem soat va ghi error_code.
            'timeout_seconds' => (int) env('MEDIA_STT_TIMEOUT_SECONDS', 3300),
            // canonicalLocale() chi validate cu phap BCP 47. Khong co allowlist
            // thi `fr` di lot toi provider.
            'locales' => ['vi', 'ko', 'en'],
            'diarization' => 'off',
            // Video co profile hieu nang khac han audio: do tren video that cho
            // RTF 0,48, audio bai giang 0,19-0,28. 7.200s x 0,48 = 3.432s, vuot
            // provider deadline 3.300s. Xem Amendment Record 2.21 § 2.
            // Mac dinh TAT. DOC-CONFLICT-0027 Temporary Safety Rule: video STT chi
            // chay o development/test cho toi khi tran thoi luong duoc do lai tren
            // hardware production. Khong co gate nay thi deploy code se tu dong bat
            // video STT o bat ky he thong nao dang bat STT audio.
            'video_enabled' => (bool) env('MEDIA_VIDEO_STT_ENABLED', false),
            'video_mime_types' => [
                'video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska',
            ],
            'max_video_source_bytes' => (int) env('MEDIA_STT_MAX_VIDEO_SOURCE_BYTES', 1073741824),
            'max_video_duration_seconds' => (int) env('MEDIA_STT_MAX_VIDEO_DURATION_SECONDS', 5400),
            'python_binary' => env('MEDIA_STT_PYTHON_BINARY', base_path('runtime/stt/.venv/bin/python')),
            'script' => env('MEDIA_STT_SCRIPT', base_path('runtime/stt/transcribe.py')),
            'model_path' => env('MEDIA_STT_MODEL_PATH', base_path('runtime/stt/models/small')),
            'compute_type' => env('MEDIA_STT_COMPUTE_TYPE', 'int8'),
            'threads' => (int) env('MEDIA_STT_THREADS', 0),
            'max_output_bytes' => (int) env('MEDIA_STT_MAX_OUTPUT_BYTES', 16777216),
        ],
        // Tach audio tu video truoc khi dua vao STT.
        // LF-Media-Processing-Contract Amendment Record 2.21 § 2, § 3.
        //
        // Moi TRANSFORMATION input o day (binary/version, codec, sample format,
        // sample rate, channels, filters) di vao canonical profile/hash va
        // `processing_version`. Timeout, budget va workspace la runtime controls,
        // khong doi noi dung nen khong duoc lam sinh revision moi.
        'video_audio' => [
            // Duong dan tuyet doi. KHONG fallback sang ffmpeg tren PATH: mot
            // binary khac tren may khac se pha revision identity va parity giua
            // local va production.
            'ffmpeg_binary' => env('MEDIA_FFMPEG_BINARY', '/usr/local/bin/ffmpeg'),
            // Version la INVENTORY cua deployment, khong phai ket qua probe.
            //
            // Truoc day version duoc doc bang `ffmpeg -version` ngay luc TAO job —
            // co the tren web node khong co ffmpeg. Node do ghi `unavailable` vao
            // processing_version, roi worker CO ffmpeg xu ly bang binary that:
            // output duoc luu duoi mot identity noi rang ffmpeg khong ton tai.
            // Hong im lang, transcript van `ready`.
            //
            // Nay identity den tu config, va worker KIEM binary that khop inventory
            // truoc khi xu ly. Lech thi fail-closed.
            'ffmpeg_version' => env('MEDIA_FFMPEG_VERSION', ''),
            'timeout_seconds' => (int) env('MEDIA_VIDEO_AUDIO_TIMEOUT_SECONDS', 600),
            'codec' => 'pcm_s16le',
            'sample_format' => 's16',   // truyen bang -sample_fmt; xem VideoAudioExtractionProfile
            'sample_rate' => 16000,
            'channels' => 1,
            'filters' => [],
            // 5.400s x 32.000 byte/s = 164,8 MiB. 256 MiB la muc phat hien cau
            // hinh bat thuong, khong phai muc van hanh.
            'max_output_bytes' => (int) env('MEDIA_VIDEO_AUDIO_MAX_OUTPUT_BYTES', 268435456),
            'workspace_root' => env('MEDIA_VIDEO_AUDIO_WORKSPACE', sys_get_temp_dir()),
        ],
        'video_qualification' => [
            // Local/test proves correctness. Production additionally requires a
            // PASS evidence record bound to the exact runtime identity/caps.
            'required' => (bool) env('MEDIA_VIDEO_STT_QUALIFICATION_REQUIRED', env('APP_ENV') === 'production'),
            'evidence_path' => env('MEDIA_VIDEO_STT_QUALIFICATION_EVIDENCE', ''),
        ],
        // Caption Phase 1: VTT dung tu transcript, khong chay model.
        // LF-Media-Processing-Contract Amendment Record 2.21 § 6.
        'caption' => [
            'format' => 'vtt',
            // Do tren video that: 23,1 cue/phut. O tran 5.400s la ~2.079 cue,
            // nen 10.000 cho bien 4,8x. Con so cu 5.000 chot tren mau chi gom
            // audio tieng Han (4,5-7,0 cue/phut) va chi con bien 2,4x.
            'max_cues' => (int) env('MEDIA_CAPTION_MAX_CUES', 10000),
            // 1.527 byte/phut => 134 KB o 90 phut; 1 MiB cho bien 7,6x.
            'max_bytes' => (int) env('MEDIA_CAPTION_MAX_BYTES', 1048576),
        ],
        'structured_extraction' => [
            'max_pages' => (int) env('MEDIA_STRUCTURED_MAX_PAGES', 100),
            'max_extracted_characters' => (int) env('MEDIA_STRUCTURED_MAX_EXTRACTED_CHARACTERS', 500000),
            'max_regions_per_page' => (int) env('MEDIA_STRUCTURED_MAX_REGIONS_PER_PAGE', 100),
            'max_regions_per_document' => (int) env('MEDIA_STRUCTURED_MAX_REGIONS_PER_DOCUMENT', 5000),
            'max_table_cells_per_document' => (int) env('MEDIA_STRUCTURED_MAX_TABLE_CELLS_PER_DOCUMENT', 200000),
            'max_processing_seconds' => (int) env('MEDIA_STRUCTURED_MAX_PROCESSING_SECONDS', 3300),
            'command_timeout_seconds' => (int) env('MEDIA_STRUCTURED_COMMAND_TIMEOUT_SECONDS', 900),
            'crop_enabled' => (bool) env('MEDIA_STRUCTURED_CROP_ENABLED', true),
            'crop_dpi' => (int) env('MEDIA_STRUCTURED_CROP_DPI', 200),
            // Tap vung du dieu kien cat crop. Consumer tu tinh duoc vi tu nay tu
            // `role` + `bbox` da co san trong unit; xem Spec B § 5.3. Mo them role
            // phai do lai tran dung luong.
            'crop_roles' => ['figure', 'image', 'chart', 'diagram', 'geometry', 'formula'],
            'crop_ocr_enabled' => (bool) env('MEDIA_STRUCTURED_CROP_OCR_ENABLED', true),
            // Text ngan hon nguong nay chua du de coi la noi dung figure co y
            // nghia. OCR chi thay the khi ket qua dai hon text hien co.
            'crop_ocr_min_text_characters' => (int) env('MEDIA_STRUCTURED_CROP_OCR_MIN_TEXT_CHARACTERS', 2),
            // Locale canonical -> Tesseract packs. `eng` la fallback da freeze
            // cho vi/ko vi tai lieu hoc thuong chen ten, nhan va cum tieng Anh.
            // Provider flatten + deduplicate pack khi profile co nhieu locale.
            // Nguong hieu chuan tren 23 vung OCR co text cua mot tai lieu that:
            // rac tu 20% tro len, noi dung that duoi 10%. Khong phai con so doan.
            'text_symbol_ratio_max' => (float) env('MEDIA_STRUCTURED_TEXT_SYMBOL_RATIO_MAX', 0.2),
            'crop_ocr_languages' => [
                'vi' => 'vie+eng', 'en' => 'eng', 'ko' => 'kor+eng', 'ja' => 'jpn',
                'zh' => 'chi_sim', 'fr' => 'fra', 'de' => 'deu', 'es' => 'spa',
            ],
            'max_crop_bytes_per_document' => (int) env('MEDIA_STRUCTURED_MAX_CROP_BYTES_PER_DOCUMENT', 67108864),
        ],
        'docling' => [
            'python_binary' => env('MEDIA_DOCLING_PYTHON_BINARY', base_path('runtime/docling/.venv/bin/python')),
            'script' => env('MEDIA_DOCLING_SCRIPT', base_path('runtime/docling/extract.py')),
            'artifacts_path' => env('MEDIA_DOCLING_ARTIFACTS_PATH', base_path('runtime/docling/models')),
            'timeout_seconds' => (int) env('MEDIA_DOCLING_TIMEOUT_SECONDS', 3300),
            'max_output_bytes' => (int) env('MEDIA_DOCLING_MAX_OUTPUT_BYTES', 67108864),
        ],
        'limits' => [
            'max_extracted_characters' => (int) env('MEDIA_MAX_EXTRACTED_CHARACTERS', 500000),
        ],
        'providers' => [
            'virus_scan' => env('MEDIA_VIRUS_SCAN_PROVIDER', 'unconfigured'),
            'ocr' => env('MEDIA_OCR_PROVIDER', 'unconfigured'),
            'speech_to_text' => env('MEDIA_SPEECH_TO_TEXT_PROVIDER', 'unconfigured'),
            'caption' => env('MEDIA_CAPTION_PROVIDER', 'unconfigured'),
            'thumbnail' => env('MEDIA_THUMBNAIL_PROVIDER', 'unconfigured'),
            'transcode' => env('MEDIA_TRANSCODE_PROVIDER', 'unconfigured'),
            'structured_extraction' => env('MEDIA_STRUCTURED_EXTRACTION_PROVIDER', 'unconfigured'),
        ],
        'versions' => [
            'virus_scan' => env('MEDIA_VIRUS_SCAN_VERSION', 'unconfigured-v1'),
            'ocr' => env('MEDIA_OCR_VERSION', 'unconfigured-v1'),
            'speech_to_text' => env('MEDIA_SPEECH_TO_TEXT_VERSION', 'unconfigured-v1'),
            'caption' => env('MEDIA_CAPTION_VERSION', 'unconfigured-v1'),
            'thumbnail' => env('MEDIA_THUMBNAIL_VERSION', 'unconfigured-v1'),
            'transcode' => env('MEDIA_TRANSCODE_VERSION', 'unconfigured-v1'),
            'structured_extraction' => env('MEDIA_STRUCTURED_EXTRACTION_VERSION', 'unconfigured-v1'),
        ],
    ],
    'file_types' => [
        'image',
        'video',
        'audio',
        'document',
        'subtitle',
        'transcript',
        'archive',
        'other',
    ],
    'visibility' => [
        'private',
        'organization',
        'public',
    ],
    'owner_types' => [
        'course_template',
        'course_template_version',
        'course_category',
        'course_product',
        'course_activity',
        'course_version_activity',
        'course_cohort',
        'assessment_question',
        'assessment_answer',
        'liveclass_recording',
        'certificate',
        'avatar',
        'ai_knowledge',
        'marketing',
    ],
    'usage_types' => [
        'intro_image',
        'intro_video',
        'intro_document',
        'cover_image',
        'thumbnail',
        'banner_image',
        'video',
        'audio',
        'document',
        'attachment',
        'recording',
        'certificate_pdf',
        'avatar_image',
        'source_material',
    ],
];
