@extends('layouts.backend')

@section('title', __('lf.LF_course_enrollment_common_create'))
@section('page_title', __('lf.LF_course_enrollment_common_create'))

@section('content')
    @if ($errors->any())
        <div class="admin-alert admin-alert-danger" role="alert">
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="admin-card admin-form-card admin-form-surface">
        <form class="admin-form-standard" method="POST" action="{{ route($routePrefix.'.store') }}"
              x-data="{
                  student: { query: @js($selectedStudent?->name ?? ''), selected: @js((string) old('student_id', $selectedStudent?->id ?? '')), results: [], open: false, loading: false, searched: false, active: 0, timer: null },
                  product: { query: @js($selectedProduct['title'] ?? ''), selected: @js((string) old('product_id', $selectedProduct['id'] ?? '')), results: [], open: false, loading: false, searched: false, active: 0, timer: null, version: @js($selectedProduct['version'] ?? null) },
                  submitting: false,
                  search(type, url) {
                      const state = this[type]; state.selected = ''; state.active = 0; state.open = true;
                      if (type === 'product') state.version = null;
                      clearTimeout(state.timer);
                      state.timer = setTimeout(async () => {
                          state.loading = true;
                          try { const response = await fetch(`${url}?q=${encodeURIComponent(state.query.trim())}`, { headers: { Accept: 'application/json' } }); state.results = response.ok ? (await response.json()).data : []; state.searched = true }
                          finally { state.loading = false }
                      }, 250)
                  },
                  ensureResults(type, url) { if (!this[type].searched) this.search(type, url) },
                  choose(type, item) { const state = this[type]; state.selected = String(item.id); state.query = type === 'student' ? item.name : item.title; state.open = false; if (type === 'product') state.version = item.version },
                  move(type, step) { const state = this[type]; if (state.results.length) state.active = (state.active + step + state.results.length) % state.results.length },
                  chooseActive(type) { const state = this[type]; const item = state.results[state.active]; if (item) this.choose(type, item) }
              }"
              @submit="if (submitting || !student.selected || !product.selected) { $event.preventDefault(); return } submitting = true">
            @csrf

            <div class="admin-form-flow">
                <section class="admin-form-standard-section" aria-labelledby="enrollment-access-title">
                    <header class="admin-form-section-header">
                        <h2 id="enrollment-access-title" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_group_access') }}</h2>
                    </header>
                    <div class="admin-form-field-grid">
                        @foreach ([
                            ['type' => 'student', 'label' => __('lf.LF_course_enrollment_student_required'), 'placeholder' => __('lf.LF_course_enrollment_student_search_placeholder'), 'url' => route('admin.course-enrollments.students.search')],
                            ['type' => 'product', 'label' => __('lf.LF_course_enrollment_product_required'), 'placeholder' => __('lf.LF_course_enrollment_product_search_placeholder'), 'url' => route('admin.course-enrollments.products.search')],
                        ] as $combobox)
                            <div class="lf-form-group admin-form-field">
                                <label class="lf-form-label" for="{{ $combobox['type'] }}_search">{{ $combobox['label'] }}</label>
                                <div class="lf-combobox" @click.outside="{{ $combobox['type'] }}.open = false">
                                    <input id="{{ $combobox['type'] }}_search" type="search" class="lf-form-control"
                                           x-model="{{ $combobox['type'] }}.query" role="combobox" aria-autocomplete="list"
                                           :aria-expanded="{{ $combobox['type'] }}.open.toString()" aria-controls="{{ $combobox['type'] }}-options"
                                           :aria-activedescendant="{{ $combobox['type'] }}.open && {{ $combobox['type'] }}.results[{{ $combobox['type'] }}.active] ? '{{ $combobox['type'] }}-option-' + {{ $combobox['type'] }}.results[{{ $combobox['type'] }}.active].id : null"
                                           placeholder="{{ $combobox['placeholder'] }}"
                                           @focus="{{ $combobox['type'] }}.open = true; ensureResults('{{ $combobox['type'] }}', @js($combobox['url']))"
                                           @input="search('{{ $combobox['type'] }}', @js($combobox['url']))"
                                           @keydown.down.prevent="move('{{ $combobox['type'] }}', 1)" @keydown.up.prevent="move('{{ $combobox['type'] }}', -1)"
                                           @keydown.enter.prevent="chooseActive('{{ $combobox['type'] }}')" @keydown.escape="{{ $combobox['type'] }}.open = false">
                                    <input type="hidden" name="{{ $combobox['type'] }}_id" x-model="{{ $combobox['type'] }}.selected">
                                    <div id="{{ $combobox['type'] }}-options" x-show="{{ $combobox['type'] }}.open" x-cloak role="listbox" class="lf-combobox-options">
                                        <p x-show="{{ $combobox['type'] }}.loading" class="cohort-student-combobox-state" role="status">{{ __('lf.LF_course_enrollment_search_loading') }}</p>
                                        <template x-for="(item, index) in {{ $combobox['type'] }}.results" :key="item.id">
                                            <button type="button" role="option" class="lf-combobox-option"
                                                    :id="`{{ $combobox['type'] }}-option-${item.id}`" :aria-selected="String(item.id) === {{ $combobox['type'] }}.selected"
                                                    :class="{ 'is-active': index === {{ $combobox['type'] }}.active }" @mouseenter="{{ $combobox['type'] }}.active = index" @click="choose('{{ $combobox['type'] }}', item)">
                                                <strong x-text="item.name || item.title"></strong>
                                                <span class="cohort-student-option-meta" x-text="item.email || item.code"></span>
                                            </button>
                                        </template>
                                        <p x-show="!{{ $combobox['type'] }}.loading && {{ $combobox['type'] }}.searched && {{ $combobox['type'] }}.results.length === 0" class="cohort-student-combobox-state" role="status">{{ __('lf.LF_course_enrollment_search_empty') }}</p>
                                    </div>
                                </div>
                                @error($combobox['type'].'_id')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                        @endforeach

                        <div class="admin-form-field--full admin-form-calculated-summary" aria-live="polite">
                            <span class="admin-form-calculated-summary-label">{{ __('lf.LF_course_enrollment_content_version') }}</span>
                            <template x-if="product.version">
                                <div class="admin-form-calculated-summary-content">
                                    <strong class="admin-form-calculated-summary-value"><span x-text="product.version.code"></span> · <span x-text="product.version.status"></span></strong>
                                    <span class="admin-form-calculated-summary-meta"><span x-text="product.version.lesson_count"></span> {{ __('lf.LF_course_enrollment_lessons') }} · <span x-text="product.version.activity_count"></span> {{ __('lf.LF_course_enrollment_activities') }}</span>
                                </div>
                            </template>
                            <p x-show="!product.version" class="admin-form-calculated-summary-meta">{{ __('lf.LF_course_enrollment_version_empty') }}</p>
                            <p class="lf-form-help">{{ __('lf.LF_course_enrollment_version_help') }}</p>
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="enrollment-metadata-title">
                    <header class="admin-form-section-header"><h2 id="enrollment-metadata-title" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_information') }}</h2></header>
                    <dl class="enrollment-create-metadata">
                        <div><dt>{{ __('lf.LF_course_enrollment_common_source') }}</dt><dd>{{ __('lf.LF_course_enrollment_common_source_admin') }}</dd></div>
                        <div><dt>{{ __('lf.LF_course_enrollment_common_status') }}</dt><dd><span class="badge badge-success">{{ __('lf.LF_course_enrollment_common_active') }}</span></dd></div>
                        <div><dt>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</dt><dd>{{ __('lf.LF_course_enrollment_recorded_on_save') }}</dd></div>
                    </dl>
                </section>

                @foreach ([
                    ['id' => 'access-window-title', 'title' => __('lf.LF_course_enrollment_access_window'), 'start' => 'access_starts_at', 'end' => 'access_ends_at', 'help' => __('lf.LF_course_enrollment_access_help')],
                    ['id' => 'review-window-title', 'title' => __('lf.LF_course_enrollment_review_window'), 'start' => 'review_starts_at', 'end' => 'review_ends_at', 'help' => __('lf.LF_course_enrollment_review_help')],
                ] as $window)
                    <section class="admin-form-standard-section" aria-labelledby="{{ $window['id'] }}">
                        <header class="admin-form-section-header"><h2 id="{{ $window['id'] }}" class="admin-form-section-title">{{ $window['title'] }}</h2><p class="admin-form-section-help">{{ $window['help'] }}</p></header>
                        <div class="admin-form-field-grid">
                            @foreach ([$window['start'], $window['end']] as $field)
                                <div class="lf-form-group admin-form-field">
                                    <label class="lf-form-label" for="{{ $field }}">{{ __('lf.LF_course_enrollment_common_'.$field) }}</label>
                                    <input id="{{ $field }}" type="datetime-local" name="{{ $field }}" class="lf-form-control" value="{{ old($field) }}">
                                    @error($field)<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach

                <section class="admin-form-standard-section" aria-labelledby="enrollment-additional-title">
                    <header class="admin-form-section-header"><h2 id="enrollment-additional-title" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_additional_information') }}</h2></header>
                    <div class="lf-form-group admin-form-field--full">
                        <label class="lf-form-label" for="notes">{{ __('lf.LF_course_enrollment_internal_notes') }}</label>
                        <textarea id="notes" name="notes" class="lf-form-control" rows="4" placeholder="{{ __('lf.LF_course_enrollment_notes_placeholder') }}">{{ old('notes') }}</textarea>
                        <p class="lf-form-help">{{ __('lf.LF_course_enrollment_notes_help') }}</p>
                        @error('notes')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </section>
            </div>

            <footer class="admin-form-footer" data-actions-align="end">
                <div class="admin-form-footer-primary">
                    <a class="btn btn-secondary" href="{{ route($routePrefix.'.index') }}">{{ __('lf.LF_common_button_cancel') }}</a>
                    <button type="submit" class="btn btn-primary" :disabled="submitting || !student.selected || !product.selected" :aria-busy="submitting">
                        <span x-show="!submitting">{{ __('lf.LF_course_enrollment_common_create') }}</span>
                        <span x-show="submitting" x-cloak>{{ __('lf.LF_course_enrollment_create_submitting') }}</span>
                    </button>
                </div>
            </footer>
        </form>
    </div>
@endsection
