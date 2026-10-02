@php
    // Handed over by CourseTemplateActivityController::aiAuthoringEntry(): routes
    // and the Template's own Framework selection only. No proposal content is
    // rendered on the server; it is fetched after page load under the same
    // authority checks as every proposal command.
    $aiConfig = [
        'isAdmin' => $aiAuthoring['is_admin'],
        'framework' => $aiAuthoring['framework'],
        // Draft versions an admin may create a new Node in; empty for a teacher.
        'draftVersions' => $aiAuthoring['draft_versions'],
        // Published versions an admin may copy into a new draft; empty for a teacher.
        'publishedVersions' => $aiAuthoring['published_versions'],
        'urls' => $aiAuthoring['urls'],
        // The Template's mapping list, only for an admin: a teacher has no such page.
        'mappingsUrl' => $aiAuthoring['is_admin'] ? $aiAuthoring['framework_url'] : null,
        // The session's own token, as in any form on the page; writes send it in a header.
        'csrfToken' => csrf_token(),
    ];

    // Every text the script shows comes from the same lf.* keys, VI or EN by the
    // current locale: the script holds no wording of its own.
    $aiMessages = collect(__('lf'))
        ->filter(fn ($text, $key) => is_string($text) && str_starts_with($key, 'LF_ai_authoring_'))
        ->mapWithKeys(fn ($text, $key) => [substr($key, strlen('LF_ai_authoring_')) => $text])
        ->all();
@endphp
@if (! $aiAuthoring['media_supported'])
    <section id="ai-authoring" class="ai-authoring" aria-labelledby="ai-authoring-title" data-ai-authoring-unsupported>
        <h3 id="ai-authoring-title">{{ __('lf.LF_ai_authoring_title') }}</h3>
        <p class="lf-secondary-text">{{ __('lf.LF_ai_authoring_no_media') }}</p>
    </section>
@else
<section id="ai-authoring"
         class="ai-authoring"
         aria-labelledby="ai-authoring-title"
         data-ai-authoring="{{ json_encode($aiConfig, JSON_UNESCAPED_SLASHES) }}"
         data-ai-authoring-messages="{{ json_encode($aiMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}">
    <h3 id="ai-authoring-title">{{ __('lf.LF_ai_authoring_title') }}</h3>
    <p class="lf-secondary-text">{{ __('lf.LF_ai_authoring_intro') }}</p>
    <p class="lf-secondary-text">{{ __('lf.LF_ai_authoring_accept_note') }}</p>

    @if ($aiAuthoring['framework']['selected'])
        <p class="lf-secondary-text" data-ai-authoring-framework="selected">
            {{ __('lf.LF_ai_authoring_framework_ready') }}
        </p>
    @elseif ($aiAuthoring['is_admin'])
        <p class="lf-secondary-text" data-ai-authoring-framework="missing">
            {{ __('lf.LF_ai_authoring_framework_missing_admin') }}
            <a href="{{ $aiAuthoring['framework_url'] }}">{{ __('lf.LF_ai_authoring_framework_link') }}</a>
        </p>
    @else
        <p class="lf-secondary-text" data-ai-authoring-framework="missing">
            {{ __('lf.LF_ai_authoring_framework_missing_teacher') }}
        </p>
    @endif

    <div class="sr-only" data-ai-authoring-status role="status" aria-live="polite"></div>
    <div data-ai-authoring-list></div>
    <noscript>
        <p class="lf-secondary-text">{{ __('lf.LF_ai_authoring_noscript') }}</p>
    </noscript>
</section>
@endif
