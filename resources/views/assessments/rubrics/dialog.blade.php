@php
    $isEdit = $rubric !== null;
    $selectedStatus = $rubric?->status ?? 'active';
    $selectLabels = 'data-ui-empty-label="'.e(__('lf.LF_assessment_category_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_assessment_category_clear_selection')).'"';
@endphp
<form class="ui-modal__form" method="POST" novalidate
      action="{{ $isEdit ? route($routePrefix.'.update', $rubric->id) : route($routePrefix.'.store') }}"
      x-data="{
          items: @js($items),
          get total() { return this.items.reduce((sum, item) => sum + (parseFloat(item.max_points) || 0), 0); },
          add() { this.items.push({ title: '', description: '', max_points: '' }); this.$nextTick(() => this.$el.querySelector('[data-rubric-row]:last-of-type input')?.focus()); },
      }">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-rubric-dialog-title">
                {{ $isEdit ? __('lf.LF_assessment_rubric_edit') : __('lf.LF_assessment_rubric_create') }}
            </h3>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="assessment-rubric-form-title"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_rubric_title') }}</label>
                <input id="assessment-rubric-form-title" type="text" name="title" value="{{ $rubric?->title }}" required maxlength="255"
                       placeholder="{{ __('lf.LF_assessment_rubric_title_placeholder') }}">
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-rubric-form-skill">{{ __('lf.LF_assessment_question_skill') }}</label>
                <select id="assessment-rubric-form-skill" name="skill_type" data-ui-kit-select {!! $selectLabels !!}>
                    <option value="">{{ __('lf.LF_assessment_question_not_set') }}</option>
                    @foreach (\App\Services\Assessment\AssessmentQuestionService::SKILLS as $skill)
                        <option value="{{ $skill }}" @selected($rubric?->skill_type === $skill)>{{ __('lf.LF_assessment_question_skill_'.$skill) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-rubric-form-status"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_category_status') }}</label>
                <select id="assessment-rubric-form-status" name="status" data-ui-kit-select required {!! $selectLabels !!}>
                    @foreach (\App\Services\Assessment\AssessmentRubricService::STATUSES as $statusOption)
                        <option value="{{ $statusOption }}" @selected($selectedStatus === $statusOption)>{{ __('lf.LF_assessment_rubric_status_label_'.$statusOption) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="assessment-rubric-form-description">{{ __('lf.LF_assessment_rubric_description') }}</label>
                <textarea id="assessment-rubric-form-description" name="description" rows="2" maxlength="5000"
                          placeholder="{{ __('lf.LF_assessment_rubric_description_placeholder') }}">{{ $rubric?->description }}</textarea>
            </div>

            <div class="ui-field ui-field--full" data-ui-error-for="items">
                <span class="ui-field__label"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_rubric_items') }}</span>
                <p class="ui-caption">{{ __('lf.LF_assessment_rubric_items_help') }}</p>
                <div class="ui-rubric-items" role="group" aria-label="{{ __('lf.LF_assessment_rubric_items') }}">
                    <template x-for="(item, index) in items" :key="index">
                        <div class="ui-rubric-items__row" data-rubric-row>
                            <input type="text" :name="'items[' + index + '][title]'" x-model="item.title" maxlength="255"
                                   placeholder="{{ __('lf.LF_assessment_rubric_item_title_placeholder') }}"
                                   :aria-label="@js(__('lf.LF_assessment_rubric_item_title')) + ' ' + (index + 1)">
                            <input type="number" :name="'items[' + index + '][max_points]'" x-model="item.max_points" min="0.01" max="9999.99" step="0.01" inputmode="decimal"
                                   placeholder="{{ __('lf.LF_assessment_rubric_item_points') }}"
                                   :aria-label="@js(__('lf.LF_assessment_rubric_item_points')) + ' ' + (index + 1)">
                            <button type="button" class="ux-row-delete cursor-interaction" x-on:click="items.splice(index, 1)" :disabled="items.length <= 1"
                                    :aria-label="@js(__('lf.LF_assessment_rubric_item_remove')) + ' ' + (index + 1)">
                                <x-backend-icon name="trash" class="ui-icon" />
                            </button>
                            <textarea :name="'items[' + index + '][description]'" x-model="item.description" rows="2" maxlength="2000"
                                      placeholder="{{ __('lf.LF_assessment_rubric_item_description_placeholder') }}"
                                      :aria-label="@js(__('lf.LF_assessment_rubric_item_description')) + ' ' + (index + 1)"></textarea>
                        </div>
                    </template>
                </div>
                <div>
                    <button type="button" class="ui-button ui-button--outline cursor-interaction" x-on:click="add()"
                            :disabled="items.length >= {{ \App\Services\Assessment\AssessmentRubricService::MAX_ITEMS }}">
                        <x-backend-icon name="plus" class="ui-icon" />
                        {{ __('lf.LF_assessment_rubric_item_add') }}
                    </button>
                </div>
                <p class="ui-caption ui-rubric-items__total" aria-live="polite">
                    {{ __('lf.LF_assessment_rubric_total') }}: <strong x-text="(Math.round(total * 100) / 100).toLocaleString()"></strong>
                </p>
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
            {{ __('lf.LF_common_button_confirm') }}
        </button>
    </footer>
</form>
