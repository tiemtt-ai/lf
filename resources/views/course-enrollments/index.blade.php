@extends('layouts.backend')

@section('title', __('lf.LF_course_enrollment_common_title'))
@section('page_title', __('lf.LF_course_enrollment_common_title'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js'])
@endsection

@section('content')
    @php
        $editablePageIds = $enrollments->getCollection()
            ->filter(fn ($row) => in_array($row->status, ['pending', 'active', 'suspended'], true))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
        $initialSelectedIds = collect(old('enrollment_ids', []))
            ->map(fn ($id) => (string) $id)
            ->filter(fn ($id) => in_array($id, $editablePageIds, true))
            ->values()
            ->all();
        $pageEnrollmentStates = $enrollments->getCollection()
            ->mapWithKeys(fn ($row) => [(string) $row->id => [
                'status' => $row->status,
                'reactivationEligible' => $row->reactivation_eligible,
            ]])
            ->all();
        $advancedFiltersOpen = request()->query('advanced_filters') === '1';
        $hasActiveFilters = $keyword !== '' || $status || $source || $productId || $courseType || $enrolledBy || $enrolledFrom || $enrolledTo;
    @endphp
    @php
        $enrollmentStatusTone = fn (string $value): string => match ($value) {
            'active' => 'active',
            'pending' => 'draft',
            'suspended' => 'inactive',
            'expired', 'cancelled' => 'danger',
            default => 'archived',
        };
    @endphp
    <div id="enrollment-list" data-ui-dialog-form-scope class="lf-admin-ui lf-admin-ui--list"
         x-on:ui-live-filter-updated="syncPage()"
         x-data="enrollmentIndexBulk(
        @js($editablePageIds),
        @js($pageEnrollmentStates),
        @js($initialSelectedIds),
        @js($errors->any()),
        @js(in_array(old('action'), ['suspend', 'reactivate', 'cancel'], true) ? old('action') : null),
        @js([
            'suspend' => ['title' => __('lf.LF_course_enrollment_bulk_suspend_title'), 'body' => __('lf.LF_course_enrollment_bulk_suspend_body'), 'confirm' => __('lf.LF_course_enrollment_bulk_suspend_confirm')],
            'reactivate' => ['title' => __('lf.LF_course_enrollment_bulk_reactivate_title'), 'body' => __('lf.LF_course_enrollment_bulk_reactivate_body'), 'confirm' => __('lf.LF_course_enrollment_bulk_reactivate_confirm')],
            'cancel' => ['title' => __('lf.LF_course_enrollment_bulk_cancel_title'), 'body' => __('lf.LF_course_enrollment_bulk_cancel_body'), 'confirm' => __('lf.LF_course_enrollment_bulk_cancel_confirm')],
        ])
    )">
    <x-ui-list-toasts />

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <x-ui-list-head :title="__('lf.LF_course_enrollment_index_heading')" :text="__('lf.LF_course_enrollment_index_description')" />

    <section class="mk-section" aria-labelledby="admin-page-title">
        <div class="mk-tablebar mk-tablebar--filters ui-enroll-filterbar">
            <form class="mk-filter-form" method="GET" action="{{ route($routePrefix.'.index') }}" data-ui-live-filter x-data="{ advancedFiltersOpen: @js($advancedFiltersOpen) }">
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="keyword">{{ __('lf.LF_course_enrollment_common_keyword') }}</label>
                    <div class="ui-search">
                        <input id="keyword" type="search" name="keyword" value="{{ $keyword }}"
                               placeholder="{{ __('lf.LF_course_enrollment_common_keyword_placeholder') }}">
                        <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                            <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                        </button>
                    </div>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="status">{{ __('lf.LF_course_enrollment_common_status') }}</label>
                    <select id="status" name="status" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_enrollment_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_enrollment_common_clear_filters') }}">
                        <option value="">{{ __('lf.LF_course_enrollment_common_all_statuses') }}</option>
                        @foreach (['pending', 'active', 'suspended', 'completed', 'expired', 'cancelled'] as $enrollmentStatus)
                        <option value="{{ $enrollmentStatus }}" @selected($status === $enrollmentStatus)>
                            {{ __('lf.LF_course_enrollment_common_'.$enrollmentStatus) }}
                        </option>
                    @endforeach
                    </select>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="course_type">{{ __('lf.LF_course_type_label') }}</label>
                    <select id="course_type" name="course_type" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_enrollment_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_enrollment_common_clear_filters') }}">
                        <option value="">{{ __('lf.LF_course_enrollment_filter_all_course_types') }}</option>
                        @foreach (\App\Support\CourseType::VALUES as $type)
                        <option value="{{ $type }}" @selected($courseType === $type)>{{ __(\App\Support\CourseType::labelKey($type)) }}</option>
                    @endforeach
                    </select>
                </div>

                <button type="button" class="ui-button ui-button--outline cursor-interaction" x-on:click="advancedFiltersOpen = !advancedFiltersOpen"
                        :aria-expanded="advancedFiltersOpen.toString()" aria-controls="course-enrollment-advanced-filters">
                    <span x-show="!advancedFiltersOpen">{{ __('lf.LF_course_enrollment_filter_show_advanced') }}</span>
                    <span x-cloak x-show="advancedFiltersOpen">{{ __('lf.LF_course_enrollment_filter_hide_advanced') }}</span>
                </button>

                <span class="ui-live-slot" data-ui-live-region="clear-filters">
                    @if ($hasActiveFilters)
                        <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.index') }}">{{ __('lf.LF_course_enrollment_common_clear_filters') }}</a>
                    @endif
                </span>

                <input type="hidden" name="advanced_filters" :value="advancedFiltersOpen ? '1' : '0'">
                <div id="course-enrollment-advanced-filters" x-cloak x-show="advancedFiltersOpen" class="course-enrollment-advanced-filter-grid">
                    <div class="ui-field"><label class="ui-field__label" for="product_id">{{ __('lf.LF_course_enrollment_common_product') }}</label><select id="product_id" name="product_id" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_enrollment_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_enrollment_common_clear_filters') }}"><option value="">{{ __('lf.LF_course_enrollment_filter_all_products') }}</option>@foreach ($filterProducts as $product)<option value="{{ $product->id }}" @selected($productId === $product->id)>{{ $product->title }} · {{ $product->product_code }}</option>@endforeach</select></div>
                <div class="ui-field"><label class="ui-field__label" for="enrolled_by">{{ __('lf.LF_course_enrollment_filter_creator') }}</label><select id="enrolled_by" name="enrolled_by" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_enrollment_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_enrollment_common_clear_filters') }}"><option value="">{{ __('lf.LF_course_enrollment_filter_all_creators') }}</option>@foreach ($filterCreators as $creator)<option value="{{ $creator->id }}" @selected($enrolledBy === $creator->id)>{{ $creator->name }}</option>@endforeach</select></div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="source">{{ __('lf.LF_course_enrollment_common_source') }}</label>
                        <select id="source" name="source" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_enrollment_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_enrollment_common_clear_filters') }}">
                            <option value="">{{ __('lf.LF_course_enrollment_common_all_sources') }}</option>
                            @foreach (['admin', 'teacher', 'self_registration', 'purchase', 'promotion', 'import', 'api'] as $enrollmentSource)
                                <option value="{{ $enrollmentSource }}" @selected($source === $enrollmentSource)>{{ __('lf.LF_course_enrollment_common_source_'.$enrollmentSource) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="enrolled_from">{{ __('lf.LF_course_enrollment_filter_from') }}</label>
                        <input id="enrolled_from" name="enrolled_from" type="date" value="{{ $enrolledFrom }}" x-on:change="$el.form.requestSubmit()">
                    </div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="enrolled_to">{{ __('lf.LF_course_enrollment_filter_to') }}</label>
                        <input id="enrolled_to" name="enrolled_to" type="date" value="{{ $enrolledTo }}" x-on:change="$el.form.requestSubmit()">
                    </div>
                </div>
            </form>

            <div class="mk-table-top__actions">
                <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.create') }}">
                    <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                    {{ __('lf.LF_course_enrollment_common_create') }}
                </a>
                {{-- Live slot: the export follows the current filters after a live search. --}}
                <span class="ui-live-slot" data-ui-live-region="export">
                    <a class="ui-button ui-button--outline cursor-interaction" download
                       href="{{ route($routePrefix.'.export', array_filter(request()->only(['keyword', 'status', 'source', 'product_id', 'course_type', 'enrolled_by', 'enrolled_from', 'enrolled_to']), fn ($value) => is_string($value) && $value !== '')) }}">
                        <x-backend-icon name="file-output" class="ui-icon" />
                        {{ __('lf.LF_export_excel') }}
                    </a>
                </span>
            </div>
        </div>

        <div x-cloak x-show="selectedIds.length > 0" class="ui-enroll-bulkbar" aria-live="polite">
            <strong x-text="selectedLabel"></strong>
            <div class="course-enrollment-bulk-actions">
            <button type="button" class="ui-button ui-button--outline cursor-interaction course-enrollment-bulk-action--suspend" x-on:click="openLifecycle('suspend', $event.currentTarget)" :disabled="submitting || !canSuspend" :aria-describedby="!canSuspend ? 'bulk-suspend-reason' : null">{{ __('lf.LF_course_enrollment_lifecycle_suspend') }}</button>
            <button type="button" class="ui-button ui-button--outline cursor-interaction course-enrollment-bulk-action--reactivate" x-on:click="openLifecycle('reactivate', $event.currentTarget)" :disabled="submitting || !canReactivate" :aria-describedby="!canReactivate ? 'bulk-reactivate-reason' : null">{{ __('lf.LF_course_enrollment_lifecycle_reactivate') }}</button>
            <button type="button" class="ui-button ui-button--danger-outline cursor-interaction course-enrollment-bulk-action--cancel" x-on:click="openLifecycle('cancel', $event.currentTarget)" :disabled="submitting || !canCancel" :aria-describedby="!canCancel ? 'bulk-cancel-reason' : null">{{ __('lf.LF_course_enrollment_lifecycle_cancel') }}</button>
            <button type="button" class="ui-button cursor-interaction" x-on:click="clearSelection" :disabled="submitting">{{ __('lf.LF_course_enrollment_bulk_clear') }}</button>
            <span id="bulk-suspend-reason" class="sr-only">{{ __('lf.LF_course_enrollment_bulk_suspend_disabled') }}</span>
            <span id="bulk-reactivate-reason" class="sr-only">{{ __('lf.LF_course_enrollment_bulk_reactivate_disabled') }}</span>
            <span id="bulk-cancel-reason" class="sr-only">{{ __('lf.LF_course_enrollment_bulk_cancel_disabled') }}</span>
        </div>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ __('lf.LF_course_enrollment_common_title') }}" tabindex="0">
            <table class="ui-enroll-table" aria-labelledby="admin-page-title">
                <colgroup>
                    <col class="ui-col-check">
                    <col class="mk-col-sequence">
                    <col class="ui-enroll-col-student">
                    <col>
                    <col class="ui-enroll-col-source">
                    <col class="ui-enroll-col-date">
                    <col class="mk-col-status">
                    <col class="mk-col-action">
                </colgroup>
                <thead>
                <tr>
                    <th scope="col" class="ui-check-cell">
                        <input type="checkbox" class="ui-bulk-box" :checked="allPageSelected" x-on:change="togglePage($event.target.checked)" aria-label="{{ __('lf.LF_course_enrollment_select_page') }}">
                    </th>
                    <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_enrollment_common_student') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_enrollment_common_product') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_enrollment_information_source') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_enrollment_information_date') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_enrollment_common_status') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($enrollments as $enrollment)
                    @php($isOpenEnrollment = in_array($enrollment->status, ['pending', 'active', 'suspended'], true))
                    <tr>
                        <td class="ui-check-cell" data-label="{{ __('lf.LF_course_enrollment_select_page') }}">
                            @if ($isOpenEnrollment)
                                <input type="checkbox" class="ui-bulk-box" value="{{ $enrollment->id }}" x-model="selectedIds"
                                       data-enrollment-state="{{ $enrollment->status }}" data-reactivation-eligible="{{ $enrollment->reactivation_eligible ? '1' : '0' }}"
                                       aria-label="{{ __('lf.LF_course_enrollment_select_row', ['id' => $enrollment->id]) }}">
                            @else
                                <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ __('lf.LF_course_enrollment_bulk_terminal') }}" data-tooltip-align="start">
                                    <input type="checkbox" class="ui-bulk-box" disabled aria-label="{{ __('lf.LF_course_enrollment_bulk_terminal') }}">
                                </span>
                            @endif
                        </td>
                        <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $enrollments->firstItem() + $loop->index }}</td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_enrollment_common_student') }}">
                            <span class="mk-name-text">{{ $enrollment->student_name }}</span>
                            <span class="ui-caption ui-framework-description">{{ $enrollment->student_email }}</span>
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_enrollment_common_product') }}">
                            <span class="mk-name-text">{{ $enrollment->product_title }}</span>
                            <span class="ui-caption ui-framework-description">{{ $enrollment->product_code }}</span>
                            <span class="ui-caption ui-framework-description">{{ __('lf.LF_course_product_item_common_version_number', ['number' => $enrollment->version_number]) }} · {{ $enrollment->version_code }}</span>
                        </td>
                        <td class="mk-text-cell ui-enroll-nowrap" data-label="{{ __('lf.LF_course_enrollment_information_source') }}">{{ __('lf.LF_course_enrollment_common_source_'.$enrollment->source) }}</td>
                        <td class="mk-text-cell ui-enroll-nowrap" data-label="{{ __('lf.LF_course_enrollment_information_date') }}">{{ \Illuminate\Support\Carbon::parse($enrollment->enrolled_at)->format('d/m/Y H:i') }}</td>
                        <td class="ux-action-cell" data-label="{{ __('lf.LF_course_enrollment_common_status') }}">
                            <span class="ui-status ui-status--{{ $enrollmentStatusTone($enrollment->status) }}">{{ __('lf.LF_course_enrollment_common_'.$enrollment->status) }}</span>
                            @if ($enrollment->learning_ended)
                                <span class="ui-caption ui-enroll-ended">{{ __('lf.LF_course_enrollment_learning_ended') }}</span>
                            @endif
                            
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                            <a class="ux-row-edit cursor-interaction" href="{{ route($routePrefix.'.show', $enrollment->id) }}"
                               aria-label="{{ __('lf.LF_course_template_action_manage') }}: {{ $enrollment->student_name }}"
                               data-tooltip="{{ __('lf.LF_course_template_action_manage') }}">
                                <x-backend-icon name="sliders" class="ui-icon" />
                            </a>
                            @if ($isOpenEnrollment)
                                <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open aria-haspopup="dialog"
                                        data-url="{{ route($routePrefix.'.edit', $enrollment->id) }}"
                                        aria-label="{{ __('lf.LF_course_enrollment_common_edit') }}: {{ $enrollment->student_name }}"
                                        data-tooltip="{{ __('lf.LF_course_enrollment_common_edit') }}">
                                    <x-backend-icon name="square-pen" class="ui-icon" />
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr class="mk-empty-row">
                        <td class="mk-empty" colspan="8">
                            <div role="status">
                                <strong>{{ $hasActiveFilters ? __('lf.LF_course_enrollment_filter_empty') : __('lf.LF_course_enrollment_common_empty') }}</strong>
                                <p class="ui-caption">{{ $hasActiveFilters ? __('lf.LF_course_enrollment_filter_empty_help') : __('lf.LF_course_enrollment_empty_help') }}</p>
                                @unless ($hasActiveFilters)
                                    <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.create') }}">{{ __('lf.LF_course_enrollment_common_create') }}</a>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <x-ui-list-foot :paginator="$enrollments"
            :label="__('lf.LF_course_enrollment_common_title')"
            :page-size-label="__('lf.LF_course_template_index_page_size')"
            :page-size-option="__('lf.LF_course_template_index_page_size_option', ['count' => $enrollments->perPage()])" />
    </section>

    <div x-cloak x-show="lifecycleModalOpen" class="admin-modal-backdrop" x-on:keydown.escape.window="if (!submitting) closeLifecycleModal()" x-on:click.self="if (!submitting) closeLifecycleModal()">
        <section class="admin-modal course-enrollment-lifecycle-modal" role="dialog" aria-modal="true" aria-labelledby="bulk-lifecycle-title" aria-describedby="bulk-lifecycle-body" :aria-busy="submitting">
            <header class="admin-modal-header"><h2 id="bulk-lifecycle-title" x-text="lifecycleTitle"></h2><button x-ref="lifecycleCancel" type="button" class="course-enrollment-lifecycle-modal__close" x-on:click="closeLifecycleModal" :disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}"><span aria-hidden="true">×</span></button></header>
            <form x-ref="lifecycleForm" method="POST" action="{{ route($routePrefix.'.bulk-lifecycle') }}" x-on:submit="guardLifecycleSubmit">
                @csrf
                <input type="hidden" name="action" :value="lifecycleAction">
                <template x-for="id in selectedIds" :key="`lifecycle-${id}`"><input type="hidden" name="enrollment_ids[]" :value="id"></template>
                <div class="course-enrollment-lifecycle-modal__body"><p id="bulk-lifecycle-body" x-text="lifecycleBody"></p></div>
                <footer class="admin-form-footer course-enrollment-lifecycle-modal__footer" data-actions-align="end"><div class="admin-form-footer-primary"><button type="button" class="ui-button cursor-interaction" x-on:click="closeLifecycleModal" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button><button type="submit" :class="lifecycleAction === 'cancel' ? 'ui-button ui-button--danger-outline cursor-interaction' : 'ui-button ui-button--primary cursor-interaction'" :disabled="submitting"><span x-show="!submitting" x-text="lifecycleConfirm"></span><span x-cloak x-show="submitting">{{ __('lf.LF_course_enrollment_lifecycle_processing') }}</span></button></div></footer>
                <p class="sr-only" aria-live="polite" x-text="submitting ? @js(__('lf.LF_course_enrollment_lifecycle_processing')) : ''"></p>
            </form>
        </section>
    </div>

    @include('course-enrollments.partials.edit-dialog-shell')
    </div>

    <script>
        function enrollmentIndexBulk(pageIds, pageStates, initialSelectedIds, hasValidationErrors, initialLifecycleAction, lifecycleCopy) {
            return { pageIds, pageStates, selectedIds: initialSelectedIds, lifecycleModalOpen: hasValidationErrors && Boolean(initialLifecycleAction) && initialSelectedIds.length > 0, lifecycleAction: initialLifecycleAction || '', lifecycleCopy, lifecycleTrigger: null, submitting: false,
                init() { if (this.lifecycleModalOpen) this.$nextTick(() => this.$refs.lifecycleCancel.focus()) },
                get allPageSelected() { return this.pageIds.length > 0 && this.pageIds.every(id => this.selectedIds.includes(id)) },
                get selectedLabel() { return @js(__('lf.LF_course_enrollment_bulk_selected')).replace(':count', this.selectedIds.length) },
                get selectedStates() { return this.selectedIds.map(id => this.pageStates[String(id)]).filter(Boolean) },
                get selectedStatuses() { return this.selectedStates.map(state => state.status) },
                get canSuspend() { return this.selectedStatuses.length === this.selectedIds.length && this.selectedStatuses.length > 0 && this.selectedStatuses.every(status => status === 'active') },
                get canReactivate() { return this.selectedStates.length === this.selectedIds.length && this.selectedStates.length > 0 && this.selectedStates.every(state => state.status === 'suspended' && state.reactivationEligible) },
                get canCancel() { return this.selectedStatuses.length === this.selectedIds.length && this.selectedStatuses.length > 0 && this.selectedStatuses.every(status => ['pending', 'active', 'suspended'].includes(status)) },
                get lifecycleTitle() { return (this.lifecycleCopy[this.lifecycleAction]?.title || '').replace(':count', this.selectedIds.length) },
                get lifecycleBody() { return this.lifecycleCopy[this.lifecycleAction]?.body || '' },
                get lifecycleConfirm() { return this.lifecycleCopy[this.lifecycleAction]?.confirm || '' },
                // The live filter swapped the rows: this page's states come from the new checkboxes and the selection starts over.
                syncPage() { const boxes = [...this.$root.querySelectorAll('[data-enrollment-state]')]; this.pageIds = boxes.map(box => box.value); this.pageStates = Object.fromEntries(boxes.map(box => [box.value, { status: box.dataset.enrollmentState, reactivationEligible: box.dataset.reactivationEligible === '1' }])); this.selectedIds = [] },
                togglePage(checked) { this.selectedIds = checked ? [...this.pageIds] : [] },
                clearSelection() { this.selectedIds = []; this.lifecycleModalOpen = false },
                async openLifecycle(action, trigger) { if (this.submitting || this.lifecycleModalOpen || (action === 'suspend' && !this.canSuspend) || (action === 'reactivate' && !this.canReactivate) || (action === 'cancel' && !this.canCancel)) return; this.lifecycleAction = action; this.lifecycleTrigger = trigger; this.lifecycleModalOpen = true; await this.$nextTick(); this.$refs.lifecycleCancel.focus() },
                async closeLifecycleModal() { if (this.submitting) return; this.lifecycleModalOpen = false; await this.$nextTick(); this.lifecycleTrigger?.focus(); this.lifecycleTrigger = null },
                guardLifecycleSubmit(event) { event.preventDefault(); if (this.submitting) return; const form = event.currentTarget; this.submitting = true; this.$nextTick(() => form.submit()) },
            }
        }
    </script>
@endsection
