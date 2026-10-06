# Media object filter — 2026-10-06

Owner requested dependent multiple-object filtering after owner type selection. MEDIUM audit: additive GET filter only; no migration, media mutation, tenant/access or published snapshot ownership change. Existing physical/logical owner mapping remains. Choices are tenant-owned objects with active usages. Draft and published references use distinct type/id keys to prevent ID collisions. Results match any selected object within the chosen type, alongside existing type/keyword/status filters. Changing owner type clears selections. Existing links without object selection retain behavior.

Authenticated browser verified category choices, selecting two categories and returning exactly their two image assets. Existing 34 Media tests passed; added multi-object result and tenant-choice isolation coverage. Vite build and whitespace validation passed. Screenshot `/private/tmp/lf-media-object-filter.jpg`.
