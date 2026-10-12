@extends('layouts.backend')

@section('title', __('lf.LF_learning_create_framework'))
@section('page_title', __('lf.LF_learning_create_framework'))

@php
    // The default rating scale; most frameworks never change it, so it lives under "Advanced".
    $defaultLevels = [
        ['key' => 'not_started', 'threshold' => 0],
        ['key' => 'developing', 'threshold' => 0.5],
        ['key' => 'proficient', 'threshold' => 0.75],
        ['key' => 'mastered', 'threshold' => 0.9],
    ];
    $levels = old('mastery_scale.levels', $defaultLevels);
    $levelLabel = fn (string $key) => \Illuminate\Support\Facades\Lang::has('lf.LF_learning_simple_level_'.$key)
        ? __('lf.LF_learning_simple_level_'.$key)
        : null;
    $advancedHasErrors = $errors->hasAny(['code', 'mastery_scale_key', 'mastery_scale_version', 'mastery_scale', 'mastery_scale.*']);
@endphp

@section('content')
    @include('learning-frameworks.partials.errors')

    <div class="admin-card admin-form-card admin-form-surface">
        <form class="admin-form-standard" method="POST" action="{{ route('admin.learning-frameworks.store') }}">
            @csrf
            <div class="admin-form-flow">
                <section class="admin-form-standard-section" aria-labelledby="framework-create-identity">
                    <header class="admin-form-section-header">
                        <h2 id="framework-create-identity" class="admin-form-section-title">{{ __('lf.LF_learning_group_identity') }}</h2>
                        <p class="admin-form-section-help">{{ __('lf.LF_learning_simple_create_help') }}</p>
                    </header>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field--full">
                            <x-form-label for="name" :value="__('lf.LF_learning_name')" required />
                            <input id="name" name="name" class="lf-form-control" required maxlength="255" value="{{ old('name') }}"
                                   placeholder="{{ __('lf.LF_learning_simple_create_name_placeholder') }}">
                            @error('name')<p class="lf-form-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="lf-form-group admin-form-field--full">
                            <x-form-label for="description" :value="__('lf.LF_learning_description')" />
                            <textarea id="description" name="description" class="lf-form-control" rows="3" maxlength="5000">{{ old('description') }}</textarea>
                            @error('description')<p class="lf-form-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <details class="admin-form-standard-section lf-framework-create__advanced" @if ($advancedHasErrors) open @endif>
                    <summary class="lf-framework-create__advanced-summary">{{ __('lf.LF_learning_simple_create_advanced') }}</summary>
                    <p class="admin-form-section-help">{{ __('lf.LF_learning_simple_create_advanced_help') }}</p>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="code" :value="__('lf.LF_learning_simple_field_code')" />
                            <input id="code" name="code" class="lf-form-control" maxlength="100" autocomplete="off" value="{{ old('code') }}">
                            <p class="lf-form-help">{{ __('lf.LF_learning_code_help') }}</p>
                            @error('code')<p class="lf-form-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="mastery_scale_key" :value="__('lf.LF_learning_scale_key')" required />
                            <input id="mastery_scale_key" name="mastery_scale_key" class="lf-form-control" required maxlength="100" value="{{ old('mastery_scale_key', 'lf-default') }}">
                            <input type="hidden" name="mastery_scale_version" value="{{ old('mastery_scale_version', '1') }}">
                            @error('mastery_scale_key')<p class="lf-form-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="admin-form-subsection">
                        <h3 class="admin-form-subsection-title">{{ __('lf.LF_learning_simple_scale_title') }}</h3>
                        <div class="admin-form-field-grid">
                            @foreach ($levels as $i => $level)
                                <div class="lf-form-group admin-form-field">
                                    <x-form-label :for="'mastery-level-key-'.$i" :value="__('lf.LF_learning_level_number', ['number' => $i + 1])" required />
                                    <input id="mastery-level-key-{{ $i }}" class="lf-form-control" name="mastery_scale[levels][{{ $i }}][key]" required maxlength="100" value="{{ $level['key'] }}">
                                    @if ($levelLabel((string) $level['key']))
                                        <p class="lf-form-help">{{ $levelLabel((string) $level['key']) }}</p>
                                    @endif
                                    @error("mastery_scale.levels.$i.key")<p class="lf-form-error">{{ $message }}</p>@enderror
                                </div>
                                <div class="lf-form-group admin-form-field">
                                    <x-form-label :for="'mastery-level-threshold-'.$i" :value="__('lf.LF_learning_threshold')" required />
                                    <input id="mastery-level-threshold-{{ $i }}" class="lf-form-control" type="number" min="0" max="1" step="0.01" name="mastery_scale[levels][{{ $i }}][threshold]" required value="{{ $level['threshold'] }}">
                                    @error("mastery_scale.levels.$i.threshold")<p class="lf-form-error">{{ $message }}</p>@enderror
                                </div>
                            @endforeach
                        </div>
                        <p class="admin-form-inline-notice"><span class="admin-form-inline-notice-icon" aria-hidden="true">i</span><span>{{ __('lf.LF_learning_threshold_help') }}</span></p>
                    </div>
                </details>
            </div>

            <footer class="admin-form-footer" data-actions-align="end">
                <div class="admin-form-footer-primary">
                    <a href="{{ route('admin.learning-frameworks.index') }}" class="btn btn-secondary">{{ __('lf.LF_common_button_cancel') }}</a>
                    <button class="btn btn-primary" type="submit">{{ __('lf.LF_learning_create_framework') }}</button>
                </div>
            </footer>
        </form>
    </div>
@endsection
