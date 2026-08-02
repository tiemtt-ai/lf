@extends('layouts.backend')

@php
    $editing = $mode === 'edit';
    $pageTitle = $editing
        ? __('lf.LF_course_cohort_student_edit_list_title')
        : __('lf.LF_course_cohort_student_common_create');
@endphp

@section('title', $pageTitle)
@section('page_title', $pageTitle)

@section('content')
    @if ($errors->any())
        <div class="admin-alert admin-alert-danger" role="alert">
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="admin-card admin-form-card admin-form-surface cohort-student-create">
        <form class="admin-form-standard" method="POST"
              action="{{ $editing ? route('admin.course-cohorts.students.sync', $cohort->id) : route('admin.course-cohorts.students.store', $cohort->id) }}"
              x-data="{
                  query: '', selectedQuery: '', results: [], page: 1, lastPage: 1, selectedPage: 1,
                  loading: false, submitting: false, requestToken: 0,
                  selectedItems: @js($selectedEnrollments->map(fn ($enrollment) => [
                      'id' => $enrollment->id,
                      'name' => $enrollment->student_name,
                      'email' => $enrollment->student_email,
                      'code' => 'ENR-'.str_pad((string) $enrollment->id, 6, '0', STR_PAD_LEFT),
                      'status' => $enrollment->status,
                      'status_label' => __('lf.LF_course_enrollment_common_'.$enrollment->status),
                      'current' => (bool) ($enrollment->current_membership ?? false),
                      'eligible' => $enrollment->status === 'active' && ($enrollment->student_status ?? 'active') === 'active',
                  ])->values()),
                  isSelected(id) { return this.selectedItems.some(item => String(item.id) === String(id)) },
                  toggle(item, checked) {
                      if (checked && item.eligible) {
                          if (!this.isSelected(item.id)) this.selectedItems.push({ ...item })
                      } else if (!checked) this.remove(item.id)
                  },
                  toggleVisible(checked) {
                      for (const item of this.results) {
                          if (item.eligible || this.isSelected(item.id)) this.toggle(item, checked)
                      }
                  },
                  remove(id) {
                      this.selectedItems = this.selectedItems.filter(item => String(item.id) !== String(id))
                      this.selectedPage = Math.min(this.selectedPage, this.selectedLastPage())
                  },
                  filteredSelected() {
                      const keyword = this.selectedQuery.trim().toLocaleLowerCase()
                      if (!keyword) return this.selectedItems
                      return this.selectedItems.filter(item => `${item.name} ${item.email} ${item.code}`.toLocaleLowerCase().includes(keyword))
                  },
                  selectedLastPage() { return Math.max(1, Math.ceil(this.filteredSelected().length / 10)) },
                  paginatedSelected() {
                      const start = (this.selectedPage - 1) * 10
                      return this.filteredSelected().slice(start, start + 10)
                  },
                  changeSelectedPage(page) {
                      if (page >= 1 && page <= this.selectedLastPage()) this.selectedPage = page
                  },
                  async loadStudents(page) {
                      if (page < 1 || this.loading && page === this.page) return
                      const token = ++this.requestToken
                      this.loading = true
                      try {
                          const manage = @js($editing) ? '&manage=1' : ''
                          const response = await fetch(`${@js(route('admin.course-cohorts.students.search', $cohort->id))}?q=${encodeURIComponent(this.query.trim())}&page=${page}${manage}`, { headers: { Accept: 'application/json' } })
                          if (token !== this.requestToken) return
                          const payload = response.ok ? await response.json() : { data: [], pagination: { current_page: 1, last_page: 1 } }
                          this.results = payload.data
                          this.page = payload.pagination.current_page
                          this.lastPage = payload.pagination.last_page
                      } finally {
                          if (token === this.requestToken) this.loading = false
                      }
                  },
                  submit(event) {
                      if (this.submitting || (!@js($editing) && this.selectedItems.length === 0)) {
                          event.preventDefault(); return
                      }
                      this.submitting = true
                  },
                  init() { this.loadStudents(1) }
              }"
              @submit="submit($event)">
            @csrf
            @if ($editing) @method('PUT') @endif

            <template x-for="item in selectedItems" :key="item.id">
                <input type="hidden" name="enrollment_ids[]" :value="item.id">
            </template>

            <section class="admin-form-standard-section" aria-labelledby="class-information-title">
                <div class="cohort-student-class-summary">
                    <div class="cohort-student-class-heading">
                        <div><span class="cohort-student-class-eyebrow">{{ __('lf.LF_course_cohort_student_section_class') }}</span><strong>{{ $cohort->name }}</strong></div>
                        <span @class(['badge', 'badge-success' => $cohort->status === 'active', 'course-cohort-status-badge--draft' => $cohort->status === 'draft'])>{{ __('lf.LF_course_cohort_common_'.$cohort->status) }}</span>
                    </div>
                    <div class="cohort-student-class-meta">
                        <span>{{ $cohort->code ?: '—' }}</span><span>{{ $cohort->product_title }} · {{ $cohort->version_code }}</span>
                        <strong>{{ __('lf.LF_course_cohort_student_capacity_summary', ['current' => $cohort->active_membership_count, 'capacity' => $cohort->capacity ?? __('lf.LF_course_cohort_student_capacity_unlimited')]) }}</strong>
                    </div>
                </div>
            </section>

            <section class="admin-form-standard-section" aria-labelledby="student-selection-title">
                <header class="cohort-student-transfer-intro">
                    <h2 id="student-selection-title" class="sr-only">{{ $pageTitle }}</h2>
                    <p>{{ __('lf.LF_course_cohort_student_manage_help') }}</p>
                </header>

                <div class="bulk-enrollment-transfer" :aria-busy="loading.toString()">
                    <section class="bulk-enrollment-transfer__panel bulk-enrollment-transfer__available" aria-labelledby="eligible-students-title">
                        <div class="bulk-enrollment-transfer__panel-header"><h3 id="eligible-students-title">{{ __('lf.LF_course_cohort_student_eligible_heading') }}</h3></div>
                        <div class="bulk-enrollment-search">
                            <label class="sr-only" for="eligible_student_search">{{ __('lf.LF_course_cohort_student_search_placeholder') }}</label>
                            <input id="eligible_student_search" type="search" class="lf-form-control" x-model="query"
                                   x-on:input.debounce.350ms="loadStudents(1)" placeholder="{{ __('lf.LF_course_cohort_student_search_placeholder') }}">
                        </div>
                        <label class="bulk-enrollment-transfer__select-all">
                            <input type="checkbox" x-on:change="toggleVisible($event.target.checked)"
                                   :checked="results.length > 0 && results.filter(item => item.eligible || isSelected(item.id)).every(item => isSelected(item.id))">
                            {{ __('lf.LF_bulk_enrollment_select_visible') }}
                        </label>
                        <div class="admin-table-wrap bulk-enrollment-selector__list"><table class="table"><tbody>
                            <template x-for="item in results" :key="item.id"><tr>
                                <td><input type="checkbox" :checked="isSelected(item.id)" :disabled="!item.eligible && !isSelected(item.id)"
                                           x-on:change="toggle(item, $event.target.checked)" :aria-label="`${item.name} · ${item.code}`"></td>
                                <td><strong x-text="item.name"></strong><span class="cohort-student-option-meta"><span x-text="item.email"></span> · <span x-text="item.code"></span> · <span x-text="item.status_label"></span></span><span class="lf-form-error" x-show="item.current && !item.eligible">{{ __('lf.LF_course_cohort_student_inactive_warning') }}</span></td>
                            </tr></template>
                            <tr x-show="loading" x-cloak><td colspan="2" role="status">{{ __('lf.LF_course_cohort_student_search_loading') }}</td></tr>
                            <tr x-show="!loading && results.length === 0" x-cloak><td colspan="2" role="status" x-text="query.trim() ? @js(__('lf.LF_course_cohort_student_search_empty')) : @js(__('lf.LF_course_cohort_student_search_no_eligible'))"></td></tr>
                        </tbody></table></div>
                        @if ($eligibleEnrollmentCount === 0)
                            <div class="admin-form-empty-state cohort-student-empty-state" role="status">
                                <p>{{ __('lf.LF_course_cohort_student_search_no_eligible') }}</p>
                                @unless ($editing)
                                    <a class="admin-text-action" href="{{ route('admin.course-enrollments.create') }}">{{ __('lf.LF_course_cohort_student_create_enrollment_action') }}</a>
                                @endunless
                            </div>
                        @endif
                        <nav class="bulk-enrollment-pagination" aria-label="{{ __('lf.LF_bulk_enrollment_students_pagination') }}">
                            <button type="button" class="bulk-enrollment-pagination__button" @click="loadStudents(page - 1)" :disabled="page <= 1">← {{ __('lf.LF_bulk_enrollment_previous') }}</button>
                            <span class="bulk-enrollment-pagination__status" x-text="`${page} / ${lastPage}`"></span>
                            <button type="button" class="bulk-enrollment-pagination__button" @click="loadStudents(page + 1)" :disabled="page >= lastPage">{{ __('lf.LF_bulk_enrollment_next') }} →</button>
                        </nav>
                    </section>

                    <section class="bulk-enrollment-transfer__panel bulk-enrollment-transfer__selected" aria-labelledby="selected-students-title">
                        <div class="bulk-enrollment-transfer__panel-header"><h3 id="selected-students-title" x-text="@js(__('lf.LF_course_cohort_student_selected_heading_count')).replace(':count', selectedItems.length)"></h3></div>
                        <div class="bulk-enrollment-search">
                            <label class="sr-only" for="selected_student_search">{{ __('lf.LF_course_cohort_student_selected_search') }}</label>
                            <input id="selected_student_search" type="search" class="lf-form-control" x-model="selectedQuery" @input="selectedPage = 1" placeholder="{{ __('lf.LF_course_cohort_student_selected_search') }}">
                        </div>
                        <p x-show="selectedItems.length === 0" class="cohort-student-combobox-state" role="status">{{ __('lf.LF_course_cohort_student_selected_empty') }}</p>
                        <p x-show="selectedItems.length > 0 && filteredSelected().length === 0" class="cohort-student-combobox-state" role="status">{{ __('lf.LF_course_cohort_student_search_empty') }}</p>
                        <ul class="cohort-student-selected-list" x-show="filteredSelected().length > 0" x-cloak>
                            <template x-for="item in paginatedSelected()" :key="item.id"><li class="cohort-student-selected-chip">
                                <span><strong x-text="item.name"></strong><span class="cohort-student-option-meta"><span x-text="item.email"></span> · <span x-text="item.code"></span></span><span class="lf-form-error" x-show="item.current && !item.eligible">{{ __('lf.LF_course_cohort_student_inactive_warning') }}</span></span>
                                <button type="button" class="cohort-student-chip-remove" @click="remove(item.id)" :aria-label="`${@js(__('lf.LF_course_cohort_student_selected_remove'))} ${item.name}`">×</button>
                            </li></template>
                        </ul>
                        <nav class="bulk-enrollment-pagination" x-show="filteredSelected().length > 0" aria-label="{{ __('lf.LF_bulk_enrollment_students_pagination') }}">
                            <button type="button" class="bulk-enrollment-pagination__button" @click="changeSelectedPage(selectedPage - 1)" :disabled="selectedPage <= 1">← {{ __('lf.LF_bulk_enrollment_previous') }}</button>
                            <span class="bulk-enrollment-pagination__status" x-text="`${selectedPage} / ${selectedLastPage()}`"></span>
                            <button type="button" class="bulk-enrollment-pagination__button" @click="changeSelectedPage(selectedPage + 1)" :disabled="selectedPage >= selectedLastPage()">{{ __('lf.LF_bulk_enrollment_next') }} →</button>
                        </nav>
                    </section>
                </div>
                @error('enrollment_ids')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
            </section>

            <footer class="admin-form-footer" data-actions-align="end"><div class="admin-form-footer-primary">
                <a class="admin-form-cancel" href="{{ route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'students']) }}">{{ __('lf.LF_common_button_cancel') }}</a>
                <button type="submit" class="btn btn-primary" :disabled="submitting || (!@js($editing) && selectedItems.length === 0)" :aria-busy="submitting">
                    <span x-show="!submitting" x-text="@js($editing ? __('lf.LF_course_cohort_student_sync_save') : __('lf.LF_course_cohort_student_add_count')).replace(':count', selectedItems.length)"></span>
                    <span x-show="submitting" x-cloak>{{ __('lf.LF_course_cohort_student_saving') }}</span>
                </button>
            </div></footer>
        </form>
    </div>
@endsection
