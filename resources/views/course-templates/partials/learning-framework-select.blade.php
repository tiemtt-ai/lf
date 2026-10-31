{{--
    Choose the framework and version of the Template. In the "change" popup ($inDialog) it says how many links the change
    removes and applies only a version other than the current one; without a selection yet it is an inline form.
--}}
@php
    $currentValue = $selection ? $selection->framework_id.':'.$selection->framework_version_id : '';
@endphp
<form method="POST" action="{{ route($routePrefix.'.learning-framework.select', $template->id) }}"
      @class(['ui-modal__form' => $inDialog, 'ui-learning-empty__form' => ! $inDialog])
      x-data="{
          current: @js($currentValue), picked: @js($currentValue),
          reset(button) {
              const select = this.$root.querySelector('select');
              select.value = this.current;
              select.dispatchEvent(new Event('change', { bubbles: true }));
              button.closest('dialog')?.close();
          },
      }">
    @csrf
    @method('PUT')
    <input type="hidden" name="working_revision" value="{{ $template->working_revision }}">
    @if ($inDialog)
        <header class="ui-modal__header">
            <div>
                <h3 id="course-framework-change-title">{{ __('lf.LF_course_template_learning_framework_change') }}</h3>
                <span class="ui-caption">{{ __('lf.LF_course_template_learning_ui_change_current', ['version' => $versionLabel($selection)]) }}</span>
            </div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="reset($el)" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body ui-learning-change__body">
    @endif
    <div class="ui-field">
        <label class="ui-field__label" for="learning-framework-version"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_template_learning_select_target') }}</label>
        <select id="learning-framework-version" name="framework" required x-on:change="picked = $event.target.value"
                data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_common_button_clear') }}">
            <option value="">{{ __('lf.LF_course_template_learning_framework_select_placeholder') }}</option>
            @foreach ($versions->groupBy('framework_id') as $frameworkVersions)
                <optgroup label="{{ $frameworkVersions->first()->framework_name }}">
                    @foreach ($frameworkVersions as $version)
                        @php
                            $isCurrent = $selection && (int) $selection->framework_version_id === (int) $version->framework_version_id;
                        @endphp
                        <option value="{{ $version->framework_id }}:{{ $version->framework_version_id }}" @selected($isCurrent)>{{ $versionLabel($version) }}@if ($isCurrent) · {{ __('lf.LF_course_template_learning_current_badge') }}@endif</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
    </div>
    @if ($inDialog)
            @if ($intents->isNotEmpty())
                <p class="ui-learning-note ui-learning-note--warn" x-show="picked && picked !== current" x-cloak>
                    <x-backend-icon name="circle" class="ui-icon" />
                    <span>{{ __('lf.LF_course_template_learning_ui_change_warning', ['count' => $intents->count()]) }}</span>
                </p>
            @endif
        </div>
        <footer class="ui-modal__footer">
            <button type="button" class="ui-button cursor-interaction" x-on:click="reset($el)">{{ __('lf.LF_common_button_cancel') }}</button>
            <button type="submit" class="ui-button ui-button--primary cursor-interaction" x-bind:disabled="! picked || picked === current">{{ __('lf.LF_course_template_learning_change_apply') }}</button>
        </footer>
    @else
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" x-bind:disabled="! picked">
            <x-backend-icon name="check" class="ui-icon" />{{ __('lf.LF_course_template_learning_change_apply') }}
        </button>
    @endif
</form>
