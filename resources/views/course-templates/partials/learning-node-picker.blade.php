{{--
    Attach competencies to one lesson: a UI Kit popup with a search box and one card per competency of the framework.
    Competencies already attached to the lesson come last, dimmed, with an "attached" badge. The form keeps the async
    save (data-lf-mapping-async: the page swaps #course-learning and focuses the lesson row) and reopens with the
    validation errors after a full-page submit (old('mapping_picker')).
--}}
@php
    $isOpenWithErrors = old('mapping_picker') === $pickerId && $errors->any();
    $pickerNodes = $nodes->sortBy(fn ($node) => $lessonNodeIds->contains((int) $node->id) ? 1 : 0)->values();
    $availableCount = $nodes->reject(fn ($node) => $lessonNodeIds->contains((int) $node->id))->count();
@endphp
<dialog id="{{ $pickerId }}" x-ref="mappingDialog" class="ui-modal" data-columns="2" aria-labelledby="{{ $pickerId }}-title"
        x-on:click="if ($event.target === $el) $el.close()"
        @if ($isOpenWithErrors) x-init="$nextTick(() => $el.showModal())" @endif>
    @if ($availableCount === 0)
        <div class="ui-modal__form">
            <header class="ui-modal__header">
                <div>
                    <h3 id="{{ $pickerId }}-title">{{ __('lf.LF_course_template_learning_add') }}</h3>
                    <span class="ui-caption">{{ $lesson->title }}</span>
                </div>
                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$el.closest('dialog').close()" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
            </header>
            <div class="ui-modal__body"><p class="ui-learning-no-result">{{ __('lf.LF_course_template_learning_all_mapped') }}</p></div>
            <footer class="ui-modal__footer">
                <button type="button" class="ui-button cursor-interaction" x-on:click="$el.closest('dialog').close()">{{ __('lf.LF_common_button_close') }}</button>
            </footer>
        </div>
    @else
        <form method="POST" action="{{ route($routePrefix.'.learning-mappings.store', $template->id) }}"
              class="ui-modal__form" data-lf-mapping-async
              data-error-message="{{ __('lf.LF_course_template_learning_attach_network_error') }}"
              data-refresh-error="{{ __('lf.LF_course_template_learning_attach_refresh_error') }}"
              x-data="{ chosen: @js(old('mapping_picker') === $pickerId ? old('learning_node_ids', []) : []), invalid: false, saving: false, q: '' }"
              x-on:mapping-saving="saving = $event.detail"
              x-on:submit="if (! chosen.length) { $event.preventDefault(); invalid = true; }"
              aria-label="{{ __('lf.LF_course_template_learning_picker_label', ['lesson' => $lesson->title]) }}">
            @csrf
            <input type="hidden" name="mapping_picker" value="{{ $pickerId }}">
            <input type="hidden" name="mapping_role" value="teaches">
            <input type="hidden" name="source" value="course_template_lesson:{{ $lesson->id }}">
            <header class="ui-modal__header">
                <div>
                    <h3 id="{{ $pickerId }}-title">{{ __('lf.LF_course_template_learning_add') }}</h3>
                    <span class="ui-caption">{{ $lesson->title }}</span>
                </div>
                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$el.closest('form').reset(); chosen = []; invalid = false; $el.closest('dialog').close()" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
            </header>
            <div class="ui-modal__body">
                @if ($isOpenWithErrors)
                    <div class="ui-learning-note ui-learning-note--danger" role="alert">
                        @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                    </div>
                @endif
                <p class="ui-learning-note ui-learning-note--danger" data-lf-mapping-error role="alert" hidden></p>
                <p class="ui-field__error" role="alert" x-show="invalid && ! chosen.length" x-cloak>{{ __('lf.LF_course_template_learning_error_nodes_required') }}</p>
                <label class="ui-search ui-learning-search ui-learning-search--block">
                    <x-backend-icon name="search" class="ui-icon" />
                    <input type="search" x-model="q" x-on:keydown.enter.prevent placeholder="{{ __('lf.LF_course_template_learning_picker_search') }}" aria-label="{{ __('lf.LF_course_template_learning_picker_search') }}">
                </label>
                <fieldset class="ui-fieldset-reset">
                    <legend class="ui-field__label">{{ __('lf.LF_course_template_learning_nodes_legend') }} <span class="ui-caption">· {{ __('lf.LF_course_template_learning_ui_available', ['count' => $availableCount]) }}</span></legend>
                    <div class="ui-learning-nodes">
                        @foreach ($pickerNodes as $node)
                            @php
                                $attached = $lessonNodeIds->contains((int) $node->id);
                            @endphp
                            <label class="ui-learning-node" x-show="! q || @js($lower($node->code_snapshot.' '.$node->name_snapshot.' '.($node->description_snapshot ?? ''))).includes(q.trim().toLowerCase())">
                                @if ($attached)
                                    <input type="checkbox" disabled checked>
                                @else
                                    <input type="checkbox" name="learning_node_ids[]" value="{{ $node->id }}" x-model="chosen">
                                @endif
                                <span class="ui-learning-node__body">
                                    <span class="ui-learning-node__top">
                                        <span class="ui-learning-code">{{ $node->code_snapshot }}</span>
                                        <span class="ui-learning-node-name">{{ $node->name_snapshot }}</span>
                                        @if ($attached)<span class="ui-learning-badge ui-learning-badge--muted">{{ __('lf.LF_course_template_learning_ui_attached') }}</span>@endif
                                    </span>
                                    @if ($node->description_snapshot ?? null)
                                        <span class="ui-learning-node__desc">{{ $node->description_snapshot }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            </div>
            <footer class="ui-modal__footer">
                <span class="ui-caption" aria-live="polite" x-text="@js(__('lf.LF_course_template_learning_picker_count')).replace(':count', chosen.length)"></span>
                <button type="button" class="ui-button cursor-interaction" x-on:click="$el.closest('form').reset(); chosen = []; invalid = false; $el.closest('dialog').close()">{{ __('lf.LF_common_button_cancel') }}</button>
                <button type="submit" class="ui-button ui-button--primary cursor-interaction" x-bind:disabled="chosen.length === 0 || saving"
                        data-loading-label="{{ __('lf.LF_course_template_learning_attaching') }}">{{ __('lf.LF_course_template_learning_submit') }}</button>
            </footer>
        </form>
    @endif
</dialog>
