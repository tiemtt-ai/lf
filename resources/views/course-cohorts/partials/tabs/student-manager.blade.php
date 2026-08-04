<form method="POST"
      action="{{ route('admin.course-cohorts.students.sync', $cohort->id) }}"
      class="course-cohort-student-inline-form"
      x-show="formOpen"
      x-cloak
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
              'current' => true,
              'eligible' => $enrollment->status === 'active' && $enrollment->student_status === 'active',
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
              if (page < 1 || (this.loading && page === this.page)) return
              const token = ++this.requestToken
              this.loading = true
              try {
                  const response = await fetch(`${@js(route('admin.course-cohorts.students.search', $cohort->id))}?q=${encodeURIComponent(this.query.trim())}&page=${page}&manage=1`, { headers: { Accept: 'application/json' } })
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
              if (this.submitting) { event.preventDefault(); return }
              this.submitting = true
          },
          init() { this.loadStudents(1) }
      }"
      x-on:submit="submit($event)">
    @csrf
    @method('PUT')

    <template x-for="item in selectedItems" :key="item.id">
        <input type="hidden" name="enrollment_ids[]" :value="item.id">
    </template>

    <header class="cohort-student-transfer-intro">
        <p>{{ __('lf.LF_course_cohort_student_manage_help') }}</p>
    </header>

    <div class="bulk-enrollment-transfer" :aria-busy="loading.toString()">
        <section class="bulk-enrollment-transfer__panel bulk-enrollment-transfer__available" aria-labelledby="inline-eligible-students-title">
            <div class="bulk-enrollment-transfer__panel-header">
                <h3 id="inline-eligible-students-title">{{ __('lf.LF_course_cohort_student_eligible_heading') }}</h3>
            </div>
            <div class="bulk-enrollment-search">
                <label class="sr-only" for="inline_eligible_student_search">{{ __('lf.LF_course_cohort_student_search_placeholder') }}</label>
                <input id="inline_eligible_student_search" type="search" class="lf-form-control" x-model="query"
                       x-on:input.debounce.350ms="loadStudents(1)" placeholder="{{ __('lf.LF_course_cohort_student_search_placeholder') }}">
            </div>
            <label class="bulk-enrollment-transfer__select-all" x-show="results.length > 0" x-cloak>
                <input type="checkbox" x-on:change="toggleVisible($event.target.checked)"
                       :checked="results.length > 0 && results.filter(item => item.eligible || isSelected(item.id)).every(item => isSelected(item.id))">
                {{ __('lf.LF_bulk_enrollment_select_visible') }}
            </label>
            <div class="admin-table-wrap bulk-enrollment-selector__list">
                <table class="table"><tbody>
                    <template x-for="item in results" :key="item.id"><tr>
                        <td><input type="checkbox" :checked="isSelected(item.id)" :disabled="!item.eligible && !isSelected(item.id)"
                                   x-on:change="toggle(item, $event.target.checked)" :aria-label="`${item.name} · ${item.code}`"></td>
                        <td><strong x-text="item.name"></strong><span class="cohort-student-option-meta"><span x-text="item.email"></span> · <span x-text="item.code"></span> · <span x-text="item.status_label"></span></span><span class="lf-form-error" x-show="item.current && !item.eligible">{{ __('lf.LF_course_cohort_student_inactive_warning') }}</span></td>
                    </tr></template>
                    <tr x-show="loading" x-cloak><td colspan="2" role="status">{{ __('lf.LF_course_cohort_student_search_loading') }}</td></tr>
                    <tr x-show="!loading && results.length === 0" x-cloak><td colspan="2" role="status">{{ __('lf.LF_course_cohort_student_search_empty') }}</td></tr>
                </tbody></table>
            </div>
            <nav x-show="lastPage > 1" class="bulk-enrollment-pagination" aria-label="{{ __('lf.LF_bulk_enrollment_students_pagination') }}" x-cloak>
                <button type="button" class="bulk-enrollment-pagination__button" x-on:click="loadStudents(page - 1)" :disabled="page <= 1">← {{ __('lf.LF_bulk_enrollment_previous') }}</button>
                <span class="bulk-enrollment-pagination__status" x-text="`${page} / ${lastPage}`"></span>
                <button type="button" class="bulk-enrollment-pagination__button" x-on:click="loadStudents(page + 1)" :disabled="page >= lastPage">{{ __('lf.LF_bulk_enrollment_next') }} →</button>
            </nav>
        </section>

        <section class="bulk-enrollment-transfer__panel bulk-enrollment-transfer__selected" aria-labelledby="inline-selected-students-title">
            <div class="bulk-enrollment-transfer__panel-header">
                <h3 id="inline-selected-students-title" x-text="@js(__('lf.LF_course_cohort_student_selected_heading_count')).replace(':count', selectedItems.length)"></h3>
            </div>
            <div class="bulk-enrollment-search" x-bind:class="{ 'bulk-enrollment-search--placeholder': selectedItems.length === 0 }">
                <label class="sr-only" for="inline_selected_student_search">{{ __('lf.LF_course_cohort_student_selected_search') }}</label>
                <input id="inline_selected_student_search" type="search" class="lf-form-control" x-model="selectedQuery" x-bind:disabled="selectedItems.length === 0"
                       x-on:input="selectedPage = 1" placeholder="{{ __('lf.LF_course_cohort_student_selected_search') }}">
            </div>
            <p class="bulk-enrollment-transfer__selected-label" x-show="selectedItems.length > 0" x-cloak>{{ __('lf.LF_course_cohort_student_selected_after_save') }}</p>
            <div x-show="selectedItems.length === 0" class="cohort-student-empty-state cohort-student-empty-state--selected" role="status">
                <strong>{{ __('lf.LF_course_cohort_student_selected_empty') }}</strong>
                <p>{{ __('lf.LF_course_cohort_student_selected_empty_help') }}</p>
            </div>
            <p x-show="selectedItems.length > 0 && filteredSelected().length === 0" class="cohort-student-combobox-state" role="status">{{ __('lf.LF_course_cohort_student_search_empty') }}</p>
            <ul class="cohort-student-selected-list" x-show="filteredSelected().length > 0" x-cloak>
                <template x-for="item in paginatedSelected()" :key="item.id"><li class="cohort-student-selected-chip">
                    <span><strong x-text="item.name"></strong><span class="cohort-student-option-meta"><span x-text="item.email"></span> · <span x-text="item.code"></span></span><span class="lf-form-error" x-show="item.current && !item.eligible">{{ __('lf.LF_course_cohort_student_inactive_warning') }}</span></span>
                    <button type="button" class="cohort-student-chip-remove" x-on:click="remove(item.id)" :aria-label="`${@js(__('lf.LF_course_cohort_student_selected_remove'))} ${item.name}`">×</button>
                </li></template>
            </ul>
            <nav x-show="selectedLastPage() > 1" class="bulk-enrollment-pagination" aria-label="{{ __('lf.LF_bulk_enrollment_students_pagination') }}" x-cloak>
                <button type="button" class="bulk-enrollment-pagination__button" x-on:click="changeSelectedPage(selectedPage - 1)" :disabled="selectedPage <= 1">← {{ __('lf.LF_bulk_enrollment_previous') }}</button>
                <span class="bulk-enrollment-pagination__status" x-text="`${selectedPage} / ${selectedLastPage()}`"></span>
                <button type="button" class="bulk-enrollment-pagination__button" x-on:click="changeSelectedPage(selectedPage + 1)" :disabled="selectedPage >= selectedLastPage()">{{ __('lf.LF_bulk_enrollment_next') }} →</button>
            </nav>
        </section>
    </div>
    @error('enrollment_ids')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror

    <footer class="admin-form-footer admin-form-footer--sticky course-cohort-student-inline-form__footer">
        <strong class="course-cohort-session-form__footer-context">{{ __('lf.LF_course_cohort_student_manage_heading') }}</strong>
        <div class="admin-form-footer-primary">
            <button type="button" class="btn btn-secondary" x-on:click="formOpen = false">{{ __('lf.LF_common_button_cancel') }}</button>
            <button type="submit" class="btn btn-primary" :disabled="submitting" :aria-busy="submitting">
                <span x-show="!submitting">{{ __('lf.LF_course_cohort_student_sync_save') }}</span>
                <span x-show="submitting" x-cloak>{{ __('lf.LF_course_cohort_student_saving') }}</span>
            </button>
        </div>
    </footer>
</form>
