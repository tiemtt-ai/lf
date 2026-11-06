<form class="ui-modal__form ui-asm-import" method="POST" enctype="multipart/form-data" novalidate
      action="{{ route($routePrefix.'.import.store') }}">
    @csrf
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-question-dialog-title">{{ __('lf.LF_assessment_question_import') }}</h3>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
            <div class="ui-field ui-field--full">
                <p class="ui-caption">{{ __('lf.LF_assessment_question_import_help', ['max' => $maxRows]) }}</p>
                <p>
                    <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.import.template') }}" download>
                        <x-backend-icon name="download" class="ui-icon" />
                        {{ __('lf.LF_assessment_question_import_template') }}
                    </a>
                </p>
            </div>
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="assessment-question-import-file"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_question_import_file') }}</label>
                <label class="ui-upload cursor-interaction" x-data="{ name: '' }">
                    <input id="assessment-question-import-file" type="file" name="file" required
                           accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                           x-on:change="name = $event.target.files[0]?.name ?? ''">
                    <x-backend-icon name="upload" class="ui-icon" />
                    <span x-show="! name">{{ __('lf.LF_assessment_question_import_file_pick') }}</span>
                    <span x-show="name" x-cloak><b x-text="name"></b></span>
                </label>
                <p class="ui-caption">{{ __('lf.LF_assessment_question_import_file_hint') }}</p>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-question-import-category">{{ __('lf.LF_assessment_question_category') }}</label>
                <select id="assessment-question-import-category" name="category_id" data-ui-kit-select
                        data-ui-empty-label="{{ __('lf.LF_assessment_category_no_options') }}"
                        data-ui-clear-label="{{ __('lf.LF_assessment_category_clear_selection') }}">
                    <option value="">{{ __('lf.LF_assessment_question_import_no_category') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-question-import-status"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_category_status') }}</label>
                <select id="assessment-question-import-status" name="status" data-ui-kit-select required
                        data-ui-empty-label="{{ __('lf.LF_assessment_category_no_options') }}"
                        data-ui-clear-label="{{ __('lf.LF_assessment_category_clear_selection') }}">
                    <option value="published" selected>{{ __('lf.LF_assessment_question_status_published') }}</option>
                    <option value="draft">{{ __('lf.LF_assessment_question_status_draft') }}</option>
                </select>
            </div>
        </div>
    </div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>
            {{ __('lf.LF_common_button_cancel') }}
        </button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit>
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_assessment_question_import_submit') }}
        </button>
    </footer>
</form>
