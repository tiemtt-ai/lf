<form class="course-learning__select" x-data="{ current: @js($selection ? $selection->framework_id.':'.$selection->framework_version_id : '') }"
      method="POST" action="{{ route($routePrefix.'.learning-framework.select', $template->id) }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="working_revision" value="{{ $template->working_revision }}">
    <div class="lf-form-group course-learning__select-field">
        <label class="lf-form-label" for="learning-framework-version">{{ __('lf.LF_course_template_learning_select_target') }}</label>
        <select id="learning-framework-version" name="framework" class="lf-form-control" required
                @change="if ($el.value !== current && $el.value) {
                    const accepted = await window.LFConfirm.open({
                        message: @js(__($intents->isEmpty() ? 'lf.LF_course_template_learning_change_empty_confirm' : 'lf.LF_course_template_learning_change_direct_confirm', ['count' => $intents->count()])).replace(':target', $el.selectedOptions[0].dataset.confirmLabel),
                        confirmLabel: @js(__('lf.LF_course_template_learning_change_apply')),
                        trigger: $el
                    });
                    if (accepted) { $el.closest('form').requestSubmit(); }
                    else { $el.value = current; }
                }">
            <option value="">{{ __('lf.LF_course_template_learning_framework_select_placeholder') }}</option>
            @foreach ($versions->groupBy('framework_id') as $frameworkVersions)
                <optgroup label="{{ $frameworkVersions->first()->framework_name }}">
                    @foreach ($frameworkVersions as $version)
                        <option data-confirm-label="{{ $versionLabel($version) }}" value="{{ $version->framework_id }}:{{ $version->framework_version_id }}" @selected($selection && (int) $selection->framework_version_id === (int) $version->framework_version_id)>{{ $version->title_snapshot ?: $version->version_code }}@if ($selection && (int) $selection->framework_version_id === (int) $version->framework_version_id) · {{ __('lf.LF_course_template_learning_current_badge') }}@endif</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
    </div>
</form>
