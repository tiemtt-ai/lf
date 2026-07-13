@extends('layouts.backend')

@section('title', __('lf.LF_course_template_version_detail_title'))
@section('page_title', __('lf.LF_course_template_version_detail_title'))

@section('content')
    <div class="course-template-version-detail">
        <div class="course-version-toolbar">
            <a href="{{ route(
                'admin.course-templates.edit',
                ['id' => $template->id, 'tab' => 'history']
            ) }}">
                ← {{ __('lf.LF_course_template_version_detail_back') }}
            </a>

            @if (in_array(
                $version->status,
                ['published', 'deprecated', 'archived'],
                true
            ))
                <form method="POST"
                      action="{{ route(
                          'admin.course-templates.versions.duplicate-to-draft',
                          [
                              'templateId' => $template->id,
                              'versionId' => $version->id,
                          ]
                      ) }}"
                      onsubmit="return window.confirm(@js(
                          __('lf.LF_course_template_duplicate_confirmation')
                      ))">
                    @csrf
                    <button type="submit" class="btn btn-secondary">
                        {{ __('lf.LF_course_template_duplicate_action') }}
                    </button>
                </form>
            @endif
        </div>

        <section class="admin-card admin-form-card course-version-summary-card">
            <div class="course-version-title-row">
                <div>
                    <p class="course-version-eyebrow">
                        {{ __('lf.LF_course_template_version_number', [
                            'version' => $version->version_number,
                        ]) }}
                    </p>
                    <h2>{{ $version->title_snapshot }}</h2>
                </div>

                @if ($version->is_current)
                    <span class="badge badge-success">
                        {{ __('lf.LF_course_template_version_current') }}
                    </span>
                @endif
            </div>

            <dl class="course-version-summary-grid">
                <div>
                    <dt>{{ __('lf.LF_course_template_history_status') }}</dt>
                    <dd>{{ __('lf.LF_course_template_version_status_'.$version->status) }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_history_published_at') }}</dt>
                    <dd>{{ \Illuminate\Support\Carbon::parse(
                        $version->published_at
                    )->format('d/m/Y H:i') }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_history_published_by') }}</dt>
                    <dd>{{ $version->published_by_name
                        ?? __('lf.LF_course_template_history_unknown_publisher') }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_version_detail_code') }}</dt>
                    <dd>{{ $version->version_code }}</dd>
                </div>
            </dl>
        </section>

        <section class="admin-card admin-form-card">
            <h2 class="admin-form-section-title">
                {{ __('lf.LF_course_template_version_detail_media') }}
            </h2>

            <dl class="course-version-information-grid">
                @foreach ([
                    'image' => __('lf.LF_course_template_intro_image'),
                    'video' => __('lf.LF_course_template_intro_video'),
                    'document' => __('lf.LF_course_template_intro_document'),
                ] as $slot => $label)
                    @php
                        $snapshot = $templateVersionMedia[$slot];
                    @endphp
                    <div>
                        <dt>{{ $label }}</dt>
                        <dd>
                            @if ($snapshot['state'] === 'empty')
                                {{ __('lf.LF_course_template_version_detail_no_media') }}
                            @elseif ($snapshot['state'] === 'unavailable')
                                {{ __('lf.LF_version_detail_media_unavailable') }}
                            @elseif ($snapshot['kind'] === 'embed')
                                <x-media-thumbnail
                                    :presentation="$snapshot['thumbnail']"
                                    :alt="$label" />
                                <div>{{ ucfirst($snapshot['provider']) }}</div>
                                <iframe class="media-library-modal-video course-template-embed-preview"
                                        src="{{ $snapshot['url'] }}"
                                        title="{{ $label }}"
                                        loading="lazy"
                                        sandbox="allow-scripts allow-same-origin allow-presentation"
                                        allow="fullscreen; picture-in-picture"></iframe>
                            @else
                                <x-media-thumbnail
                                    :presentation="$snapshot['thumbnail']"
                                    :alt="$snapshot['media']->display_name" />
                                <div>{{ $snapshot['media']->display_name }}</div>
                                <a href="{{ $snapshot['url'] }}"
                                   target="_blank"
                                   rel="noopener">
                                    {{ __('lf.LF_media_file_common_preview_action') }}
                                </a>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="admin-card admin-form-card">
            <h2 class="admin-form-section-title">
                {{ __('lf.LF_course_template_version_detail_information') }}
            </h2>

            <dl class="course-version-information-grid">
                <div>
                    <dt>{{ __('lf.LF_course_template_common_name') }}</dt>
                    <dd>{{ $version->title_snapshot }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_common_category') }}</dt>
                    <dd>{{ $version->category_name_snapshot
                        ?? __('lf.LF_course_template_common_no_category') }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_common_publisher_name') }}</dt>
                    <dd>{{ $version->publisher_name_snapshot
                        ?? __('lf.LF_course_template_publish_not_available') }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_common_difficulty_level') }}</dt>
                    <dd>{{ $version->difficulty_level_snapshot
                        ? __('lf.LF_course_template_common_'.$version->difficulty_level_snapshot)
                        : __('lf.LF_course_template_common_no_difficulty') }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_estimated_minutes_per_lesson') }}</dt>
                    <dd>{{ $version->estimated_minutes_per_lesson_snapshot ?? '—' }}</dd>
                </div>
                <div><dt>{{ __('lf.LF_course_template_estimated_lesson_count') }}</dt><dd>{{ $version->estimated_lesson_count_snapshot ?? '—' }}</dd></div>
                <div>
                    <dt>{{ __('lf.LF_course_template_version_detail_lesson_count') }}</dt>
                    <dd>{{ $version->lesson_count_snapshot }}</dd>
                </div>
            </dl>

            @if ($version->short_description_snapshot)
                <div class="course-version-copy">
                    <h3>{{ __('lf.LF_course_template_common_short_description') }}</h3>
                    <p>{{ $version->short_description_snapshot }}</p>
                </div>
            @endif

            @if ($version->description_snapshot)
                <div class="course-version-copy">
                    <h3>{{ __('lf.LF_course_template_common_description') }}</h3>
                    <p>{{ $version->description_snapshot }}</p>
                </div>
            @endif
        </section>

        <section class="admin-card admin-form-card">
            @php
                $sectionsByParent = $sections->groupBy(
                    fn (object $section): string => (string) ($section->parent_version_section_id ?? 'root')
                );
            @endphp

            <h2 class="admin-form-section-title">
                {{ __('lf.LF_course_template_version_detail_content') }}
            </h2>

            @if ($directLessons->isNotEmpty())
                <section class="course-version-group">
                    <h3>{{ __('lf.LF_course_template_version_detail_direct_lessons') }}</h3>
                    <div class="course-version-lessons">
                        @foreach ($directLessons as $lesson)
                            @include('course-template-versions.partials.lesson')
                        @endforeach
                    </div>
                </section>
            @endif

            @foreach ($sectionsByParent->get('root', collect()) as $section)
                @include('course-template-versions.partials.section-node', [
                    'depth' => 0,
                ])
            @endforeach

            @if ($directLessons->isEmpty() && $sections->isEmpty())
                <p class="course-version-empty">
                    {{ __('lf.LF_course_template_version_detail_no_content') }}
                </p>
            @endif
        </section>
    </div>
@endsection
