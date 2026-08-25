# Approved corpus mount point

Do not commit benchmark documents or ground truth here. Mount or copy the
Mount or copy the Owner-approved corpus into `files/` and `ground-truth/`, then
create `manifest.json` from `manifest.example.json`. Both data directories and
the real manifest are intentionally ignored.

PII is not an automatic rejection under ADR-0018. A
PII-bearing corpus must record source/approval evidence, local-only storage,
restricted access, `external_processing_allowed: false`, and an explicit
retention/deletion date. Missing evidence/approval or a required external call
is `DECISION_REQUIRED`. Use a separately fingerprinted redacted derivative or a
non-PII source when those conditions cannot be met.

The runner rejects a manifest unless `approval.status` is `approved`, approval
identity/date are present, every path remains inside the corpus root, and S1–S10
contain the protocol minimum for both `vi` and `ko`. S11–S14 are also validated;
S11/S12 are measurement-only strata.
