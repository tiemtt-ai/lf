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
                {{ __('lf.LF_course_template_version_detail_information') }}
            </h2>

            <dl class="course-version-information-grid">
                <div>
                    <dt>{{ __('lf.LF_course_template_common_name') }}</dt>
                    <dd>{{ $version->title_snapshot }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_common_slug') }}</dt>
                    <dd>{{ $version->slug_snapshot }}</dd>
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
                    <dt>{{ __('lf.LF_course_template_common_language') }}</dt>
                    <dd>{{ $version->language_snapshot
                        ?? __('lf.LF_course_template_publish_not_available') }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_common_estimated_duration_minutes') }}</dt>
                    <dd>{{ $version->estimated_duration_minutes_snapshot }}</dd>
                </div>
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

            @foreach ($sections as $section)
                <section class="course-version-group course-version-section">
                    <div class="course-version-item-heading">
                        <div>
                            <p class="course-version-eyebrow">
                                {{ __('lf.LF_course_template_version_detail_section_order', [
                                    'order' => $section->sort_order,
                                ]) }}
                                @if ($section->parent_title)
                                    · {{ __('lf.LF_course_template_version_detail_parent', [
                                        'parent' => $section->parent_title,
                                    ]) }}
                                @endif
                            </p>
                            <h3>{{ $section->title_snapshot }}</h3>
                        </div>
                        <span class="badge">
                            {{ __('lf.LF_course_template_section_common_'.$section->status_snapshot) }}
                        </span>
                    </div>

                    @if ($section->description_snapshot)
                        <p>{{ $section->description_snapshot }}</p>
                    @endif

                    <div class="course-version-lessons">
                        @forelse (
                            $lessonsBySection->get($section->id, collect())
                            as $lesson
                        )
                            @include('course-template-versions.partials.lesson')
                        @empty
                            <p class="course-version-empty">
                                {{ __('lf.LF_course_template_version_detail_no_lessons') }}
                            </p>
                        @endforelse
                    </div>
                </section>
            @endforeach

            @if ($directLessons->isEmpty() && $sections->isEmpty())
                <p class="course-version-empty">
                    {{ __('lf.LF_course_template_version_detail_no_content') }}
                </p>
            @endif
        </section>
    </div>
@endsection
