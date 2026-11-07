@php
    $selectLabels = 'data-ui-empty-label="'.e(__('lf.LF_assessment_category_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_assessment_category_clear_selection')).'"';
    $chosenSection = $sections->firstWhere('id', $sectionId) ?? $sections->first();
@endphp
{{--
    Ghép tự động (ADR-0025 D6a): pick a category and how many questions per skill × level; "Bốc câu" asks the server
    for a random preview (nothing saved), the user unticks what they do not want, and the form adds the rest through
    the same route as "Thêm câu hỏi".
--}}
<form class="ui-modal__form" method="POST" novalidate data-modal-columns="3"
      action="{{ route($routePrefix.'.questions.add', $quizId) }}"
      data-auto-preview-url="{{ route($routePrefix.'.questions.auto-preview', $quizId) }}"
      x-data="{
          url: $el.dataset.autoPreviewUrl,
          max: {{ $max }},
          total: 0,
          busy: false,
          picked: [],
          short: [],
          done: false,
          error: '',
          count() {
              this.total = [...$root.querySelectorAll('[data-auto-count]')].reduce((sum, input) => sum + Math.max(0, parseInt(input.value, 10) || 0), 0);
          },
          async preview() {
              if (this.busy) return;
              this.busy = true;
              this.error = '';
              try {
                  const data = new FormData($root);
                  data.delete('question_ids[]');
                  const response = await fetch(this.url, {
                      method: 'POST', body: data, credentials: 'same-origin',
                      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                  });
                  const result = await response.json().catch(() => ({}));
                  if (! response.ok) {
                      this.error = result.message || @js(__('lf.LF_assessment_quiz_auto_error_load'));
                      return;
                  }
                  this.picked = result.questions || [];
                  this.short = result.short || [];
                  this.done = true;
              } catch {
                  this.error = @js(__('lf.LF_assessment_quiz_auto_error_load'));
              } finally {
                  this.busy = false;
              }
          },
      }">
    @csrf
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-quiz-auto-title">{{ __('lf.LF_assessment_quiz_auto') }}</h3>
            <p class="ui-caption">{{ __('lf.LF_assessment_quiz_auto_help') }}</p>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
            @if ($sections->count() > 1)
                <div class="ui-field">
                    <label class="ui-field__label" for="assessment-quiz-auto-section"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_quiz_section') }}</label>
                    <select id="assessment-quiz-auto-section" name="quiz_section_id" data-ui-kit-select required {!! $selectLabels !!}>
                        @foreach ($sections as $section)
                            <option value="{{ $section->id }}" @selected((int) $chosenSection->id === (int) $section->id)>{{ $section->title }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <input type="hidden" name="quiz_section_id" value="{{ $chosenSection->id }}">
            @endif
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-quiz-auto-category">{{ __('lf.LF_assessment_question_category') }}</label>
                <select id="assessment-quiz-auto-category" name="category_id" data-ui-kit-select {!! $selectLabels !!}>
                    <option value="">{{ __('lf.LF_assessment_quiz_auto_category_all') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-switch-row" for="assessment-quiz-auto-children">
                    <span class="ui-switch-row__text">
                        <b>{{ __('lf.LF_assessment_quiz_auto_with_children') }}</b>
                        <small>{{ __('lf.LF_assessment_quiz_auto_with_children_help') }}</small>
                    </span>
                    <span class="ui-switch">
                        <input id="assessment-quiz-auto-children" type="checkbox" role="switch" name="with_children" value="1" checked>
                        <span></span>
                    </span>
                </label>
            </div>

            <div class="ui-field ui-field--full" data-ui-error-for="counts">
                <span class="ui-field__label" id="assessment-quiz-auto-table">{{ __('lf.LF_assessment_quiz_auto_table') }}</span>
                <div class="ui-table-wrap">
                    <table class="ui-table ui-auto-table" aria-labelledby="assessment-quiz-auto-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('lf.LF_assessment_question_skill') }}</th>
                                @foreach ($levels as $level)
                                    <th scope="col">{{ __('lf.LF_assessment_question_level_'.$level) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($skills as $skill)
                                <tr>
                                    <th scope="row">{{ __('lf.LF_assessment_question_skill_'.$skill) }}</th>
                                    @foreach ($levels as $level)
                                        <td>
                                            <input type="number" min="0" max="{{ $max }}" step="1" inputmode="numeric" placeholder="0"
                                                   name="counts[{{ $skill }}][{{ $level }}]" data-auto-count @input="count()"
                                                   aria-label="{{ __('lf.LF_assessment_question_skill_'.$skill) }} · {{ __('lf.LF_assessment_question_level_'.$level) }}">
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="ui-auto-actions">
                    <span class="ui-caption" x-text="@js(__('lf.LF_assessment_quiz_auto_total', ['max' => $max])).replace(':count', total)"
                          :class="total > max && 'ui-field__error'"></span>
                    <button type="button" class="ui-button ui-button--outline cursor-interaction" @click="preview()"
                            :disabled="busy || total === 0 || total > max" :aria-busy="busy">
                        <x-backend-icon name="shuffle" class="ui-icon" />
                        <span x-text="done ? @js(__('lf.LF_assessment_quiz_auto_repick')) : @js(__('lf.LF_assessment_quiz_auto_pick'))"></span>
                    </button>
                </div>
                <p class="ui-caption ui-field__error" role="alert" x-show="error" x-text="error" x-cloak></p>
            </div>

            <div class="ui-field ui-field--full" data-ui-error-for="question_ids" x-show="done" x-cloak>
                <span class="ui-field__label" id="assessment-quiz-auto-picked"
                      x-text="@js(__('lf.LF_assessment_quiz_auto_picked')).replace(':count', picked.length)"></span>
                <div class="ui-auto-short" role="status" x-show="short.length > 0">
                    <b>{{ __('lf.LF_assessment_quiz_auto_short_title') }}</b>
                    <ul>
                        <template x-for="line in short" :key="line">
                            <li x-text="line"></li>
                        </template>
                    </ul>
                </div>
                <div class="ui-pick-list" role="group" aria-labelledby="assessment-quiz-auto-picked">
                    <template x-for="question in picked" :key="question.id">
                        <label class="ui-pick-row cursor-interaction">
                            <input type="checkbox" class="ui-bulk-box" name="question_ids[]" :value="question.id" checked>
                            <span>
                                <span class="ui-passage-prompt" x-text="question.prompt"></span>
                                <span class="ui-caption" x-text="question.meta"></span>
                            </span>
                            <span class="ui-caption" x-text="question.points"></span>
                        </label>
                    </template>
                    <p class="ui-caption ui-pick-empty" x-show="picked.length === 0">{{ __('lf.LF_assessment_quiz_auto_nothing') }}</p>
                </div>
            </div>
        </div>
    </div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit :disabled="picked.length === 0">
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_assessment_quiz_add_selected') }}
        </button>
    </footer>
</form>
