@php
    $isEdit = $passage !== null;
    $selectLabels = 'data-ui-empty-label="'.e(__('lf.LF_assessment_category_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_assessment_category_clear_selection')).'"';
    $slots = [
        'prompt_image' => ['image', 'image/*', ['JPG', 'PNG', 'GIF', 'WEBP']],
        'prompt_audio' => ['audio', '.mp3,.wav,.m4a,.aac,.ogg', ['MP3', 'WAV', 'M4A', 'AAC', 'OGG']],
        'prompt_video' => ['video', '.mp4,.webm,.mov', ['MP4', 'WEBM', 'MOV']],
    ];
@endphp
<form class="ui-modal__form" method="POST" enctype="multipart/form-data" novalidate data-modal-columns="3"
      action="{{ $isEdit ? route($routePrefix.'.update', $passage->id) : route($routePrefix.'.store') }}">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-passage-dialog-title">
                {{ $isEdit ? __('lf.LF_assessment_passage_edit') : __('lf.LF_assessment_passage_create') }}
            </h3>
            <p class="ui-caption">{{ __('lf.LF_assessment_passages_tip') }}</p>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="assessment-passage-form-title"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_passage_title') }}</label>
                <input id="assessment-passage-form-title" type="text" name="title" value="{{ $passage?->title }}" required maxlength="255"
                       placeholder="{{ __('lf.LF_assessment_passage_title_placeholder') }}">
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-passage-form-category">{{ __('lf.LF_assessment_question_category') }}</label>
                <select id="assessment-passage-form-category" name="category_id" data-ui-kit-select {!! $selectLabels !!}>
                    <option value="">{{ __('lf.LF_assessment_question_no_category') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) $passage?->category_id === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-passage-form-skill">{{ __('lf.LF_assessment_question_skill') }}</label>
                <select id="assessment-passage-form-skill" name="skill_type" data-ui-kit-select {!! $selectLabels !!}>
                    <option value="">{{ __('lf.LF_assessment_question_not_set') }}</option>
                    @foreach (\App\Services\Assessment\AssessmentQuestionService::SKILLS as $skill)
                        <option value="{{ $skill }}" @selected($passage?->skill_type === $skill)>{{ __('lf.LF_assessment_question_skill_'.$skill) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-passage-form-level">{{ __('lf.LF_assessment_question_level') }}</label>
                <select id="assessment-passage-form-level" name="difficulty_level" data-ui-kit-select {!! $selectLabels !!}>
                    <option value="">{{ __('lf.LF_assessment_question_not_set') }}</option>
                    @foreach (\App\Services\Assessment\AssessmentQuestionService::LEVELS as $level)
                        <option value="{{ $level }}" @selected($passage?->difficulty_level === $level)>{{ __('lf.LF_assessment_question_level_'.$level) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-passage-form-status"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_category_status') }}</label>
                <select id="assessment-passage-form-status" name="status" data-ui-kit-select required {!! $selectLabels !!}>
                    @foreach (\App\Services\Assessment\AssessmentPassageService::STATUSES as $statusOption)
                        <option value="{{ $statusOption }}" @selected(($passage?->status ?? 'draft') === $statusOption)>{{ __('lf.LF_assessment_question_status_'.$statusOption) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="assessment-passage-form-content">{{ __('lf.LF_assessment_passage_content') }}</label>
                <textarea id="assessment-passage-form-content" name="content" rows="8" maxlength="50000"
                          placeholder="{{ __('lf.LF_assessment_passage_content_placeholder') }}">{{ $passage?->content }}</textarea>
            </div>

            @foreach ($slots as $role => [$kind, $accept, $formats])
                <div class="ui-field">
                    <p class="ui-field__label" id="assessment-passage-{{ $role }}-label">{{ __('lf.LF_assessment_passage_'.$role) }}</p>
                    <x-ui-media-slot :id="$role.'_file'" :name="$role.'_file'" :accept="$accept" :kind="$kind"
                        :labelledby="'assessment-passage-'.$role.'-label'"
                        :presentation="$promptMedia[$role]['presentation'] ?? null"
                        :display-name="$promptMedia[$role]['name'] ?? null"
                        :preview-url="$promptMedia[$role]['url'] ?? null"
                        :remove-name="'remove_'.$role"
                        :library-name="$role.'_library_id'" :library-caption="__('lf.LF_assessment_passage_'.$role)">
                        <x-upload-hint ui-kit :formats="$formats" />
                    </x-ui-media-slot>
                </div>
            @endforeach
        </div>
    </div>
    <x-media-library-picker />
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>
            {{ __('lf.LF_common_button_cancel') }}
        </button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit>
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_common_button_confirm') }}
        </button>
    </footer>
</form>
