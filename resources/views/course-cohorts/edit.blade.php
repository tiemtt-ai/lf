@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_common_edit'))
@section('page_title', __('lf.LF_course_cohort_common_edit'))

@section('content')
    @php($activeTab = request('tab') === 'students' ? 'students' : 'overview')
    @if (session('success'))
        <div class="admin-alert admin-alert-success">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <nav class="admin-form-actions" aria-label="{{ __('lf.LF_course_cohort_common_tabs') }}">
        <a @class(['btn', 'btn-primary' => $activeTab === 'overview', 'btn-secondary' => $activeTab !== 'overview'])
           href="{{ route($routePrefix.'.edit', $cohort->id) }}" @if($activeTab === 'overview') aria-current="page" @endif>
            {{ __('lf.LF_course_cohort_tab_overview') }}
        </a>
        <a @class(['btn', 'btn-primary' => $activeTab === 'students', 'btn-secondary' => $activeTab !== 'students'])
           href="{{ route($routePrefix.'.edit', ['id' => $cohort->id, 'tab' => 'students']) }}" @if($activeTab === 'students') aria-current="page" @endif>
            {{ __('lf.LF_course_cohort_tab_students') }}
        </a>
    </nav>

    <div class="admin-form-actions">
        <a href="{{ route($routePrefix.'.show', $cohort->id) }}">
            {{ __('lf.LF_course_cohort_common_back_to_detail') }}
        </a>
    </div>

    @if ($activeTab === 'overview')
    <div class="admin-card admin-form-card admin-form-surface">
        <form method="POST" action="{{ route($routePrefix.'.update', $cohort->id) }}"
              class="admin-form-standard" x-data="{ submitting: false }" x-on:submit="submitting = true">
            @csrf
            @method('PUT')

            @include('course-cohorts.partials.form', [
                'cohort' => $cohort,
                'submitLabel' => __('lf.LF_common_button_save_changes'),
            ])
        </form>
    </div>
    @else
    <div class="admin-card admin-form-card admin-form-surface cohort-student-create">
        <form class="admin-form-standard" method="POST"
              action="{{ route('admin.course-cohorts.students.sync', $cohort->id) }}"
              x-data="{
                  query: '', results: [], page: 1, lastPage: 1, loading: false, submitting: false,
                  initialIds: @js($selectedEnrollments->pluck('id')->map(fn ($id) => (string) $id)->values()),
                  selectedItems: @js($selectedEnrollments->map(fn ($enrollment) => [
                      'id' => $enrollment->id, 'name' => $enrollment->student_name, 'email' => $enrollment->student_email,
                  ])->values()),
                  isSelected(id) { return this.selectedItems.some(item => String(item.id) === String(id)) },
                  toggle(item, checked) {
                      if (checked) { if (!this.isSelected(item.id)) this.selectedItems.push({ id: item.id, name: item.name, email: item.email }) }
                      else this.remove(item.id)
                  },
                  toggleVisible(checked) { for (const item of this.results) this.toggle(item, checked) },
                  remove(id) { this.selectedItems = this.selectedItems.filter(item => String(item.id) !== String(id)) },
                  removedCount() { return this.initialIds.filter(id => !this.isSelected(id)).length },
                  async loadStudents(page) {
                      if (page < 1) return; this.loading = true;
                      try {
                          const response = await fetch(`${@js(route('admin.course-cohorts.students.search', $cohort->id))}?manage=1&q=${encodeURIComponent(this.query.trim())}&page=${page}`, { headers: { Accept: 'application/json' } });
                          const data = response.ok ? await response.json() : { data: [], pagination: { current_page: 1, last_page: 1 } };
                          this.results = data.data; this.page = data.pagination.current_page; this.lastPage = data.pagination.last_page;
                      } finally { this.loading = false }
                  },
                  confirmSubmit(event) {
                      if (this.submitting) { event.preventDefault(); return }
                      if (this.removedCount() > 0 && !confirm(@js(__('lf.LF_course_cohort_student_sync_remove_confirm')))) { event.preventDefault(); return }
                      this.submitting = true
                  },
                  init() { this.loadStudents(1) }
              }" @submit="confirmSubmit($event)">
            @csrf
            @method('PUT')
            <template x-for="item in selectedItems" :key="item.id">
                <input type="hidden" name="enrollment_ids[]" :value="item.id">
            </template>
            <div class="admin-form-flow">
                <section class="admin-form-standard-section" aria-labelledby="class-information-title">
                    <header class="admin-form-section-header"><h2 id="class-information-title" class="admin-form-section-title">{{ __('lf.LF_course_cohort_student_section_class') }}</h2></header>
                    <div class="cohort-student-class-summary">
                        <div class="cohort-student-class-heading">
                            <strong>{{ $cohort->name }}</strong>
                            <span @class(['badge', 'badge-success' => $cohort->status === 'active', 'course-cohort-status-badge--draft' => $cohort->status === 'draft'])>{{ __('lf.LF_course_cohort_common_'.$cohort->status) }}</span>
                        </div>
                        <p>{{ $cohort->code ?: '—' }} · {{ $cohort->product_title }} ({{ $cohort->product_code }}) · {{ $cohort->version_code }}</p>
                        <p>{{ __('lf.LF_course_cohort_student_capacity_summary', ['current' => $activeMembershipCount, 'capacity' => $cohort->capacity ?? __('lf.LF_course_cohort_student_capacity_unlimited')]) }}</p>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="student-selection-title">
                    <header class="admin-form-section-header">
                        <h2 id="student-selection-title" class="admin-form-section-title">{{ __('lf.LF_course_cohort_student_manage_heading') }}</h2>
                        <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_student_manage_help') }}</p>
                    </header>
                    <div class="lf-form-group admin-form-field--full">
                        <label class="lf-form-label" for="enrollment_search">{{ __('lf.LF_course_cohort_tab_students') }}</label>
                        <div class="bulk-enrollment-selector" :aria-busy="loading.toString()">
                            <div class="bulk-enrollment-transfer__panel-header"><span x-text="@js(__('lf.LF_course_cohort_student_selected_count')).replace(':count', selectedItems.length)"></span></div>
                            <div class="bulk-enrollment-search">
                                <svg class="bulk-enrollment-search__icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
                                <input id="enrollment_search" type="search" class="lf-form-control" x-model="query" x-on:input.debounce.350ms="loadStudents(1)" placeholder="{{ __('lf.LF_course_cohort_student_search_placeholder') }}">
                            </div>
                            <label class="bulk-enrollment-transfer__select-all">
                                <input type="checkbox" x-on:change="toggleVisible($event.target.checked)" :checked="results.length > 0 && results.every(item => isSelected(item.id))">
                                {{ __('lf.LF_bulk_enrollment_select_visible') }}
                            </label>
                            <div class="admin-table-wrap bulk-enrollment-selector__list"><table class="table"><tbody>
                                <template x-for="item in results" :key="item.id"><tr>
                                    <td><input type="checkbox" :checked="isSelected(item.id)" x-on:change="toggle(item, $event.target.checked)" :aria-label="item.name"></td>
                                    <td><strong x-text="item.name"></strong><span class="cohort-student-option-meta" x-text="item.email"></span></td>
                                </tr></template>
                                <tr x-show="loading" x-cloak><td colspan="2" class="cohort-student-combobox-state">{{ __('lf.LF_course_cohort_student_search_loading') }}</td></tr>
                                <tr x-show="!loading && results.length === 0" x-cloak><td colspan="2" class="cohort-student-combobox-state">{{ __('lf.LF_course_cohort_student_search_empty') }}</td></tr>
                            </tbody></table></div>
                            <nav class="bulk-enrollment-pagination" aria-label="{{ __('lf.LF_bulk_enrollment_students_pagination') }}">
                                <button type="button" class="bulk-enrollment-pagination__button" @click="loadStudents(page - 1)" :disabled="page <= 1"><span aria-hidden="true">←</span><span>{{ __('lf.LF_bulk_enrollment_previous') }}</span></button>
                                <span class="bulk-enrollment-pagination__status" x-text="`${page} / ${lastPage}`"></span>
                                <button type="button" class="bulk-enrollment-pagination__button" @click="loadStudents(page + 1)" :disabled="page >= lastPage"><span>{{ __('lf.LF_bulk_enrollment_next') }}</span><span aria-hidden="true">→</span></button>
                            </nav>
                        </div>
                        @error('enrollment_ids')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                        <h3 class="cohort-student-selected-heading">{{ __('lf.LF_course_cohort_student_selected_heading') }}</h3>
                        <p x-show="selectedItems.length === 0" class="cohort-student-combobox-state" role="status">{{ __('lf.LF_course_cohort_student_selected_empty') }}</p>
                        <ul class="cohort-student-selected-list" x-show="selectedItems.length > 0" x-cloak>
                            <template x-for="item in selectedItems" :key="item.id"><li class="cohort-student-selected-chip">
                                <span><strong x-text="item.name"></strong><span class="cohort-student-option-meta" x-text="item.email"></span></span>
                                <button type="button" class="cohort-student-chip-remove" @click="remove(item.id)" :aria-label="`{{ __('lf.LF_course_cohort_student_selected_remove') }}: ${item.name}`">×</button>
                            </li></template>
                        </ul>
                    </div>
                </section>
            </div>
            <footer class="admin-form-footer" data-actions-align="end"><div class="admin-form-footer-primary">
                <a class="admin-form-cancel" href="{{ route($routePrefix.'.show', $cohort->id) }}">{{ __('lf.LF_common_button_cancel') }}</a>
                <button type="submit" class="btn btn-primary" :disabled="submitting" :aria-busy="submitting">{{ __('lf.LF_course_cohort_student_sync_save') }}</button>
            </div></footer>
        </form>
    </div>
    @endif
@endsection
