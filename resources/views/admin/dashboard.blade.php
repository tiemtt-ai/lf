@extends('layouts.backend')

@section('title', __('lf.LF_admin_title_admin_dashboard'))
@section('page_title', __('lf.LF_navigation_menu_admin_dashboard'))

@section('content')
    <p class="admin-dashboard-welcome">
        {{ __('lf.LF_admin_message_admin_welcome', ['name' => auth()->user()->name]) }}
    </p>

    <div class="admin-card">
        <dl class="admin-profile-summary">
            <div>
                <dt>{{ __('lf.LF_common_label_name') }}</dt>
                <dd>{{ auth()->user()->name }}</dd>
            </div>
            <div>
                <dt>{{ __('lf.LF_common_label_email') }}</dt>
                <dd>{{ auth()->user()->email }}</dd>
            </div>
            <div>
                <dt>{{ __('lf.LF_common_label_role') }}</dt>
                <dd>{{ auth()->user()->role }}</dd>
            </div>
            <div>
                <dt>{{ __('lf.LF_admin_label_admin_customer_id') }}</dt>
                <dd>{{ auth()->user()->customer_id }}</dd>
            </div>
        </dl>
    </div>
@endsection
