@extends('layouts.backend')

@section('title', __('lf.LF_teacher_title_teacher_dashboard'))
@section('page_title', __('lf.LF_navigation_menu_teacher_dashboard'))

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js'])
@endsection

@section('content')
    <div class="lf-admin-ui lf-admin-ui--dashboard" data-ui-dialog-form-scope>
        <x-ui-list-toasts />

        <p class="ui-dash-welcome"><strong>{{ __('lf.LF_teacher_message_teacher_welcome', ['name' => auth()->user()->name]) }}</strong> {{ __('lf.LF_teacher_message_dashboard_guidance') }}</p>

        <section aria-labelledby="teacher-dashboard-quick-actions">
            <h2 id="teacher-dashboard-quick-actions" class="ui-dash-title">{{ __('lf.LF_admin_title_quick_actions') }}</h2>
            <div class="ui-dash-shortcuts">
                <x-dashboard-shortcut :href="route('teacher.cohorts.index')" icon="users" :title="__('lf.LF_navigation_menu_teacher_cohorts')" :help="__('lf.LF_teacher_action_cohorts_help')" />
                <x-dashboard-shortcut :href="route('teacher.course-templates.index')" icon="book-open" :title="__('lf.LF_navigation_menu_common_course_templates')" :help="__('lf.LF_teacher_action_templates_help')" />
                <x-dashboard-shortcut :href="route('teacher.media.index')" icon="image" :title="__('lf.LF_navigation_menu_admin_media')" :help="__('lf.LF_teacher_action_media_help')" />
            </div>
        </section>

        <div class="ui-dash-info ui-dash-info--single">
            @include('partials.dashboard.user-card', ['user' => auth()->user(), 'editUrl' => route('teacher.profile.edit')])
        </div>

        <x-ui-form-dialog-host title-id="dashboard-dialog-title" />
    </div>
@endsection
