@php($useOldInput = $useOldInput ?? true)

<div class="admin-form-flow">
    <section class="admin-form-standard-section" aria-labelledby="learning-framework-identity">
        <header class="admin-form-section-header">
            <h2 id="learning-framework-identity" class="admin-form-section-title">{{ __('lf.LF_learning_group_identity') }}</h2>
            <p class="admin-form-section-help">{{ __('lf.LF_learning_group_identity_help') }}</p>
        </header>
        <div class="admin-form-field-grid">
            <div class="lf-form-group admin-form-field">
                <x-form-label for="code" :value="__('lf.LF_learning_code')" required />
                <input id="code" name="code" class="lf-form-control" required maxlength="100" autocomplete="off" value="{{ $useOldInput ? old('code', $framework->code ?? '') : ($framework->code ?? '') }}">
                <p class="lf-form-help">{{ __('lf.LF_learning_code_help') }}</p>
                @error('code')<p class="lf-form-error">{{ $message }}</p>@enderror
            </div>
            <div class="lf-form-group admin-form-field">
                <x-form-label for="name" :value="__('lf.LF_learning_name')" required />
                <input id="name" name="name" class="lf-form-control" required maxlength="255" value="{{ $useOldInput ? old('name', $framework->name ?? '') : ($framework->name ?? '') }}">
                @error('name')<p class="lf-form-error">{{ $message }}</p>@enderror
            </div>
            <div class="lf-form-group admin-form-field--full">
                <x-form-label for="description" :value="__('lf.LF_learning_description')" />
                <textarea id="description" name="description" class="lf-form-control" rows="4" maxlength="5000">{{ $useOldInput ? old('description', $framework->description ?? '') : ($framework->description ?? '') }}</textarea>
                @error('description')<p class="lf-form-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </section>
    <section class="admin-form-standard-section" aria-labelledby="learning-framework-scale">
        <header class="admin-form-section-header">
            <h2 id="learning-framework-scale" class="admin-form-section-title">{{ __('lf.LF_learning_mastery_scale') }}</h2>
            <p class="admin-form-section-help">{{ __('lf.LF_learning_mastery_scale_help') }}</p>
        </header>
        <div class="admin-form-field-grid">
            <div class="lf-form-group admin-form-field">
                <x-form-label for="mastery_scale_key" :value="__('lf.LF_learning_scale_key')" required />
                <input id="mastery_scale_key" name="mastery_scale_key" class="lf-form-control" required maxlength="100" value="{{ $useOldInput ? old('mastery_scale_key', $framework->default_mastery_scale_key ?? 'lf-default') : ($framework->default_mastery_scale_key ?? 'lf-default') }}">
                @error('mastery_scale_key')<p class="lf-form-error">{{ $message }}</p>@enderror
            </div>
            <div class="lf-form-group admin-form-field">
                <x-form-label for="mastery_scale_version" :value="__('lf.LF_learning_scale_version')" required />
                <input id="mastery_scale_version" name="mastery_scale_version" class="lf-form-control" required maxlength="50" value="{{ $useOldInput ? old('mastery_scale_version', $framework->default_mastery_scale_version ?? '1') : ($framework->default_mastery_scale_version ?? '1') }}">
                @error('mastery_scale_version')<p class="lf-form-error">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="admin-form-subsection">
            <h3 class="admin-form-subsection-title">{{ __('lf.LF_learning_levels') }}</h3>
            <div class="admin-form-field-grid">
                @foreach(($useOldInput ? old('mastery_scale.levels', $masteryScale['levels']) : $masteryScale['levels']) as $i => $level)
                    <div class="lf-form-group admin-form-field">
                        <x-form-label :for="'mastery-level-key-'.$i" :value="__('lf.LF_learning_level_number', ['number' => $i + 1])" required />
                        <input id="mastery-level-key-{{ $i }}" class="lf-form-control" name="mastery_scale[levels][{{ $i }}][key]" required maxlength="100" value="{{ $level['key'] }}">
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
    </section>
</div>
