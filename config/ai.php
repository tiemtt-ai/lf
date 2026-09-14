<?php

/*
 * AI provider execution gate configuration.
 *
 * LF-AI § "Provider execution gate" (Approved 2026-09-08) makes this file the
 * reviewed allow-list of step 1. It is deliberately EMPTY by default: shipping
 * this config activates no provider, exactly as shipping the AI Foundation
 * migration activated none. Adding an entry here is a reviewed act under
 * ADR-0018 § External-processing eligibility and still does not by itself
 * authorize a tenant — steps 2 to 5 of the gate remain independent.
 *
 * No credential belongs in this file. Credentials are resolved inside the
 * provider adapter, only after the gate has returned `allowed`.
 */
return [

    /*
     * Step 1 — reviewed provider/model/purpose allow-list.
     *
     * Shape:
     *   '<provider>' => [
     *       'managed'          => bool,   // true = inside the LF-managed boundary
     *       'models'           => ['<model>', ...],
     *       'purposes'         => ['<purpose>', ...],
     *       'regions'          => ['<execution region>', ...],
     *       'retention_classes'=> ['<retention class>', ...],
     *       'data_classes'     => ['<data class>', ...],
     *   ]
     *
     * `managed` records where the provider runs. ADR-0018 is explicit that a
     * provider inside the LF-managed boundary must NOT be read as external
     * approval, so this flag never shortcuts step 2.
     */
    'providers' => [],

    /*
     * Closed vocabularies. The gate refuses any request whose values fall
     * outside these lists; widening one is a contract amendment, not a local
     * decision.
     */
    'purposes' => [
        'knowledge_embedding',
        'vision_interpretation',
        'authoring_proposal',
        'tutor_answer',
        'insight_generation',
    ],

    'data_classes' => [
        'derived_text',        // OCR/transcript text already owned by Media
        'derived_structure',   // regions, tables, formulas
        'media_image',         // crops and frames
        'learner_identifier',  // pseudonymous learner references
        'personal_data',       // PII per ADR-0018
    ],

    'retention_classes' => [
        'none',            // provider retains nothing
        'transient',       // provider retains only for the call
        'provider_default',
    ],

    'regions' => [
        'lf_managed',
        'ap_southeast',
        'eu',
        'us',
    ],

    /*
     * Step 5 — safety and data-class policy.
     *
     * `forbidden_data_classes` is evaluated per purpose. A payload carrying a
     * class listed here is refused with AI_SAFETY_BLOCKED before any network
     * call. `max_retention_class` bounds what a purpose may ever ask for, so a
     * tenant setting cannot widen retention beyond the purpose's own ceiling.
     */
    'safety' => [
        'default' => [
            'forbidden_data_classes' => ['personal_data'],
            'max_retention_class' => 'transient',
        ],
        'purposes' => [],
    ],

    /*
     * Embedding worker. `model` must also appear in the provider's allow-list
     * entry above; naming it here does not approve it, it only says which
     * approved model the worker asks for.
     *
     * `chunk_batch` bounds how many chunks one worker pass claims, so a large
     * rebuild cannot hold quota for an unbounded set at once.
     */
    'embedding' => [
        'provider' => env('AI_EMBEDDING_PROVIDER'),
        'model' => env('AI_EMBEDDING_MODEL'),
        'dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 0),
        'chunk_batch' => (int) env('AI_EMBEDDING_CHUNK_BATCH', 50),

        /*
         * What the worker declares about the payload it intends to send.
         * Declaring is not approving: the allow-list entry above and the
         * tenant's `ai.external_processing.<provider>` setting still decide,
         * and the safety step still refuses anything wider than the purpose
         * allows. These values exist so the declaration is explicit and
         * reviewable instead of hard-coded in the worker.
         */
        'data_classes' => ['derived_text'],
        'execution_region' => env('AI_EMBEDDING_REGION', 'lf_managed'),
        'retention_class' => env('AI_EMBEDDING_RETENTION', 'none'),

        /*
         * How long a run may sit in `queued` before the worker treats it as
         * abandoned. Only `queued` is reaped: the gate moves a run to `running`
         * before it builds the adapter, so a `queued` run provably never
         * reached a provider. A `running` run that lost its process is left for
         * provider-aware reconciliation, which is the only thing that can know
         * whether usage occurred.
         */
        'abandoned_run_minutes' => (int) env('AI_EMBEDDING_ABANDONED_RUN_MINUTES', 30),

        /*
         * How many candidates a retrieval over-fetches from the index before
         * relational post-validation. The store cannot know that a chunk was
         * archived, a source superseded or a learner unauthorized, so hits are
         * filtered afterwards and the surplus absorbs those drops.
         */
        'retrieval_overfetch' => (int) env('AI_EMBEDDING_RETRIEVAL_OVERFETCH', 4),
    ],

    /*
     * Vector store — ADR-0006 v1.0.2 freezes Qdrant self-hosted >= 1.11 inside
     * the LF-managed boundary.
     *
     * `host` ships empty: the store is unconfigured until an operator sets it,
     * and an unconfigured store refuses every operation rather than guessing a
     * default endpoint. A collection is shared across tenants and partitioned by
     * an indexed `customer_id` payload, so every query, upsert and delete must
     * carry a tenant filter — that filter is the isolation boundary, not a
     * convenience.
     *
     * No credential belongs here; the adapter resolves one, if the deployment
     * needs it, at call time.
     */
    'vector_store' => [
        'driver' => 'qdrant',
        'host' => env('AI_QDRANT_HOST'),
        'port' => (int) env('AI_QDRANT_PORT', 6333),
        'timeout_seconds' => (int) env('AI_QDRANT_TIMEOUT', 10),
        'collection_prefix' => env('AI_QDRANT_COLLECTION_PREFIX', 'lf_text'),
    ],

    /*
     * Vision Interpretation — ADR-0020, database/ai/ai_vision_interpretations.md v1.1.
     *
     * `provider` and `model` ship empty: an unconfigured deployment interprets
     * nothing and never reaches Media Read or the gate. Naming a provider here
     * approves nothing — the allow-list, the tenant's
     * `ai.external_processing.<provider>.vision_interpretation` setting and the
     * safety step still decide.
     *
     * `data_classes` declares `media_image` only because no PII signal exists in
     * this repository yet. An image can contain PII the declaration cannot see,
     * which is why provider activation stays gated under ADR-0018 until that is
     * resolved; see LF-AI-Vision-Interpretation-Implementation-Review.
     *
     * `max_interpretation_chars` bounds what the adapter accepts. Output over the
     * bound is refused inside the adapter, so the gate records the call failed
     * and no row is written.
     */
    'vision' => [
        'provider' => env('AI_VISION_PROVIDER'),
        'model' => env('AI_VISION_MODEL'),
        'data_classes' => ['media_image'],
        'execution_region' => env('AI_VISION_REGION', 'lf_managed'),
        'retention_class' => env('AI_VISION_RETENTION', 'none'),
        'max_interpretation_chars' => (int) env('AI_VISION_MAX_CHARS', 20000),
    ],

    /*
     * Usage feature key each purpose consumes. Step 4 reserves against this
     * key. It maps onto `saas_usage_counters.feature_key`; see the OWNER
     * DECISION recorded in the Step 4 review artifact — no domain currently
     * owns usage *reservation*, so the reserver is fail-closed until one does.
     */
    'usage_feature_keys' => [
        'knowledge_embedding' => 'ai_knowledge_embedding',
        'vision_interpretation' => 'ai_vision_interpretation',
        'authoring_proposal' => 'ai_authoring_proposal',
        'tutor_answer' => 'ai_tutor',
        'insight_generation' => 'ai_insight',
    ],
];
