@php
    $total = count($ids);
@endphp
{{--
    Giao chấm (ADR-0027 Amendment 1.2): name the teachers who grade the chosen quizzes. One quiz: its teachers are ticked
    and saving replaces them. Several: saving adds the ticked teachers to each quiz, or replaces each quiz's list.
    The least busy teachers come first; the badges say how many of these quizzes each one already grades.
--}}
<form class="ui-modal__form" method="POST" novalidate data-modal-columns="2" action="{{ route($routePrefix.'.graders.store') }}"
      x-data="{ q: '', mode: @js($mode), chosen: {{ count($chosen) }}, show(text) { return this.q.trim() === '' || text.includes(this.q.trim().toLowerCase()); } }"
      x-on:change="chosen = $el.querySelectorAll('input[name=&quot;user_ids[]&quot;]:checked').length">
    @csrf
    @method('PUT')
    @if ($returnTo)
        <input type="hidden" name="return_to" value="{{ $returnTo }}">
    @endif
    @foreach ($ids as $id)
        <input type="hidden" name="ids[]" value="{{ $id }}">
    @endforeach
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-quiz-graders-dialog-title">{{ __('lf.LF_assessment_grader_quizzes_title') }}</h3>
            <p class="ui-caption">
                {{ trans_choice('lf.LF_assessment_grader_quizzes_help', $total, ['count' => $total]) }}
                <span class="ui-grader-quiz-names" title="{{ $titles->implode(', ') }}">{{ $titles->implode(', ') }}@if ($total > $titles->count()) …@endif</span>
            </p>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="assessment-quiz-graders-search">{{ __('lf.LF_common_button_search') }}</label>
                <input id="assessment-quiz-graders-search" type="search" x-model="q" placeholder="{{ __('lf.LF_assessment_grader_search') }}">
            </div>
            <div class="ui-field ui-field--full" data-ui-error-for="user_ids">
                <span class="ui-field__label" id="assessment-quiz-graders-teachers">
                    {{ __('lf.LF_assessment_grader_teachers') }}
                    <span class="ui-caption" x-show="chosen > 0" x-text="@js(__('lf.LF_assessment_registration_chosen')).replace(':count', chosen)"></span>
                </span>
                <div class="ui-pick-list" role="group" aria-labelledby="assessment-quiz-graders-teachers">
                    @forelse ($teachers as $teacher)
                        <label class="ui-pick-row cursor-interaction" data-search="{{ mb_strtolower($teacher->name.' '.$teacher->email) }}" x-show="show($el.dataset.search)">
                            <input type="checkbox" class="ui-bulk-box" name="user_ids[]" value="{{ $teacher->id }}" @checked(in_array((int) $teacher->id, $chosen, true))>
                            <span>
                                <span>{{ $teacher->name }}</span>
                                <span class="ui-caption">{{ $teacher->email }}</span>
                            </span>
                            <span class="ui-grader-badges">
                                @if ($total > 1 && $teacher->covers > 0)
                                    <span class="ui-status ui-status--active">{{ $teacher->covers === $total ? __('lf.LF_assessment_grader_covers_all_quizzes') : __('lf.LF_assessment_grader_covers_some', ['count' => $teacher->covers, 'total' => $total]) }}</span>
                                @endif
                                <span @class(['ui-status', 'ui-status--inactive' => $teacher->load > 0, 'ui-status--completed' => $teacher->load === 0])>
                                    {{ trans_choice('lf.LF_assessment_grader_load', $teacher->load, ['count' => $teacher->load]) }}
                                </span>
                            </span>
                        </label>
                    @empty
                        <p class="ui-caption ui-pick-empty">{{ __('lf.LF_assessment_grader_no_teachers') }}</p>
                    @endforelse
                </div>
            </div>
            <div class="ui-field ui-field--full">
                <span class="ui-field__label" id="assessment-quiz-graders-mode">{{ __('lf.LF_assessment_grader_mode') }}</span>
                <div class="ui-chip-list" role="radiogroup" aria-labelledby="assessment-quiz-graders-mode">
                    @foreach (['add', 'replace'] as $option)
                        <label class="ui-chip cursor-interaction">
                            <input type="radio" name="mode" value="{{ $option }}" x-model="mode" @checked($mode === $option)>
                            <x-backend-icon name="check" class="ui-chip__check" />
                            {{ __('lf.LF_assessment_grader_mode_'.$option) }}
                        </label>
                    @endforeach
                </div>
                <p class="ui-caption" x-text="mode === 'add' ? @js(__('lf.LF_assessment_grader_mode_add_help')) : @js(__('lf.LF_assessment_grader_mode_replace_help'))">
                    {{ __('lf.LF_assessment_grader_mode_'.$mode.'_help') }}
                </p>
            </div>
        </div>
    </div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit
                :disabled="mode === 'add' && chosen === 0">
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_common_button_save') }}
        </button>
    </footer>
</form>
