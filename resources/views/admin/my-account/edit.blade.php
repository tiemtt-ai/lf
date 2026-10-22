@extends('layouts.backend')

@section('title', __('lf.LF_profile_title_admin_my_account'))
@section('page_title', __('lf.LF_profile_title_admin_my_account'))

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js'])
@endsection

@section('content')
    @include('partials.account.page', ['user' => $user, 'editUrl' => route('admin.my-account.edit'), 'passwordUrl' => route('admin.my-account.password.edit')])
@endsection
