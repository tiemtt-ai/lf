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
                  query: '', results: [], page: 1, lastPage: 1, loading: false, submitting: false,
                  selectedItems: @js($selectedEnrollments->map(fn ($enrollment) => [
                      'id' => $enrollment->id,
                      'name' => $enrollment->student_name,
                      'email' => $enrollment->student_email,
                  ])->values()),
                  isSelected(id) { return this.selectedItems.some(item => String(item.id) === String(id)) },
                  toggle(item, checked) {
                      if (checked) { if (!this.isSelected(item.id)) { this.selectedItems.push({ id: item.id, name: item.name, email: item.email }) } }
                      else { this.remove(item.id) }
                  },
                  toggleVisible(checked) {
                      for (const item of this.results) { this.toggle(item, checked) }
                  },
                  remove(id) { this.selectedItems = this.selectedItems.filter(item => String(item.id) !== String(id)) },
                  async loadStudents(page) {
                      if (page < 1) return;
                      this.loading = true;
                      try {
                          const response = await fetch(`${@js(route('admin.course-cohorts.students.search', $cohort->id))}?q=${encodeURIComponent(this.query.trim())}&page=${page}`, { headers: { Accept: 'application/json' } });
                          const data = response.ok ? await response.json() : { data: [], pagination: { current_page: 1, last_page: 1 } };
                          this.results = data.data;
                          this.page = data.pagination.current_page;
                          this.lastPage = data.pagination.last_page;
                      } finally { this.loading = false }
                  },
                  init() { this.loadStudents(1) }
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
                            <div class="bulk-enrollment-selector" :aria-busy="loading.toString()">
                                <div class="bulk-enrollment-transfer__panel-header">
                                    <span x-text="@js(__('lf.LF_course_cohort_student_selected_count')).replace(':count', selectedItems.length)"></span>
                                </div>
                                <div class="bulk-enrollment-search">
                                    <svg class="bulk-enrollment-search__icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
                                    <input id="enrollment_search" type="search" class="lf-form-control"
                                           x-model="query" x-on:input.debounce.350ms="loadStudents(1)"
                                           placeholder="{{ __('lf.LF_course_cohort_student_search_placeholder') }}">
                                </div>
                                <label class="bulk-enrollment-transfer__select-all">
                                    <input type="checkbox" x-on:change="toggleVisible($event.target.checked)"
                                           :checked="results.length > 0 && results.every(item => isSelected(item.id))">
                                    {{ __('lf.LF_bulk_enrollment_select_visible') }}
                                </label>
                                <template x-for="item in selectedItems" :key="item.id">
                                    <input type="hidden" name="enrollment_ids[]" :value="item.id">
                                </template>
                                <div class="admin-table-wrap bulk-enrollment-selector__list">
                                    <table class="table"><tbody>
                                        <template x-for="item in results" :key="item.id">
                                            <tr>
                                                <td><input type="checkbox" :checked="isSelected(item.id)" x-on:change="toggle(item, $event.target.checked)" :aria-label="item.name"></td>
                                                <td><strong x-text="item.name"></strong><span class="cohort-student-option-meta" x-text="item.email"></span></td>
                                            </tr>
                                        </template>
                                        <tr x-show="loading" x-cloak><td colspan="2" class="cohort-student-combobox-state">{{ __('lf.LF_course_cohort_student_search_loading') }}</td></tr>
                                        <tr x-show="!loading && results.length === 0" x-cloak><td colspan="2" class="cohort-student-combobox-state">{{ __('lf.LF_course_cohort_student_search_empty') }}</td></tr>
                                    </tbody></table>
                                </div>
                                <nav class="bulk-enrollment-pagination" aria-label="{{ __('lf.LF_bulk_enrollment_students_pagination') }}">
                                    <button type="button" class="bulk-enrollment-pagination__button" x-on:click="loadStudents(page - 1)" :disabled="page <= 1"><span aria-hidden="true">←</span><span>{{ __('lf.LF_bulk_enrollment_previous') }}</span></button>
                                    <span class="bulk-enrollment-pagination__status" x-text="`${page} / ${lastPage}`"></span>
                                    <button type="button" class="bulk-enrollment-pagination__button" x-on:click="loadStudents(page + 1)" :disabled="page >= lastPage"><span>{{ __('lf.LF_bulk_enrollment_next') }}</span><span aria-hidden="true">→</span></button>
                                </nav>
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
