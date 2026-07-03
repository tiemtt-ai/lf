@extends('layouts.backend')

@section('title', __('lf.LF_course_template_common_edit'))
@section('page_title', __('lf.LF_course_template_common_edit'))

@section('content')
    @php
        $tabs = [
            'information' => __('lf.LF_course_template_tab_information'),
            'structure' => __('lf.LF_course_template_tab_structure'),
            'teachers' => __('lf.LF_course_template_tab_teachers'),
            'versions' => __('lf.LF_course_template_tab_versions'),
        ];
        $activeTab = request()->query('tab', 'information');
        $activeTab = array_key_exists($activeTab, $tabs)
            ? $activeTab
            : 'information';
    @endphp

    @if (session('success'))
        <div class="admin-alert admin-alert-success admin-form-card">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="course-template-authoring">
        <nav class="course-template-tabs"
             aria-label="{{ __('lf.LF_course_template_tabs_label') }}">
            @foreach ($tabs as $tab => $label)
                <a href="{{ route(
                    $routePrefix.'.edit',
                    ['id' => $template->id, 'tab' => $tab]
                ) }}"
                   @class([
                       'course-template-tab',
                       'is-active' => $activeTab === $tab,
                   ])
                   @if ($activeTab === $tab) aria-current="page" @endif>
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        <section id="course-template-tab-information"
                 class="course-template-tab-panel"
                 @if ($activeTab !== 'information') hidden @endif>
            <div class="admin-card admin-form-card">
                <form method="POST" action="{{ route($routePrefix.'.update', $template->id) }}">
                    @csrf
                    @method('PUT')

                    @include('course-templates.partials.form')

                    <div class="admin-form-actions">
                        <button type="submit" class="btn btn-primary">
                            {{ __('lf.LF_common_button_save_changes') }}
                        </button>
                        <a href="{{ route($routePrefix.'.index') }}">
                            {{ __('lf.LF_common_button_cancel') }}
                        </a>
                    </div>
                </form>
            </div>
        </section>

        <section id="course-template-tab-structure"
                 class="course-template-tab-panel"
                 @if ($activeTab !== 'structure') hidden @endif>
            @include('course-template-sections.partials.list')
        </section>

        <section id="course-template-tab-teachers"
                 class="course-template-tab-panel"
                 @if ($activeTab !== 'teachers') hidden @endif>
            @include('course-template-teachers.partials.list')
        </section>

        <section id="course-template-tab-versions"
                 class="course-template-tab-panel"
                 @if ($activeTab !== 'versions') hidden @endif>
            <div class="admin-card admin-form-card course-template-version-placeholder">
                <h2 class="admin-form-section-title">
                    {{ __('lf.LF_course_template_tab_versions') }}
                </h2>
                <p>{{ __('lf.LF_course_template_versions_placeholder') }}</p>
            </div>
        </section>
    </div>
@endsection
