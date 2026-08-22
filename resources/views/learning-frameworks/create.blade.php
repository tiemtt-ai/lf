@extends('layouts.backend')
@section('title', __('lf.LF_learning_create_framework'))
@section('content')
<div class="admin-page-header"><h1>{{ __('lf.LF_learning_create_framework') }}</h1></div>
@include('learning-frameworks.partials.errors')
<form class="admin-card" method="POST" action="{{ route('admin.learning-frameworks.store') }}">@csrf
    @include('learning-frameworks.partials.framework-fields', ['framework' => null, 'masteryScale' => ['levels' => [['key' => 'not_started', 'threshold' => 0], ['key' => 'mastered', 'threshold' => 1]]]])
    <button class="admin-btn admin-btn-primary" type="submit">{{ __('lf.LF_common_button_save') }}</button>
</form>
@endsection
