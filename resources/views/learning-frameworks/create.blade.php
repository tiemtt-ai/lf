@extends('layouts.backend')

@section('title', __('lf.LF_learning_create_framework'))
@section('page_title', __('lf.LF_learning_create_framework'))

@section('content')
    @include('learning-frameworks.partials.errors')

    <div class="admin-card admin-form-card admin-form-surface">
        <form class="admin-form-standard" method="POST" action="{{ route('admin.learning-frameworks.store') }}">
            @csrf
            @include('learning-frameworks.partials.framework-fields', [
                'framework' => null,
                'masteryScale' => ['levels' => [
                    ['key' => 'not_started', 'threshold' => 0],
                    ['key' => 'mastered', 'threshold' => 1],
                ]],
            ])
            <footer class="admin-form-footer" data-actions-align="end">
                <div class="admin-form-footer-primary">
                    <a href="{{ route('admin.learning-frameworks.index') }}" class="btn btn-secondary">{{ __('lf.LF_common_button_cancel') }}</a>
                    <button class="btn btn-primary" type="submit">{{ __('lf.LF_learning_create_framework') }}</button>
                </div>
            </footer>
        </form>
    </div>
@endsection
