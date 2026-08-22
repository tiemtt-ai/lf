<div class="admin-form-grid">
    <label>{{ __('lf.LF_learning_code') }}<input name="code" required maxlength="100" value="{{ old('code', $framework->code ?? '') }}"></label>
    <label>{{ __('lf.LF_learning_name') }}<input name="name" required maxlength="255" value="{{ old('name', $framework->name ?? '') }}"></label>
    <label>{{ __('lf.LF_learning_scale_key') }}<input name="mastery_scale_key" required value="{{ old('mastery_scale_key', $framework->default_mastery_scale_key ?? 'lf-default') }}"></label>
    <label>{{ __('lf.LF_learning_scale_version') }}<input name="mastery_scale_version" required value="{{ old('mastery_scale_version', $framework->default_mastery_scale_version ?? '1') }}"></label>
</div>
<label>{{ __('lf.LF_learning_description') }}<textarea name="description">{{ old('description', $framework->description ?? '') }}</textarea></label>
<h3>{{ __('lf.LF_learning_mastery_scale') }}</h3>
@foreach(old('mastery_scale.levels', $masteryScale['levels']) as $i => $level)
<div class="admin-form-grid"><label>{{ __('lf.LF_learning_level_key') }}<input name="mastery_scale[levels][{{ $i }}][key]" required value="{{ $level['key'] }}"></label><label>{{ __('lf.LF_learning_threshold') }}<input type="number" min="0" max="1" step="0.01" name="mastery_scale[levels][{{ $i }}][threshold]" required value="{{ $level['threshold'] }}"></label></div>
@endforeach
