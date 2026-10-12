<form class="course-learning__select" method="POST" action="{{ route($routePrefix.'.learning-framework.select', $template->id) }}">
    @csrf
    @method('PUT')
    <div class="lf-form-group course-learning__select-field">
        <label class="lf-form-label" for="learning-framework-version">{{ __('lf.LF_course_template_learning_framework_select_label') }}</label>
        <select id="learning-framework-version" name="framework" class="lf-form-control" required>
            <option value="">{{ __('lf.LF_course_template_learning_framework_select_placeholder') }}</option>
            @foreach ($versions as $version)
                <option value="{{ $version->framework_id }}:{{ $version->framework_version_id }}" @selected($selection && (int) $selection->framework_version_id === (int) $version->framework_version_id)>{{ $versionLabel($version) }}</option>
            @endforeach
        </select>
    </div>
    <button class="btn btn-primary" type="submit">{{ __('lf.LF_course_template_learning_framework_save') }}</button>
</form>
