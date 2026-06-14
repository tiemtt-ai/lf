@extends('layouts.app')

@section('title', __('lf.LF_common_title_common_dashboard'))

@section('content')
    <h1>LF {{ __('lf.LF_common_title_common_dashboard') }}</h1>

    <p>
        {{ __('lf.LF_common_message_common_welcome_back', ['name' => Auth::user()->name]) }}
    </p>
@endsection
