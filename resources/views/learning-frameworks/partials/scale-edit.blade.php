@php
    // Saving a changed scale gets the next scale version, so new Versions can be told apart from old ones.
    $currentScaleVersion = (string) $framework->default_mastery_scale_version;
    $nextScaleVersion = ctype_digit($currentScaleVersion) ? (string) ((int) $currentScaleVersion + 1) : $currentScaleVersion.'-2';
    $editLevels = collect(old('mastery_scale.levels', $masteryScale['levels']))
        ->map(fn ($level) => ['key' => (string) ($level['key'] ?? ''), 'threshold' => is_numeric($level['threshold'] ?? null) ? (string) round((float) $level['threshold'] * 100, 10) : ''])
        ->values();
@endphp
<details class="lf-framework__disclosure lf-framework__scale-edit" @if ($errors->hasAny(['mastery_scale', 'mastery_scale.*'])) open @endif>
    <summary class="btn btn-secondary">{{ __('lf.LF_learning_simple_scale_edit') }}</summary>
    <form class="lf-framework__panel" method="POST" action="{{ route('admin.learning-frameworks.update', $framework->id) }}"
          x-data="{ levels: @js($editLevels) }" data-lf-framework-scale-form>
        @csrf
        @method('PUT')
        <input type="hidden" name="code" value="{{ $framework->code }}">
        <input type="hidden" name="name" value="{{ $framework->name }}">
        <input type="hidden" name="description" value="{{ $framework->description }}">
        <input type="hidden" name="mastery_scale_key" value="{{ $framework->default_mastery_scale_key }}">
        <input type="hidden" name="mastery_scale_version" value="{{ $nextScaleVersion }}">
        <p class="admin-form-inline-notice">
            <span class="admin-form-inline-notice-icon" aria-hidden="true">i</span>
            <span>{{ __('lf.LF_learning_simple_scale_edit_help') }}</span>
        </p>
        <div class="lf-framework-create__levels">
        <template x-for="(level, index) in levels" :key="index">
            <div class="lf-framework-create__level">
                <h3 class="admin-form-subsection-title" x-text="(@js(collect($masteryScale['levels'])->mapWithKeys(fn ($level) => [$level['key'] => $levelLabel($level['key'])])))[level.key] || (@js(__('lf.LF_learning_level_number', ['number' => ':number']))).replace(':number', index + 1)"></h3>
                <div class="lf-form-group">
                    <label class="lf-form-label" :for="'scale-level-key-' + index" x-text="@js(__('lf.LF_learning_simple_scale_level_code')) + ' ' + (index + 1)"></label>
                    <input class="lf-form-control" :id="'scale-level-key-' + index" :name="'mastery_scale[levels][' + index + '][key]'" x-model="level.key" required maxlength="100" autocomplete="off">
                </div>
                <div class="lf-form-group">
                    <label class="lf-form-label" :for="'scale-level-threshold-' + index">{{ __('lf.LF_learning_threshold_percent') }}</label>
                    <input class="lf-form-control" type="number" min="0" max="100" step="any" :id="'scale-level-threshold-' + index" x-model="level.threshold" required>
                    <input type="hidden" :name="'mastery_scale[levels][' + index + '][threshold]'"
                           :value="level.threshold === '' ? '' : Number((Number(level.threshold) / 100).toFixed(12))">
                </div>
                <div class="lf-framework__panel-actions">
                <span class="lf-framework__meta" x-text="level.threshold !== '' && Number.isFinite(Number(level.threshold)) ? Number(level.threshold) + '%' : ''"></span>
                <button type="button" class="btn btn-secondary lf-framework__scale-remove"
                        x-on:click="levels.splice(index, 1)" x-bind:disabled="levels.length <= 2"
                        :aria-label="@js(__('lf.LF_learning_simple_scale_remove')) + ' ' + (index + 1)">{{ __('lf.LF_learning_simple_scale_remove') }}</button>
                </div>
            </div>
        </template>
        </div>
        @error('mastery_scale.levels')<p class="lf-form-error">{{ $message }}</p>@enderror
        @foreach ($errors->get('mastery_scale.*') as $messages)
            @foreach ($messages as $message)<p class="lf-form-error">{{ $message }}</p>@endforeach
        @endforeach
        <p class="lf-form-help">{{ __('lf.LF_learning_threshold_percent_help') }}</p>
        <div class="lf-framework__panel-actions lf-framework__scale-actions">
            <button type="button" class="btn btn-secondary" x-on:click="levels.push({ key: '', threshold: '' })">+ {{ __('lf.LF_learning_simple_scale_add') }}</button>
            <div class="lf-framework__panel-actions">
                <button type="button" class="btn btn-secondary"
                        @click="levels = @js($editLevels); $el.closest('details').open = false; $el.closest('details').querySelector('summary').focus()">{{ __('lf.LF_common_button_cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_save') }}</button>
            </div>
        </div>
    </form>
</details>
