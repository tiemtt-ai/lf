# Document multilingual and STEM local corpus

This corpus tests evidence extraction and source locators. It does not establish
semantic understanding or formula correctness.

| Fixture | Coverage |
|---|---|
| `multilingual-stem.txt` | Vietnamese, English and Korean on one document; Math, Chemistry, Physics, geometry, diagram, chart and image-caption labels |
| `structured.pdf` | Page regions, merged table cells, chart-like graphics and a process diagram |
| `mixed.pdf`, `scan.pdf`, `mixed-blank.pdf` | Embedded text, OCR, blank-page preservation and page provenance |
| `office.docx`, `office.pptx`, `office.xlsx` | Provider-supported office formats and spreadsheet cells |

The declared local provider quality corpus is currently `vi`, `en`, and `ko`.
A provider-supported locale without a reviewed fixture must remain quality
`unavailable`; an unsupported locale must fail with
`document_language_profile_unsupported`.
