@php
    $managedItem = fn (object $enrollment, bool $current): array => [
        'id' => $enrollment->id,
        'name' => $enrollment->student_name,
        'email' => $enrollment->student_email,
        'code' => 'ENR-'.str_pad((string) $enrollment->id, 6, '0', STR_PAD_LEFT),
        'status' => $enrollment->status,
        'status_label' => __('lf.LF_course_enrollment_common_'.$enrollment->status),
        'current' => $current,
        'account_active' => $enrollment->student_status === 'active',
        'eligible' => $enrollment->status === 'active' && $enrollment->student_status === 'active',
    ];
    $rosterItems = $selectedEnrollments->map(fn ($enrollment) => $managedItem($enrollment, true))->values();
    $startingItems = $retainedEnrollmentIds === null
        ? $rosterItems
        : $rosterItems->whereIn('id', $retainedEnrollmentIds)
            ->concat($retainedNewEnrollments->map(fn ($enrollment) => $managedItem($enrollment, false)))->values();
@endphp
<dialog x-ref="manageDialog" class="ui-modal" data-columns="2" aria-labelledby="cohort-student-manager-title" x-on:close="formOpen = false">
<form id="cohort-student-manager-form" method="POST"
      action="{{ route('admin.course-cohorts.students.sync', $cohort->id) }}"
      class="ui-modal__form course-cohort-student-inline-form"
      x-data="{
          query: '', selectedQuery: '', results: [], page: 1, lastPage: 1, selectedPage: 1,
          loading: false, submitting: false, requestToken: 0,
          capacity: @js($cohort->capacity !== null ? (int) $cohort->capacity : null),
          roster: @js($rosterItems),
          selectedItems: @js($startingItems),
          isSelected(id) { return this.selectedItems.some(item => String(item.id) === String(id)) },
          selectable(item) { return item.eligible || item.current },
          isFull() { return this.capacity !== null && this.selectedItems.length >= this.capacity },
          isOver() { return this.capacity !== null && this.selectedItems.length > this.capacity },
          addedCount() { return this.selectedItems.filter(item => !this.roster.some(member => String(member.id) === String(item.id))).length },
          removedItems() { return this.roster.filter(member => !this.isSelected(member.id)) },
          removedCount() { return this.removedItems().length },
          hasChanges() { return this.addedCount() > 0 || this.removedCount() > 0 },
          canSave() { return this.hasChanges() && !this.isOver() },
          changeSummary() {
              if (!this.hasChanges()) return @js(__('lf.LF_course_cohort_student_change_none'));
              const parts = []
              if (this.addedCount() > 0) parts.push(@js(__('lf.LF_course_cohort_student_change_add')).replace(':count', this.addedCount()))
              if (this.removedCount() > 0) parts.push(@js(__('lf.LF_course_cohort_student_change_remove')).replace(':count', this.removedCount()))
              return parts.join(' · ')
          },
          selectedHeading() {
              return this.capacity === null
                  ? @js(__('lf.LF_course_cohort_student_selected_heading_count')).replace(':count', this.selectedItems.length)
                  : @js(__('lf.LF_course_cohort_student_selected_heading_capacity')).replace(':count', this.selectedItems.length).replace(':capacity', this.capacity)
          },
          tagLabel(item) { return item.status !== 'active' ? item.status_label : @js(__('lf.LF_course_cohort_student_account_inactive')) },
          tagTone(item) {
              if (item.status === 'active' || item.status === 'suspended') return 'ui-status--inactive'
              return item.status === 'pending' ? 'ui-status--draft' : (['expired', 'cancelled'].includes(item.status) ? 'ui-status--danger' : 'ui-status--archived')
          },
          toggle(item, checked) {
              if (!checked) { this.remove(item.id); return }
              if (this.selectable(item) && !this.isFull() && !this.isSelected(item.id)) this.selectedItems.push({ ...item })
          },
          visibleEligible() { return this.results.filter(item => item.eligible) },
          allVisibleSelected() { return this.visibleEligible().length > 0 && this.visibleEligible().every(item => this.isSelected(item.id)) },
          toggleVisible(event) {
              for (const item of this.visibleEligible()) this.toggle(item, event.target.checked)
              event.target.checked = this.allVisibleSelected()
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
              if (page < 1) return
              const token = ++this.requestToken
              this.loading = true
              try {
                  const response = await fetch(`${@js(route('admin.course-cohorts.students.search', $cohort->id))}?q=${encodeURIComponent(this.query.trim())}&page=${page}&manage=1`, { headers: { Accept: 'application/json' } })
                  if (token !== this.requestToken) return
                  const payload = response.ok ? await response.json() : { data: [], pagination: { current_page: 1, last_page: 1 } }
                  if (token !== this.requestToken) return
                  this.results = payload.data
                  this.page = payload.pagination.current_page
                  this.lastPage = payload.pagination.last_page
              } finally {
                  if (token === this.requestToken) this.loading = false
              }
          },
          discardChanges() {
              this.selectedItems = this.roster.map(item => ({ ...item }))
              this.query = ''
              this.selectedQuery = ''
              this.selectedPage = 1
              this.loadStudents(1)
          },
          submit(event) {
              if (this.submitting || !this.canSave()) { event.preventDefault(); return }
              // Only the confirm dialog's own button can submit while it is open, so its open state is the answer.
              if (this.removedCount() > 0 && !this.$refs.removeConfirmDialog.open) {
                  event.preventDefault()
                  this.$refs.removeConfirmDialog.showModal()
                  return
              }
              this.submitting = true
          },
          init() {
              this.loadStudents(1)
              this.$watch('formOpen', open => { if (!open) this.discardChanges() })
          }
      }"
      x-on:submit="submit($event)">
    @csrf
    @method('PUT')
    <input type="hidden" name="roster_snapshot" value="{{ $rosterSnapshot }}">

    <template x-for="item in selectedItems" :key="item.id">
        <input type="hidden" name="enrollment_ids[]" :value="item.id">
    </template>

    <header class="ui-modal__header">
        <div>
            <h3 id="cohort-student-manager-title">{{ __('lf.LF_course_cohort_student_manage_heading') }}</h3>
            <p class="ui-caption cohort-student-transfer-intro">{{ __('lf.LF_course_cohort_student_manage_help') }}</p>
        </div>
        <button type="button" class="ui-modal__close cursor-interaction" x-on:click="formOpen = false" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>

    <div class="ui-modal__body">
    @foreach (['roster_snapshot', 'enrollment_ids', 'enrollment_ids.*'] as $errorField)
        @if ($errors->has($errorField))
            <p class="ui-field__error ui-cohort-manage-error" role="alert">{{ $errors->first($errorField) }}</p>
            @break
        @endif
    @endforeach
    <div class="bulk-enrollment-transfer" :aria-busy="loading.toString()">
        <section class="bulk-enrollment-transfer__panel bulk-enrollment-transfer__available" aria-labelledby="inline-eligible-students-title">
            <div class="bulk-enrollment-transfer__panel-header">
                <h3 id="inline-eligible-students-title">{{ __('lf.LF_course_cohort_student_pick_heading') }}</h3>
            </div>
            <div class="bulk-enrollment-search">
                <label class="sr-only" for="inline_eligible_student_search">{{ __('lf.LF_course_cohort_student_search_placeholder') }}</label>
                <input id="inline_eligible_student_search" type="search" x-model="query"
                       x-on:keydown.enter.prevent x-on:input.debounce.350ms="loadStudents(1)" placeholder="{{ __('lf.LF_course_cohort_student_search_placeholder') }}">
            </div>
            <label class="bulk-enrollment-transfer__select-all" x-show="visibleEligible().length > 0" x-cloak>
                <input type="checkbox" x-on:change="toggleVisible($event)" :checked="allVisibleSelected()">
                {{ __('lf.LF_bulk_enrollment_select_visible') }}
            </label>
            <div class="bulk-enrollment-selector__list">
                <table><tbody>
                    <template x-for="item in results" :key="item.id"><tr>
                        <td class="ui-check-cell"><input type="checkbox" class="ui-bulk-box" :checked="isSelected(item.id)" :disabled="!isSelected(item.id) && (!selectable(item) || isFull())"
                                   x-on:change="toggle(item, $event.target.checked)" :aria-label="`${item.name} · ${item.code}`"></td>
                        <td class="mk-text-cell">
                            <span class="mk-name-text" x-text="item.name"></span>
                            <span class="ui-caption ui-framework-description"><span x-text="item.email"></span> · <span x-text="item.code"></span></span>
                            <span class="ui-cohort-pick-tags" x-show="!item.eligible">
                                <span class="ui-status" :class="tagTone(item)" x-text="tagLabel(item)"></span>
                                <span class="ui-caption ui-cohort-seat-note" x-show="isSelected(item.id)"><x-backend-icon name="info" class="ui-icon" />{{ __('lf.LF_course_cohort_student_holds_seat') }}</span>
                            </span>
                        </td>
                    </tr></template>
                    <tr x-show="loading" x-cloak><td class="mk-text-cell" colspan="2" role="status">{{ __('lf.LF_course_cohort_student_search_loading') }}</td></tr>
                    <tr x-show="!loading && results.length === 0" x-cloak><td class="mk-text-cell" colspan="2" role="status">{{ __('lf.LF_course_cohort_student_search_empty') }}</td></tr>
                </tbody></table>
            </div>
            <nav x-show="lastPage > 1" class="bulk-enrollment-pagination" aria-label="{{ __('lf.LF_bulk_enrollment_students_pagination') }}" x-cloak>
                <button type="button" class="ui-button ui-button--sm cursor-interaction" x-on:click="loadStudents(page - 1)" :disabled="page <= 1">← {{ __('lf.LF_bulk_enrollment_previous') }}</button>
                <span class="bulk-enrollment-pagination__status" x-text="`${page} / ${lastPage}`"></span>
                <button type="button" class="ui-button ui-button--sm cursor-interaction" x-on:click="loadStudents(page + 1)" :disabled="page >= lastPage">{{ __('lf.LF_bulk_enrollment_next') }} →</button>
            </nav>
        </section>

        <section class="bulk-enrollment-transfer__panel bulk-enrollment-transfer__selected" aria-labelledby="inline-selected-students-title">
            <div class="bulk-enrollment-transfer__panel-header">
                <h3 id="inline-selected-students-title" x-text="selectedHeading()"></h3>
                <span class="ui-caption ui-cohort-capacity-note" role="status" x-show="isFull()" x-text="isOver() ? @js(__('lf.LF_course_cohort_student_capacity_over')) : @js(__('lf.LF_course_cohort_student_capacity_full'))" x-cloak></span>
            </div>
            <div class="bulk-enrollment-search" x-bind:class="{ 'bulk-enrollment-search--placeholder': selectedItems.length === 0 }">
                <label class="sr-only" for="inline_selected_student_search">{{ __('lf.LF_course_cohort_student_selected_search') }}</label>
                <input id="inline_selected_student_search" type="search" x-model="selectedQuery" x-bind:disabled="selectedItems.length === 0"
                       x-on:keydown.enter.prevent x-on:input="selectedPage = 1" placeholder="{{ __('lf.LF_course_cohort_student_selected_search') }}">
            </div>
            <div x-show="selectedItems.length === 0" class="cohort-student-empty-state cohort-student-empty-state--selected" role="status">
                <strong>{{ __('lf.LF_course_cohort_student_selected_empty') }}</strong>
                <p>{{ __('lf.LF_course_cohort_student_selected_empty_help') }}</p>
            </div>
            <p x-show="selectedItems.length > 0 && filteredSelected().length === 0" class="cohort-student-combobox-state" role="status">{{ __('lf.LF_course_cohort_student_search_empty') }}</p>
            <ul class="cohort-student-selected-list" x-show="filteredSelected().length > 0" x-cloak>
                <template x-for="item in paginatedSelected()" :key="item.id"><li class="cohort-student-selected-chip">
                    <span class="mk-text-cell">
                        <span class="mk-name-text" x-text="item.name"></span>
                        <span class="ui-caption ui-framework-description"><span x-text="item.email"></span> · <span x-text="item.code"></span></span>
                        <span class="ui-cohort-pick-tags" x-show="!item.eligible">
                            <span class="ui-status" :class="tagTone(item)" x-text="tagLabel(item)"></span>
                            <span class="ui-caption ui-cohort-seat-note"><x-backend-icon name="info" class="ui-icon" />{{ __('lf.LF_course_cohort_student_holds_seat') }}</span>
                        </span>
                    </span>
                    <button type="button" class="cohort-student-chip-remove" x-on:click="remove(item.id)" :aria-label="`${@js(__('lf.LF_course_cohort_student_selected_remove'))} ${item.name}`">×</button>
                </li></template>
            </ul>
            <nav x-show="selectedLastPage() > 1" class="bulk-enrollment-pagination" aria-label="{{ __('lf.LF_bulk_enrollment_students_pagination') }}" x-cloak>
                <button type="button" class="ui-button ui-button--sm cursor-interaction" x-on:click="changeSelectedPage(selectedPage - 1)" :disabled="selectedPage <= 1">← {{ __('lf.LF_bulk_enrollment_previous') }}</button>
                <span class="bulk-enrollment-pagination__status" x-text="`${selectedPage} / ${selectedLastPage()}`"></span>
                <button type="button" class="ui-button ui-button--sm cursor-interaction" x-on:click="changeSelectedPage(selectedPage + 1)" :disabled="selectedPage >= selectedLastPage()">{{ __('lf.LF_bulk_enrollment_next') }} →</button>
            </nav>
        </section>
    </div>
    </div>

    <footer class="ui-modal__footer">
        <span class="ui-caption ui-cohort-manage-summary" role="status" x-text="changeSummary()"></span>
        <button type="button" class="ui-button cursor-interaction" x-on:click="formOpen = false">{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" :disabled="submitting || !canSave()" :aria-busy="submitting">
            <span x-show="!submitting">{{ __('lf.LF_course_cohort_student_sync_save') }}</span>
            <span x-show="submitting" x-cloak>{{ __('lf.LF_course_cohort_student_saving') }}</span>
        </button>
    </footer>

    <dialog x-ref="removeConfirmDialog" class="ui-modal" data-columns="1" aria-labelledby="cohort-student-remove-title" aria-describedby="cohort-student-remove-body"
            x-on:cancel="if (submitting) $event.preventDefault()">
        <div class="ui-modal__form">
            <header class="ui-modal__header">
                <div><h3 id="cohort-student-remove-title">{{ __('lf.LF_course_cohort_student_sync_remove_title') }}</h3></div>
                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.removeConfirmDialog.close()" :disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
            </header>
            <div class="ui-modal__body">
                <p id="cohort-student-remove-body" class="ui-enroll-dialog-text" x-text="@js(__('lf.LF_course_cohort_student_sync_remove_confirm')).replace(':count', removedCount())"></p>
                <ul class="ui-cohort-remove-list">
                    <template x-for="item in removedItems()" :key="item.id"><li>
                        <span class="mk-name-text" x-text="item.name"></span>
                        <span class="ui-caption ui-framework-description"><span x-text="item.email"></span> · <span x-text="item.code"></span></span>
                    </li></template>
                </ul>
            </div>
            <footer class="ui-modal__footer">
                <span></span>
                <button type="button" autofocus class="ui-button cursor-interaction" x-on:click="$refs.removeConfirmDialog.close()" :disabled="submitting">{{ __('lf.LF_common_button_back') }}</button>
                <button type="submit" class="ui-button ui-button--primary cursor-interaction" :disabled="submitting" :aria-busy="submitting">
                    <span x-show="!submitting">{{ __('lf.LF_course_cohort_student_sync_confirm_save') }}</span>
                    <span x-show="submitting" x-cloak>{{ __('lf.LF_course_cohort_student_saving') }}</span>
                </button>
            </footer>
        </div>
    </dialog>
</form>
</dialog>
