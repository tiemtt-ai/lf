@php
    $selectLabels = 'data-ui-empty-label="'.e(__('lf.LF_assessment_category_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_assessment_category_clear_selection')).'"';
    $chosenSection = $sections->firstWhere('id', $sectionId) ?? $sections->first();
@endphp
{{-- Picks ready questions (single ones, or whole passages) to append to one section of the quiz draft. --}}
<form class="ui-modal__form" method="POST" novalidate data-modal-columns="3"
      action="{{ route($routePrefix.'.questions.add', $quizId) }}"
      x-data="{ tab: 'questions', q: '', truncated: @js($truncated), searched: [], timer: null,
                show(text) { return this.q.trim() === '' || text.includes(this.q.trim().toLowerCase()); },
                {{-- The list holds only the newest rows: a filter also asks the whole bank and appends what is missing. --}}
                lookUp() {
                    const term = this.q.trim();
                    clearTimeout(this.timer);
                    if (! this.truncated || term.length < 2 || this.searched.includes(term.toLowerCase())) return;
                    this.timer = setTimeout(() => this.fetchRows(term), 300);
                },
                async fetchRows(term) {
                    const url = new URL(@js(route($routePrefix.'.questions.pick', $quizId)), window.location.href);
                    url.searchParams.set('q', term);
                    const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } }).catch(() => null);
                    if (! response?.ok) return;
                    const rows = await response.json();
                    this.searched.push(term.toLowerCase());
                    this.append(this.$refs.questions, rows.questions, 'question_ids[]');
                    this.append(this.$refs.passages, rows.passages, 'passage_ids[]');
                },
                append(list, html, name) {
                    const found = document.createElement('div');
                    found.innerHTML = html;
                    found.querySelectorAll('.ui-pick-row').forEach((row) => {
                        if (list.querySelector(`input[name='${name}'][value='${row.querySelector('input').value}']`)) return;
                        list.querySelector('.ui-pick-empty')?.remove();
                        list.append(row);
                    });
                } }">
    @csrf
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-quiz-picker-title">{{ __('lf.LF_assessment_quiz_add_questions') }}</h3>
            <p class="ui-caption">{{ __('lf.LF_assessment_quiz_picker_help') }}</p>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
            @if ($sections->count() > 1)
                <div class="ui-field">
                    <label class="ui-field__label" for="assessment-quiz-picker-section"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_quiz_section') }}</label>
                    <select id="assessment-quiz-picker-section" name="quiz_section_id" data-ui-kit-select required {!! $selectLabels !!}>
                        @foreach ($sections as $section)
                            <option value="{{ $section->id }}" @selected((int) $chosenSection->id === (int) $section->id)>{{ $section->title }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <input type="hidden" name="quiz_section_id" value="{{ $chosenSection->id }}">
            @endif
            <div class="ui-field">
                <span class="ui-field__label ui-field__label--tip">
                    <span id="assessment-quiz-picker-source">{{ __('lf.LF_assessment_quiz_picker_source') }}</span>
                    <span class="ui-source-icon" tabindex="0" role="img" data-tooltip-align="start" data-tooltip-placement="bottom"
                          data-tooltip="{{ __('lf.LF_assessment_quiz_picker_passages_tip') }}" aria-label="{{ __('lf.LF_assessment_quiz_picker_passages_tip') }}">
                        <x-backend-icon name="info" class="ui-icon" />
                    </span>
                </span>
                <div class="ui-chip-list" role="radiogroup" aria-labelledby="assessment-quiz-picker-source">
                    <label class="ui-chip cursor-interaction">
                        <input type="radio" value="questions" x-model="tab">
                        <x-backend-icon name="check" class="ui-chip__check" />
                        {{ __('lf.LF_assessment_quiz_picker_questions', ['count' => $questions->count()]) }}
                    </label>
                    <label class="ui-chip cursor-interaction">
                        <input type="radio" value="passages" x-model="tab">
                        <x-backend-icon name="check" class="ui-chip__check" />
                        {{ __('lf.LF_assessment_quiz_picker_passages', ['count' => $passages->count()]) }}
                    </label>
                </div>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-quiz-picker-search">{{ __('lf.LF_common_button_search') }}</label>
                <input id="assessment-quiz-picker-search" type="search" x-model="q" @input="lookUp()" placeholder="{{ __('lf.LF_assessment_quiz_picker_search') }}">
                @if ($truncated)
                    <p class="ui-caption">{{ __('lf.LF_assessment_quiz_picker_truncated') }}</p>
                @endif
            </div>

            <div class="ui-field ui-field--full" data-ui-error-for="question_ids">
                <div class="ui-pick-list" x-show="tab === 'questions'" x-ref="questions" role="group" aria-label="{{ __('lf.LF_assessment_quiz_picker_questions', ['count' => $questions->count()]) }}">
                    @include('assessments.quizzes.picker-rows', ['kind' => 'questions', 'rows' => $questions])
                    @if ($questions->isEmpty())
                        <p class="ui-caption ui-pick-empty">{{ __('lf.LF_assessment_quiz_picker_no_questions') }}</p>
                    @endif
                </div>
                <div class="ui-pick-list" x-show="tab === 'passages'" x-cloak x-ref="passages" role="group" aria-label="{{ __('lf.LF_assessment_quiz_picker_passages', ['count' => $passages->count()]) }}">
                    @include('assessments.quizzes.picker-rows', ['kind' => 'passages', 'rows' => $passages])
                    @if ($passages->isEmpty())
                        <p class="ui-caption ui-pick-empty">{{ __('lf.LF_assessment_quiz_picker_no_passages') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit>
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_assessment_quiz_add_selected') }}
        </button>
    </footer>
</form>
