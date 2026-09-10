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
