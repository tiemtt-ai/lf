@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_student_common_create'))
@section('page_title', __('lf.LF_course_cohort_student_common_create'))

@section('content')
    @if (session('success'))
        <div class="admin-alert admin-alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger" role="alert">
            <ul>
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="admin-card admin-form-card admin-form-surface cohort-student-create">
        <form class="admin-form-standard" method="POST"
              action="{{ route('admin.course-cohorts.students.store', $cohort->id) }}"
              x-data="{
                  query: '', results: [], open: false, loading: false, searched: false,
                  activeIndex: 0, timer: null, submitting: false,
                  selectedItems: @js($selectedEnrollments->map(fn ($enrollment) => [
                      'id' => $enrollment->id,
                      'name' => $enrollment->student_name,
                      'email' => $enrollment->student_email,
                  ])->values()),
                  isSelected(id) { return this.selectedItems.some(item => String(item.id) === String(id)) },
                  search() {
                      this.activeIndex = 0; this.open = true;
                      clearTimeout(this.timer);
                      if (this.query.trim().length < 2) { this.results = []; this.searched = false; return }
                      this.timer = setTimeout(async () => {
                          this.loading = true;
                          try {
                              const response = await fetch(`${@js(route('admin.course-cohorts.students.search', $cohort->id))}?q=${encodeURIComponent(this.query.trim())}`, { headers: { Accept: 'application/json' } });
                              const data = response.ok ? (await response.json()).data : [];
                              this.results = data.filter(item => !this.isSelected(item.id));
                              this.searched = true;
                          } finally { this.loading = false }
                      }, 300)
                  },
                  choose(item) {
                      if (!this.isSelected(item.id)) { this.selectedItems.push({ id: item.id, name: item.name, email: item.email }) }
                      this.query = ''; this.results = []; this.searched = false; this.open = false;
                  },
                  remove(id) { this.selectedItems = this.selectedItems.filter(item => String(item.id) !== String(id)) },
                  move(step) { if (this.results.length) this.activeIndex = (this.activeIndex + step + this.results.length) % this.results.length },
                  chooseActive() { const item = this.results[this.activeIndex]; if (item) this.choose(item) }
              }"
              @submit="if (submitting || selectedItems.length === 0) { $event.preventDefault(); return } submitting = true">
            @csrf

            <div class="admin-form-flow">
                <section class="admin-form-standard-section" aria-labelledby="class-information-title">
                    <header class="admin-form-section-header">
                        <h2 id="class-information-title" class="admin-form-section-title">{{ __('lf.LF_course_cohort_student_section_class') }}</h2>
                    </header>
                    <div class="cohort-student-class-summary">
                        <div class="cohort-student-class-heading">
                            <strong>{{ $cohort->name }}</strong>
                            <span @class(['badge', 'badge-success' => $cohort->status === 'active', 'course-cohort-status-badge--draft' => $cohort->status === 'draft'])>{{ __('lf.LF_course_cohort_common_'.$cohort->status) }}</span>
                        </div>
                        <p>{{ $cohort->code ?: '—' }} · {{ $cohort->product_title }} ({{ $cohort->product_code }}) · {{ $cohort->version_code }}</p>
                        <p>{{ __('lf.LF_course_cohort_student_capacity_summary', ['current' => $cohort->active_membership_count, 'capacity' => $cohort->capacity ?? __('lf.LF_course_cohort_student_capacity_unlimited')]) }}</p>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="student-selection-title">
                    <header class="admin-form-section-header">
                        <h2 id="student-selection-title" class="admin-form-section-title">{{ __('lf.LF_course_cohort_student_section_select') }}</h2>
                    </header>
                    <div class="lf-form-group admin-form-field--full">
                        <label class="lf-form-label" for="enrollment_search">{{ __('lf.LF_course_cohort_student_student_required') }}</label>
                        @if ($eligibleEnrollmentCount > 0)
                            <div class="lf-combobox" @click.outside="open = false">
                                <input id="enrollment_search" type="search" class="lf-form-control"
                                       x-model="query" role="combobox" aria-autocomplete="list"
                                       :aria-expanded="open.toString()" aria-controls="eligible-enrollment-options"
                                       :aria-activedescendant="open && results[activeIndex] ? `eligible-enrollment-${results[activeIndex].id}` : null"
                                       placeholder="{{ __('lf.LF_course_cohort_student_search_placeholder') }}"
                                       @focus="open = true" @input="search()"
                                       @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
                                       @keydown.enter.prevent="chooseActive()" @keydown.escape="open = false">
                                <template x-for="item in selectedItems" :key="item.id">
                                    <input type="hidden" name="enrollment_ids[]" :value="item.id">
                                </template>
                                <div id="eligible-enrollment-options" x-show="open" x-cloak role="listbox" class="lf-combobox-options">
                                    <p x-show="loading" class="cohort-student-combobox-state" role="status">{{ __('lf.LF_course_cohort_student_search_loading') }}</p>
                                    <p x-show="!loading && query.trim().length < 2" class="cohort-student-combobox-state" role="status">{{ __('lf.LF_course_cohort_student_search_prompt') }}</p>
                                    <template x-for="(item, index) in results" :key="item.id">
                                        <button type="button" role="option" class="lf-combobox-option"
                                                :id="`eligible-enrollment-${item.id}`" :aria-selected="isSelected(item.id)"
                                                :class="{ 'is-active': index === activeIndex }" @mouseenter="activeIndex = index" @click="choose(item)">
                                            <strong x-text="item.name"></strong>
                                            <span class="cohort-student-option-meta"><span x-text="item.email"></span> · <span x-text="item.code"></span></span>
                                        </button>
                                    </template>
                                    <p x-show="!loading && searched && results.length === 0" class="cohort-student-combobox-state" role="status">{{ __('lf.LF_course_cohort_student_search_empty') }}</p>
                                </div>
                            </div>
                            <p class="lf-form-help">{{ __('lf.LF_course_cohort_student_search_help') }}</p>
                            @error('enrollment_ids')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror

                            <h3 class="cohort-student-selected-heading">{{ __('lf.LF_course_cohort_student_selected_heading') }}</h3>
                            <p x-show="selectedItems.length === 0" class="cohort-student-combobox-state" role="status">{{ __('lf.LF_course_cohort_student_selected_empty') }}</p>
                            <ul class="cohort-student-selected-list" x-show="selectedItems.length > 0" x-cloak>
                                <template x-for="item in selectedItems" :key="item.id">
                                    <li class="cohort-student-selected-chip">
                                        <span>
                                            <strong x-text="item.name"></strong>
                                            <span class="cohort-student-option-meta" x-text="item.email"></span>
                                        </span>
                                        <button type="button" class="cohort-student-chip-remove" @click="remove(item.id)" :aria-label="`{{ __('lf.LF_course_cohort_student_selected_remove') }}: ${item.name}`">×</button>
                                    </li>
                                </template>
                            </ul>
                        @else
                            <div class="admin-form-empty-state cohort-student-empty-state" role="status">
                                <p>{{ __('lf.LF_course_cohort_student_search_no_eligible') }}</p>
                                <a class="admin-text-action" href="{{ route('admin.course-enrollments.create') }}">
                                    {{ __('lf.LF_course_cohort_student_create_enrollment_action') }}
                                </a>
                            </div>
                        @endif
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="additional-information-title">
                    <header class="admin-form-section-header">
                        <h2 id="additional-information-title" class="admin-form-section-title">{{ __('lf.LF_course_cohort_student_section_additional') }}</h2>
                    </header>
                    <div class="lf-form-group admin-form-field--full">
                        <label class="lf-form-label" for="note">{{ __('lf.LF_course_cohort_student_internal_note') }}</label>
                        <textarea id="note" name="note" class="lf-form-control" rows="4" placeholder="{{ __('lf.LF_course_cohort_student_note_placeholder') }}">{{ old('note') }}</textarea>
                        <p class="lf-form-help">{{ __('lf.LF_course_cohort_student_note_help') }}</p>
                        @error('note')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </section>
            </div>

            <footer class="admin-form-footer" data-actions-align="end">
                <div class="admin-form-footer-primary">
                    <a class="admin-form-cancel" href="{{ route('admin.course-cohort-students.index', ['cohort_id' => $cohort->id]) }}">{{ __('lf.LF_common_button_cancel') }}</a>
                    <button type="submit" class="btn btn-primary" :disabled="submitting || selectedItems.length === 0" :aria-busy="submitting" aria-live="polite">
                        {{ __('lf.LF_course_cohort_student_common_create') }}
                    </button>
                </div>
            </footer>
        </form>
    </div>
@endsection
