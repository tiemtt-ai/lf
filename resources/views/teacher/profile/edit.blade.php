@extends('layouts.backend')

@section('title', __('lf.LF_teacher_title_teacher_profile'))
@section('page_title', __('lf.LF_navigation_menu_student_profile'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js'])
@endsection

@section('content')
    @include('partials.account.page', ['user' => $user, 'editUrl' => route('teacher.profile.edit'), 'updateUrl' => route('teacher.profile.update'), 'emailEditable' => true, 'passwordUrl' => route('teacher.profile.password.edit')])
@endsection
