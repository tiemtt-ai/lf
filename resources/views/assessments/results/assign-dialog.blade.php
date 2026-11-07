@php
    $total = count($ids);
@endphp
{{-- Giao chấm (ADR-0027 D3–D4): one teacher for the chosen attempts. Teachers who already grade these quizzes come first,
     then the least busy; the badges say why, so the choice stays the admin's. --}}
<form class="ui-modal__form" method="POST" novalidate data-modal-columns="2" action="{{ route($routePrefix.'.assign.store') }}"
      x-data="{ q: '', show(text) { return this.q.trim() === '' || text.includes(this.q.trim().toLowerCase()); } }">
    @csrf
    @if ($returnTo)
        <input type="hidden" name="return_to" value="{{ $returnTo }}">
    @endif
    @foreach ($ids as $id)
        <input type="hidden" name="ids[]" value="{{ $id }}">
    @endforeach
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-assign-dialog-title">{{ __('lf.LF_assessment_grader_assign_title') }}</h3>
            <p class="ui-caption">{{ trans_choice('lf.LF_assessment_grader_assign_help', $total, ['count' => $total]) }}</p>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="assessment-assign-search">{{ __('lf.LF_common_button_search') }}</label>
                <input id="assessment-assign-search" type="search" x-model="q" placeholder="{{ __('lf.LF_assessment_grader_search') }}">
            </div>
            <div class="ui-field ui-field--full" data-ui-error-for="teacher_id"
                 data-ui-required-group="teacher_id" data-ui-required-message="{{ __('lf.LF_assessment_grader_choose_teacher') }}">
                <span class="ui-field__label" id="assessment-assign-teachers">
                    <span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_grader_teacher') }}
                </span>
                <div class="ui-pick-list" role="radiogroup" aria-labelledby="assessment-assign-teachers">
                    @forelse ($teachers as $teacher)
                        <label class="ui-pick-row cursor-interaction" data-search="{{ mb_strtolower($teacher->name.' '.$teacher->email) }}" x-show="show($el.dataset.search)">
                            <input type="radio" name="teacher_id" value="{{ $teacher->id }}" @checked($currentId === (int) $teacher->id) required>
                            <span>
                                <span>{{ $teacher->name }}@if ($currentId === (int) $teacher->id) <span class="ui-caption">· {{ __('lf.LF_assessment_grader_current') }}</span>@endif</span>
                                <span class="ui-caption">{{ $teacher->email }}</span>
                            </span>
                            <span class="ui-grader-badges">
                                @if ($teacher->covers > 0)
                                    <span class="ui-status ui-status--active">{{ $teacher->covers === $total ? __('lf.LF_assessment_grader_covers_all') : __('lf.LF_assessment_grader_covers_some', ['count' => $teacher->covers, 'total' => $total]) }}</span>
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
        </div>
    </div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit @disabled($teachers->isEmpty())>
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_assessment_grader_assign') }}
        </button>
    </footer>
</form>
