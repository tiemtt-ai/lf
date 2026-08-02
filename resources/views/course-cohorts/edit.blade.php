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

    <div x-data="{ lockedReason: '' }">
        <nav class="admin-form-actions cohort-student-tabs" aria-label="{{ __('lf.LF_course_cohort_common_tabs') }}">
            @foreach ($cohortTabs as $tab)
                <span class="sr-only">{{ $tab['note'] }}</span>
                @php($editRoute = $tab['key'] === 'overview'
                    ? route($routePrefix.'.edit', $cohort->id)
                    : ($tab['key'] === 'students'
                        ? route('admin.course-cohorts.students.edit', $cohort->id)
                        : $tab['route']))
                @if ($tab['accessible'])
                    <a @class(['btn', 'btn-primary' => $activeTab === $tab['key'], 'btn-secondary' => $activeTab !== $tab['key']])
                       href="{{ $editRoute }}" @if($activeTab === $tab['key']) aria-current="page" @endif>
                        {{ $tab['label'] }} @if($tab['read_only']) · {{ __('lf.LF_course_cohort_tab_read_only') }} @endif
                    </a>
                @else
                    <button type="button" class="btn btn-secondary" aria-disabled="true"
                            x-on:click="lockedReason = @js($tab['locked_reason'])"
                            x-on:focus="lockedReason = @js($tab['locked_reason'])">
                        <span aria-hidden="true">🔒</span> {{ $tab['label'] }}
                    </button>
                    <span class="sr-only">{{ $tab['locked_reason'] }}</span>
                @endif
            @endforeach
        </nav>
        <p class="admin-form-section-help" role="status" aria-live="polite"
           x-text="lockedReason || @js(collect($cohortTabs)->firstWhere('key', $activeTab)['note'])"></p>
    </div>

    <div class="cohort-student-edit-back">
        <a class="cohort-detail-back" href="{{ route($routePrefix.'.show', $cohort->id) }}">
            <span aria-hidden="true">←</span>
            {{ __('lf.LF_course_cohort_common_back_to_detail') }}
        </a>
    </div>

    @if ($activeTab === 'overview')
    <div class="admin-card admin-form-card admin-form-surface course-cohort-edit-overview">
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
                  detail: null, detailTrigger: null,
                  initialIds: @js($selectedEnrollments->pluck('id')->map(fn ($id) => (string) $id)->values()),
                  selectedItems: @js($selectedEnrollments->map(fn ($enrollment) => [
                      'id' => $enrollment->id,
                      'name' => $enrollment->student_name,
                      'email' => $enrollment->student_email,
                      'code' => 'ENR-'.str_pad((string) $enrollment->id, 6, '0', STR_PAD_LEFT),
                      'status' => $enrollment->status,
                      'status_label' => __('lf.LF_course_enrollment_common_'.$enrollment->status),
                      'source_label' => __('lf.LF_course_enrollment_common_source_'.$enrollment->source),
                      'enrolled_at' => $enrollment->enrolled_at ? \Illuminate\Support\Carbon::parse($enrollment->enrolled_at)->format('d/m/Y H:i') : '—',
                      'access_starts_at' => $enrollment->access_starts_at ? \Illuminate\Support\Carbon::parse($enrollment->access_starts_at)->format('d/m/Y H:i') : '—',
                      'access_ends_at' => $enrollment->access_ends_at ? \Illuminate\Support\Carbon::parse($enrollment->access_ends_at)->format('d/m/Y H:i') : '—',
                      'review_starts_at' => $enrollment->review_starts_at ? \Illuminate\Support\Carbon::parse($enrollment->review_starts_at)->format('d/m/Y H:i') : '—',
                      'review_ends_at' => $enrollment->review_ends_at ? \Illuminate\Support\Carbon::parse($enrollment->review_ends_at)->format('d/m/Y H:i') : '—',
                      'detail_url' => route('admin.course-enrollments.show', $enrollment->id),
                      'current' => true,
                  ])->values()),
                  isSelected(id) { return this.selectedItems.some(item => String(item.id) === String(id)) },
                  toggle(item, checked) {
                      if (checked) { if (!this.isSelected(item.id)) this.selectedItems.push({ ...item }) }
                      else this.remove(item.id)
                  },
                  toggleVisible(checked) { for (const item of this.results) this.toggle(item, checked) },
                  remove(id) { this.selectedItems = this.selectedItems.filter(item => String(item.id) !== String(id)) },
                  removedCount() { return this.initialIds.filter(id => !this.isSelected(id)).length },
                  openDetail(item, event) {
                      this.detail = item; this.detailTrigger = event.currentTarget;
                      this.$nextTick(() => this.$refs.detailClose.focus())
                  },
                  closeDetail() {
                      this.detail = null;
                      this.$nextTick(() => this.detailTrigger?.focus())
                  },
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
                      if (this.removedCount() > 0 && !confirm(@js(__('lf.LF_course_cohort_student_sync_remove_confirm')).replace(':count', this.removedCount()))) { event.preventDefault(); return }
                      this.submitting = true
                  },
                  init() { this.loadStudents(1) }
              }" @submit="confirmSubmit($event)" x-on:keydown.escape.window="if (detail) closeDetail()">
            @csrf
            @method('PUT')
            <template x-for="item in selectedItems" :key="item.id">
                <input type="hidden" name="enrollment_ids[]" :value="item.id">
            </template>
            <div class="admin-form-flow">
                <section class="admin-form-standard-section" aria-labelledby="class-information-title">
                    <div class="cohort-student-class-summary">
                        <div class="cohort-student-class-heading">
                            <div>
                                <span class="cohort-student-class-eyebrow">{{ __('lf.LF_course_cohort_student_section_class') }}</span>
                                <strong>{{ $cohort->name }}</strong>
                            </div>
                            <span @class(['badge', 'badge-success' => $cohort->status === 'active', 'course-cohort-status-badge--draft' => $cohort->status === 'draft'])>{{ __('lf.LF_course_cohort_common_'.$cohort->status) }}</span>
                        </div>
                        <div class="cohort-student-class-meta">
                            <span>{{ $cohort->code ?: '—' }}</span>
                            <span>{{ $cohort->product_title }} · {{ $cohort->product_code }}</span>
                            <span>{{ $cohort->version_code }}</span>
                            <strong>{{ __('lf.LF_course_cohort_student_capacity_summary', ['current' => $activeMembershipCount, 'capacity' => $cohort->capacity ?? __('lf.LF_course_cohort_student_capacity_unlimited')]) }}</strong>
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="student-selection-title">
                    <header class="admin-form-section-header">
                        <h2 id="student-selection-title" class="admin-form-section-title">{{ __('lf.LF_course_cohort_student_manage_heading') }}</h2>
                        <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_student_manage_help') }}</p>
                    </header>
                    <div class="lf-form-group admin-form-field--full cohort-student-manage-field">
                        <label class="sr-only" for="enrollment_search">{{ __('lf.LF_course_cohort_tab_students') }}</label>
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
                                    <td>
                                        <strong x-text="item.name"></strong>
                                        <span class="cohort-student-option-meta"><span x-text="item.email"></span> · <span x-text="item.code"></span></span>
                                    </td>
                                    <td class="cohort-student-option-action">
                                        <button type="button" class="admin-text-action" x-on:click="openDetail(item, $event)">
                                            {{ __('lf.LF_course_cohort_student_view_enrollment') }}
                                        </button>
                                    </td>
                                </tr></template>
                                <tr x-show="loading" x-cloak><td colspan="3" class="cohort-student-combobox-state">{{ __('lf.LF_course_cohort_student_search_loading') }}</td></tr>
                                <tr x-show="!loading && results.length === 0" x-cloak><td colspan="3" class="cohort-student-combobox-state">{{ __('lf.LF_course_cohort_student_search_empty') }}</td></tr>
                            </tbody></table></div>
                            <nav class="bulk-enrollment-pagination" aria-label="{{ __('lf.LF_bulk_enrollment_students_pagination') }}">
                                <button type="button" class="bulk-enrollment-pagination__button" @click="loadStudents(page - 1)" :disabled="page <= 1"><span aria-hidden="true">←</span><span>{{ __('lf.LF_bulk_enrollment_previous') }}</span></button>
                                <span class="bulk-enrollment-pagination__status" x-text="`${page} / ${lastPage}`"></span>
                                <button type="button" class="bulk-enrollment-pagination__button" @click="loadStudents(page + 1)" :disabled="page >= lastPage"><span>{{ __('lf.LF_bulk_enrollment_next') }}</span><span aria-hidden="true">→</span></button>
                            </nav>
                            <div class="cohort-student-selected-panel">
                                <h3 class="cohort-student-selected-heading">{{ __('lf.LF_course_cohort_student_selected_heading') }}</h3>
                                <p x-show="selectedItems.length === 0" class="cohort-student-combobox-state" role="status">{{ __('lf.LF_course_cohort_student_selected_empty') }}</p>
                                <ul class="cohort-student-selected-list" x-show="selectedItems.length > 0" x-cloak>
                                    <template x-for="item in selectedItems" :key="item.id"><li class="cohort-student-selected-chip">
                                        <button type="button" class="cohort-student-selected-main" x-on:click="openDetail(item, $event)">
                                            <strong x-text="item.name"></strong>
                                            <span class="cohort-student-option-meta"><span x-text="item.email"></span> · <span x-text="item.code"></span></span>
                                        </button>
                                        <button type="button" class="cohort-student-chip-remove" @click="remove(item.id)" :aria-label="`{{ __('lf.LF_course_cohort_student_selected_remove') }}: ${item.name}`">×</button>
                                    </li></template>
                                </ul>
                            </div>
                        </div>
                        @error('enrollment_ids')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </section>
            </div>
            <footer class="admin-form-footer" data-actions-align="end"><div class="admin-form-footer-primary">
                <a class="admin-form-cancel" href="{{ route($routePrefix.'.show', ['id' => $cohort->id, 'tab' => 'students']) }}">{{ __('lf.LF_common_button_cancel') }}</a>
                <button type="submit" class="btn btn-primary" :disabled="submitting" :aria-busy="submitting">{{ __('lf.LF_course_cohort_student_sync_save') }}</button>
            </div></footer>

            @include('course-cohorts.partials.enrollment-quick-view', ['cohort' => $cohort])
        </form>
    </div>
    @endif
@endsection
