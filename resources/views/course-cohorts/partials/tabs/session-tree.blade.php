{{-- The class's programme: the same structure as the Content tab, from the locked Version, with dates on each lesson. --}}
@if ($contentTree['hasContent'])
    <div class="ui-cohort-session-tree">
        @include('course-template-versions.partials.content-structure', $contentTree['structure'] + [
            'sessionTree' => $contentTree + [
                'canReorder' => $cohort->is_mutable && $sessionAcceptsNew,
                'appliedLessonIds' => $appliedLessonIds,
                'firstAppliedLessonId' => $firstAppliedLessonId,
            ],
            'foldScope' => 'cohort-'.$cohort->id,
            'contentKey' => 'lf-course-cohort-'.$cohort->id,
            'structureHelp' => __('lf.LF_course_cohort_session_tree_help'),
        ])
    </div>
@elseif ($sessions->isEmpty())
    <div class="ui-cohort-session-tree__empty" role="status">
        <strong>{{ __('lf.LF_course_cohort_session_empty') }}</strong>
        <p class="ui-caption">{{ __('lf.LF_course_cohort_session_empty_help') }}</p>
    </div>
@endif

@if ($contentTree['looseSessions']->isNotEmpty())
    <section class="ui-cohort-session-tree__loose" aria-labelledby="cohort-sessions-outside-title">
        <h3 id="cohort-sessions-outside-title">{{ __('lf.LF_course_cohort_session_table_type_operational') }}</h3>
        <div class="ui-cohort-session-tree__slots">
            @foreach ($contentTree['looseSessions'] as $session)
                @include('course-cohorts.partials.tabs.session-tree-slot', ['showTitle' => true])
            @endforeach
        </div>
    </section>
@endif

{{-- Same preview popup as the Version page; x-if drops the player on close, which stops playback. --}}
<div class="lf-admin-ui">
    <x-media-preview-modal ui-kit id="cohort-session-media-preview-title" show="previewOpen" close="closeVersionPreview()" title="preview.name" compact="false">
        <template x-if="previewOpen && preview.type === 'image'">
            <img x-bind:src="preview.url" x-bind:alt="preview.name">
        </template>
        <template x-if="previewOpen && preview.type === 'video'">
            <video controls preload="metadata" autoplay x-bind:src="preview.url"></video>
        </template>
        <template x-if="previewOpen && preview.type === 'audio'">
            <audio controls preload="metadata" autoplay x-bind:src="preview.url"></audio>
        </template>
        <template x-if="previewOpen && preview.type === 'embed'">
            <iframe x-bind:src="preview.url" x-bind:title="preview.name" loading="lazy"
                    sandbox="allow-scripts allow-same-origin allow-presentation"
                    allow="autoplay; fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
        </template>
    </x-media-preview-modal>
</div>
