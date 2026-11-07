{{-- Giáo viên chấm of a category or a quiz (ADR-0027 D2): pick any active teachers; those who already grade through a
     parent category are listed read only, so nobody is named twice. Saving with nobody checked clears the list. --}}
<form class="ui-modal__form" method="POST" novalidate data-modal-columns="2" action="{{ $action }}"
      x-data="{ q: '', chosen: {{ count($chosen) }}, show(text) { return this.q.trim() === '' || text.includes(this.q.trim().toLowerCase()); } }"
      x-on:change="chosen = $el.querySelectorAll('input[name=&quot;user_ids[]&quot;]:checked').length">
    @csrf
    @method('PUT')
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-grader-dialog-title">{{ $title }}</h3>
            <p class="ui-caption">{{ $help }}</p>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="assessment-grader-search">{{ __('lf.LF_common_button_search') }}</label>
                <input id="assessment-grader-search" type="search" x-model="q" placeholder="{{ __('lf.LF_assessment_grader_search') }}">
            </div>
            <div class="ui-field ui-field--full" data-ui-error-for="user_ids">
                <span class="ui-field__label" id="assessment-grader-teachers">
                    {{ __('lf.LF_assessment_grader_teachers') }}
                    <span class="ui-caption" x-show="chosen > 0" x-text="@js(__('lf.LF_assessment_registration_chosen')).replace(':count', chosen)"></span>
                </span>
                <div class="ui-pick-list" role="group" aria-labelledby="assessment-grader-teachers">
                    @forelse ($teachers as $teacher)
                        <label class="ui-pick-row cursor-interaction" data-search="{{ mb_strtolower($teacher->name.' '.$teacher->email) }}" x-show="show($el.dataset.search)">
                            <input type="checkbox" class="ui-bulk-box" name="user_ids[]" value="{{ $teacher->id }}" @checked(in_array((int) $teacher->id, $chosen, true))>
                            <span>
                                <span>{{ $teacher->name }}</span>
                                <span class="ui-caption">{{ $teacher->email }}</span>
                            </span>
                            <span></span>
                        </label>
                    @empty
                        <p class="ui-caption ui-pick-empty">{{ __('lf.LF_assessment_grader_no_teachers') }}</p>
                    @endforelse
                </div>
            </div>
            @if ($inherited->isNotEmpty())
                <div class="ui-field ui-field--full">
                    <span class="ui-field__label">{{ __('lf.LF_assessment_grader_inherited') }}</span>
                    <ul class="ui-grader-inherited">
                        @foreach ($inherited as $teacher)
                            <li>{{ $teacher->name }} <span class="ui-caption">· {{ __('lf.LF_assessment_grader_from', ['category' => $teacher->category_name]) }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit>
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_common_button_save') }}
        </button>
    </footer>
</form>
