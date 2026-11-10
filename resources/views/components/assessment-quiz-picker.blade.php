@props(['url'])
{{--
    The Quiz picker of a Quiz Activity (Quiz Picker Amendment, Owner 2026-11-10), built like the Media library view: it
    covers the form inside the same dialog. The quiz field asks for it with the `open-quiz-picker` window event and the
    choice goes back with `quiz-picked` { chosen }. Only the picker in the asking form answers. Lists published Quizzes the
    user may use (an admin: the tenant's; a teacher: their own). Escape and Back return to the form; the search box and
    type filter have no name, so nothing here is submitted with the form.
--}}
<section class="ui-library-view" role="region" aria-labelledby="ui-quiz-pick-title" x-cloak data-quiz-picker
         x-data="{
             open: false, loading: false, keyword: '', type: '', items: [], pending: null, timer: null, seq: 0,
             get pendingItem() { return this.items.find(item => item.id === this.pending) ?? null; },
             show(detail) {
                 this.pending = detail.chosenId ?? null;
                 this.keyword = '';
                 this.items = [];
                 this.open = true;
                 this.load();
                 this.$nextTick(() => this.$refs.search.focus());
             },
             back() {
                 this.open = false;
                 this.$nextTick(() => this.$root.closest('form')?.querySelector('[data-quiz-choose]')?.focus());
             },
             async load() {
                 const mine = ++this.seq;
                 this.loading = true;
                 try {
                     const query = new URLSearchParams({ keyword: this.keyword, assessment_type: this.type });
                     const response = await fetch(@js($url) + '?' + query, {
                         headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
                     });
                     const items = response.ok ? (await response.json()).items : [];
                     if (mine === this.seq) this.items = items;
                 } catch (error) {
                     if (mine === this.seq) this.items = [];
                 } finally {
                     if (mine === this.seq) this.loading = false;
                 }
             },
             search() { clearTimeout(this.timer); this.timer = setTimeout(() => this.load(), 250); },
             step(event, direction) {
                 (direction > 0 ? event.target.nextElementSibling : event.target.previousElementSibling)?.focus();
             },
             confirm() {
                 const item = this.pendingItem;
                 if (! item) return;
                 this.$dispatch('quiz-picked', { chosen: item });
                 this.back();
             },
         }"
         x-show="open"
         x-on:open-quiz-picker.window="if ($event.target?.closest?.('form') === $root.closest('form')) show($event.detail)"
         x-on:keydown.escape.prevent.stop="back()">
    <header class="ui-modal__header ui-library-view__header">
        <button type="button" class="ui-modal__close cursor-interaction" x-on:click="back()" aria-label="{{ __('lf.LF_media_pick_back') }}">
            <x-backend-icon name="arrow-left" class="ui-icon" />
        </button>
        <div>
            <h3 id="ui-quiz-pick-title">{{ __('lf.LF_course_template_activity_quiz_picker_title') }}</h3>
            <p class="ui-caption">{{ __('lf.LF_course_template_activity_quiz_hint') }}</p>
        </div>
        <button type="button" class="ui-modal__close cursor-interaction" data-ui-dialog-form-cancel aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>

    <div class="ui-library-pick__toolbar">
        <div class="ui-library-pick__toolbar-row">
            <label class="ui-search ui-library-pick__search">
                <x-backend-icon name="search" class="ui-icon" />
                <input type="search" x-ref="search" x-model="keyword" x-on:input="search()" x-on:keydown.enter.prevent
                       placeholder="{{ __('lf.LF_course_template_activity_quiz_picker_search') }}" aria-label="{{ __('lf.LF_course_template_activity_quiz_picker_search') }}" autocomplete="off">
            </label>
            <div class="ui-field ui-library-pick__sort">
                <label class="ui-sr-only" for="ui-quiz-pick-type">{{ __('lf.LF_course_template_activity_quiz_picker_all_types') }}</label>
                <select id="ui-quiz-pick-type" data-ui-kit-select x-on:change="type = $event.target.value; load()"
                        data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_template_activity_quiz_picker_all_types') }}">
                    <option value="" selected>{{ __('lf.LF_course_template_activity_quiz_picker_all_types') }}</option>
                    @foreach (\App\Services\Assessment\AssessmentQuizService::TYPES as $quizType)
                        <option value="{{ $quizType }}">{{ __('lf.LF_assessment_quiz_type_'.$quizType) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="ui-library-pick__body">
        <div class="ui-library-pick__list" x-show="loading" aria-hidden="true">
            @for ($skeleton = 0; $skeleton < 3; $skeleton++)
                <div class="ui-library-pick__item ui-library-pick__item--skeleton">
                    <span class="ui-library-pick__radio"></span>
                    <span class="ui-library-pick__thumb"></span>
                    <span class="ui-library-pick__main"><span class="ui-skeleton-line"></span><span class="ui-skeleton-line ui-skeleton-line--short"></span></span>
                </div>
            @endfor
        </div>
        <p class="ui-sr-only" role="status" x-show="loading">{{ __('lf.LF_media_pick_loading') }}</p>
        <div class="ui-library-pick__empty" role="status" x-show="! loading && items.length === 0">
            <span class="ui-library-pick__empty-icon" aria-hidden="true"><x-backend-icon name="search" class="ui-icon" /></span>
            <strong>{{ __('lf.LF_course_template_activity_quiz_picker_empty') }}</strong>
            <span class="ui-caption">{{ __('lf.LF_course_template_activity_quiz_picker_empty_hint') }}</span>
        </div>
        <div class="ui-library-pick__list" role="radiogroup" aria-labelledby="ui-quiz-pick-title" x-show="! loading">
            <template x-for="item in items" x-bind:key="item.id">
                <div class="ui-library-pick__item" role="radio" tabindex="0" data-quiz-option
                     x-bind:aria-checked="(pending === item.id).toString()"
                     x-on:click="pending = item.id" x-on:dblclick="pending = item.id; confirm()"
                     x-on:keydown.space.prevent="pending = item.id" x-on:keydown.enter.prevent="pending = item.id"
                     x-on:keydown.arrow-down.prevent="step($event, 1)" x-on:keydown.arrow-up.prevent="step($event, -1)">
                    <span class="ui-library-pick__radio" aria-hidden="true"></span>
                    <span class="ui-library-pick__thumb" aria-hidden="true"><x-backend-icon name="clipboard-check" class="ui-icon" /></span>
                    <span class="ui-library-pick__main">
                        <span class="ui-library-pick__name" x-text="item.name" x-bind:title="item.name"></span>
                        <span class="ui-library-pick__meta">
                            <span x-text="item.type"></span>
                            <span class="ui-library-pick__dot" aria-hidden="true"></span>
                            <span x-text="item.questions"></span>
                            <span class="ui-library-pick__dot" aria-hidden="true"></span>
                            <span x-text="item.duration"></span>
                        </span>
                        <span class="ui-library-pick__meta">
                            <span x-show="item.category" x-text="item.category"></span>
                            <span class="ui-library-pick__dot" aria-hidden="true" x-show="item.category"></span>
                            <span x-text="item.owner"></span>
                        </span>
                    </span>
                </div>
            </template>
        </div>
    </div>

    <footer class="ui-modal__footer ui-library-view__footer">
        <span class="ui-caption ui-library-pick__selected" role="status"
              x-text="pendingItem ? @js(__('lf.LF_course_template_activity_quiz_picker_selected', ['name' => '__NAME__'])).replace('__NAME__', pendingItem.name) : @js(__('lf.LF_course_template_activity_quiz_picker_none_selected'))"></span>
        <button type="button" class="ui-button cursor-interaction" x-on:click="back()">{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="button" class="ui-button ui-button--primary cursor-interaction" x-bind:disabled="! pendingItem" x-on:click="confirm()" data-quiz-confirm>{{ __('lf.LF_course_template_activity_quiz_picker_select') }}</button>
    </footer>
</section>
