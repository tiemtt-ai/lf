@extends('layouts.backend')

@section('title', __('lf.LF_course_enrollment_common_title'))
@section('page_title', __('lf.LF_course_enrollment_common_title'))

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
        $pageEnrollmentStatuses = $enrollments->getCollection()
            ->mapWithKeys(fn ($row) => [(string) $row->id => $row->status])
            ->all();
    @endphp
    @if (session('success'))
        <div class="admin-alert admin-alert-success" role="status">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div x-data="enrollmentIndexBulk(
        @js($editablePageIds),
        @js($pageEnrollmentStatuses),
        @js($initialSelectedIds),
        @js($errors->any()),
        @js(in_array(old('action'), ['suspend', 'reactivate', 'cancel'], true) ? old('action') : null),
        @js([
            'suspend' => ['title' => __('lf.LF_course_enrollment_bulk_suspend_title'), 'body' => __('lf.LF_course_enrollment_bulk_suspend_body'), 'confirm' => __('lf.LF_course_enrollment_bulk_suspend_confirm')],
            'reactivate' => ['title' => __('lf.LF_course_enrollment_bulk_reactivate_title'), 'body' => __('lf.LF_course_enrollment_bulk_reactivate_body'), 'confirm' => __('lf.LF_course_enrollment_bulk_reactivate_confirm')],
            'cancel' => ['title' => __('lf.LF_course_enrollment_bulk_cancel_title'), 'body' => __('lf.LF_course_enrollment_bulk_cancel_body'), 'confirm' => __('lf.LF_course_enrollment_bulk_cancel_confirm')],
        ])
    )">

    <div class="admin-card admin-form-card course-enrollment-filter-card">
        <form class="course-enrollment-filter-grid" method="GET" action="{{ route($routePrefix.'.index') }}">
            <div class="lf-form-group">
                <label class="lf-form-label" for="keyword">
                    {{ __('lf.LF_course_enrollment_common_keyword') }}
                </label>
                <input id="keyword"
                       type="search"
                       name="keyword"
                       class="lf-form-control"
                       value="{{ $keyword }}"
                       placeholder="{{ __('lf.LF_course_enrollment_common_keyword_placeholder') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="status">
                    {{ __('lf.LF_course_enrollment_common_status') }}
                </label>
                <select id="status" name="status" class="lf-form-control">
                    <option value="">{{ __('lf.LF_course_enrollment_common_all_statuses') }}</option>
                    @foreach (['pending', 'active', 'suspended', 'completed', 'expired', 'cancelled'] as $enrollmentStatus)
                        <option value="{{ $enrollmentStatus }}" @selected($status === $enrollmentStatus)>
                            {{ __('lf.LF_course_enrollment_common_'.$enrollmentStatus) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="source">
                    {{ __('lf.LF_course_enrollment_common_source') }}
                </label>
                <select id="source" name="source" class="lf-form-control">
                    <option value="">{{ __('lf.LF_course_enrollment_common_all_sources') }}</option>
                    @foreach (['admin', 'teacher', 'self_registration', 'purchase', 'promotion', 'import', 'api'] as $enrollmentSource)
                        <option value="{{ $enrollmentSource }}" @selected($source === $enrollmentSource)>
                            {{ __('lf.LF_course_enrollment_common_source_'.$enrollmentSource) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="lf-form-group"><label class="lf-form-label" for="product_id">{{ __('lf.LF_course_enrollment_common_product') }}</label><select id="product_id" name="product_id" class="lf-form-control"><option value="">{{ __('lf.LF_course_enrollment_filter_all_products') }}</option>@foreach ($filterProducts as $product)<option value="{{ $product->id }}" @selected($productId === $product->id)>{{ $product->title }} · {{ $product->product_code }}</option>@endforeach</select></div>
            <div class="lf-form-group"><label class="lf-form-label" for="student_id">{{ __('lf.LF_course_enrollment_common_student') }}</label><select id="student_id" name="student_id" class="lf-form-control"><option value="">{{ __('lf.LF_course_enrollment_filter_all_students') }}</option>@foreach ($filterStudents as $student)<option value="{{ $student->id }}" @selected($studentId === $student->id)>{{ $student->name }} · {{ $student->email }}</option>@endforeach</select></div>
            <div class="lf-form-group"><label class="lf-form-label" for="enrolled_from">{{ __('lf.LF_course_enrollment_filter_from') }}</label><input id="enrolled_from" name="enrolled_from" type="date" class="lf-form-control" value="{{ $enrolledFrom }}"></div>
            <div class="lf-form-group"><label class="lf-form-label" for="enrolled_to">{{ __('lf.LF_course_enrollment_filter_to') }}</label><input id="enrolled_to" name="enrolled_to" type="date" class="lf-form-control" value="{{ $enrolledTo }}"></div>
            <div class="lf-form-group"><label class="lf-form-label" for="enrolled_by">{{ __('lf.LF_course_enrollment_filter_creator') }}</label><select id="enrolled_by" name="enrolled_by" class="lf-form-control"><option value="">{{ __('lf.LF_course_enrollment_filter_all_creators') }}</option>@foreach ($filterCreators as $creator)<option value="{{ $creator->id }}" @selected($enrolledBy === $creator->id)>{{ $creator->name }}</option>@endforeach</select></div>

            <div class="admin-form-actions course-enrollment-filter-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_common_button_search') }}
                </button>
                <a href="{{ route($routePrefix.'.index') }}">
                    {{ __('lf.LF_course_enrollment_common_clear_filters') }}
                </a>
            </div>
        </form>
    </div>

    <div class="course-cohort-index-toolbar">
        <p>{{ __('lf.LF_course_enrollment_filter_results', ['count' => $enrollments->total()]) }}</p>
        <a href="{{ route($routePrefix.'.create') }}" class="btn btn-primary">
            {{ __('lf.LF_course_enrollment_common_create') }}
        </a>
    </div>

    <div x-cloak x-show="selectedIds.length > 0" class="course-enrollment-bulk-bar" aria-live="polite">
        <strong x-text="selectedLabel"></strong>
        <div class="course-enrollment-bulk-actions">
            <button type="button" class="btn btn-primary" x-on:click="openEditModal">{{ __('lf.LF_course_enrollment_bulk_edit') }}</button>
            <button type="button" class="btn btn-secondary" x-on:click="openLifecycle('suspend', $event.currentTarget)" :disabled="!canSuspend" :aria-describedby="!canSuspend ? 'bulk-suspend-reason' : null">{{ __('lf.LF_course_enrollment_lifecycle_suspend') }}</button>
            <button type="button" class="btn btn-secondary" x-on:click="openLifecycle('reactivate', $event.currentTarget)" :disabled="!canReactivate" :aria-describedby="!canReactivate ? 'bulk-reactivate-reason' : null">{{ __('lf.LF_course_enrollment_lifecycle_reactivate') }}</button>
            <button type="button" class="btn btn-danger" x-on:click="openLifecycle('cancel', $event.currentTarget)" :disabled="!canCancel" :aria-describedby="!canCancel ? 'bulk-cancel-reason' : null">{{ __('lf.LF_course_enrollment_lifecycle_cancel') }}</button>
            <button type="button" class="btn btn-secondary" x-on:click="clearSelection">{{ __('lf.LF_course_enrollment_bulk_clear') }}</button>
            <span id="bulk-suspend-reason" class="sr-only">{{ __('lf.LF_course_enrollment_bulk_suspend_disabled') }}</span>
            <span id="bulk-reactivate-reason" class="sr-only">{{ __('lf.LF_course_enrollment_bulk_reactivate_disabled') }}</span>
            <span id="bulk-cancel-reason" class="sr-only">{{ __('lf.LF_course_enrollment_bulk_cancel_disabled') }}</span>
        </div>
    </div>

    <div class="admin-table-wrap course-cohort-index-table-wrap">
        <table class="table course-cohort-index-table course-enrollment-index-table">
            <thead>
            <tr>
                <th class="course-enrollment-select-column"><label><input type="checkbox" :checked="allPageSelected" x-on:change="togglePage($event.target.checked)"><span class="sr-only">{{ __('lf.LF_course_enrollment_select_page') }}</span></label></th>
                <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                <th>{{ __('lf.LF_course_enrollment_common_student') }}</th>
                <th>{{ __('lf.LF_course_enrollment_common_product') }}</th>
                <th>{{ __('lf.LF_course_enrollment_common_version') }}</th>
                <th>{{ __('lf.LF_course_enrollment_common_source') }}</th>
                <th>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</th>
                <th class="course-cohort-index-status">{{ __('lf.LF_course_enrollment_common_status') }}</th>
                <th class="course-cohort-index-actions">{{ __('lf.table_actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($enrollments as $enrollment)
                <tr>
                    <td class="course-enrollment-select-column">
                        @if (in_array($enrollment->status, ['pending', 'active', 'suspended'], true))
                            <label><input type="checkbox" value="{{ $enrollment->id }}" x-model="selectedIds"><span class="sr-only">{{ __('lf.LF_course_enrollment_select_row', ['id' => $enrollment->id, 'student' => $enrollment->student_name]) }}</span></label>
                        @else
                            <span title="{{ __('lf.LF_course_enrollment_bulk_terminal') }}">—</span>
                        @endif
                    </td>
                    <td class="admin-table-sequence">{{ $enrollments->firstItem() + $loop->index }}</td>
                    <td>
                        <strong class="course-cohort-index-primary">{{ $enrollment->student_name }}</strong>
                        <span class="course-cohort-index-meta">{{ $enrollment->student_email }}</span>
                    </td>
                    <td>
                        <strong class="course-cohort-index-primary">{{ $enrollment->product_title }}</strong>
                        <span class="course-cohort-index-meta">{{ $enrollment->product_code }}</span>
                    </td>
                    <td>
                        <strong class="course-cohort-index-primary">{{ $enrollment->version_title }}</strong>
                        <span class="course-cohort-index-meta">
                            {{ __('lf.LF_course_product_item_common_version_number', ['number' => $enrollment->version_number]) }}
                            · {{ $enrollment->version_code }}
                        </span>
                    </td>
                    <td>{{ __('lf.LF_course_enrollment_common_source_'.$enrollment->source) }}</td>
                    <td>{{ $enrollment->enrolled_at }}</td>
                    <td class="course-cohort-index-status">
                        <span @class([
                            'badge',
                            'badge-success' => $enrollment->status === 'active',
                            'badge-danger' => in_array($enrollment->status, ['expired', 'cancelled'], true),
                        ])>
                            {{ __('lf.LF_course_enrollment_common_'.$enrollment->status) }}
                        </span>
                    </td>
                    <td class="course-cohort-index-actions">
                        <div class="admin-table-actions course-cohort-index-action-list">
                            <a class="admin-table-action-link admin-text-action" href="{{ route($routePrefix.'.show', $enrollment->id) }}">
                                {{ __('lf.action_view') }}
                            </a>
                            @if (in_array($enrollment->status, ['pending', 'active', 'suspended'], true))<a class="admin-table-action-link admin-text-action" href="{{ route($routePrefix.'.edit', $enrollment->id) }}">{{ __('lf.action_edit') }}</a>@endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        {{ __('lf.LF_course_enrollment_common_empty') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($enrollments->hasPages())
        <div class="admin-pagination">
            {{ $enrollments->links() }}
        </div>
    @endif

    <div x-cloak x-show="editModalOpen" class="admin-modal-backdrop" x-on:keydown.escape.window="if (!submitting) closeEditModal()" x-on:click.self="if (!submitting) closeEditModal()">
        <section class="admin-modal course-enrollment-bulk-modal" role="dialog" aria-modal="true" aria-labelledby="bulk-edit-title">
            <header class="admin-modal-header"><h2 id="bulk-edit-title">{{ __('lf.LF_course_enrollment_bulk_edit') }}</h2><button type="button" class="admin-text-action" x-on:click="closeEditModal" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button></header>
            <form method="POST" action="{{ route($routePrefix.'.bulk-update') }}" x-on:submit="submitting = true">
                @csrf
                <template x-for="id in selectedIds" :key="id"><input type="hidden" name="enrollment_ids[]" :value="id"></template>
                <p x-text="selectedLabel"></p>
                <div class="admin-form-stack">
                    @foreach (['access_starts_at', 'access_ends_at', 'review_starts_at', 'review_ends_at'] as $field)
                        <div class="course-enrollment-bulk-field"><label class="lf-form-label" for="{{ $field }}_action">{{ __('lf.LF_course_enrollment_common_'.$field) }}</label><select id="{{ $field }}_action" name="{{ $field }}_action" class="lf-form-control" x-model="actions.{{ $field }}"><option value="preserve">{{ __('lf.LF_course_enrollment_bulk_preserve') }}</option><option value="set">{{ __('lf.LF_course_enrollment_bulk_set') }}</option><option value="clear">{{ __('lf.LF_course_enrollment_bulk_clear_value') }}</option></select><input x-show="actions.{{ $field }} === 'set'" type="datetime-local" name="{{ $field }}_value" class="lf-form-control" :disabled="actions.{{ $field }} !== 'set'"></div>
                    @endforeach
                    <div class="course-enrollment-bulk-field"><label class="lf-form-label" for="notes_action">{{ __('lf.LF_course_enrollment_internal_notes') }}</label><select id="notes_action" name="notes_action" class="lf-form-control" x-model="actions.notes"><option value="preserve">{{ __('lf.LF_course_enrollment_bulk_preserve') }}</option><option value="set">{{ __('lf.LF_course_enrollment_bulk_set') }}</option><option value="clear">{{ __('lf.LF_course_enrollment_bulk_clear_value') }}</option></select><textarea x-show="actions.notes === 'set'" name="notes_value" class="lf-form-control" rows="3" :disabled="actions.notes !== 'set'"></textarea></div>
                </div>
                <footer class="admin-form-footer"><div class="admin-form-footer-primary"><button type="button" class="btn btn-secondary" x-on:click="closeEditModal" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button><button type="submit" class="btn btn-primary" :disabled="submitting || selectedIds.length === 0"><span x-show="!submitting">{{ __('lf.LF_common_button_save_changes') }}</span><span x-show="submitting">{{ __('lf.LF_course_enrollment_update_saving') }}</span></button></div></footer>
            </form>
        </section>
    </div>

    <div x-cloak x-show="lifecycleModalOpen" class="admin-modal-backdrop" x-on:keydown.escape.window="if (!submitting) closeLifecycleModal()" x-on:click.self="if (!submitting) closeLifecycleModal()">
        <section class="admin-modal course-enrollment-lifecycle-modal" role="dialog" aria-modal="true" aria-labelledby="bulk-lifecycle-title" aria-describedby="bulk-lifecycle-body" :aria-busy="submitting">
            <header class="admin-modal-header"><h2 id="bulk-lifecycle-title" x-text="lifecycleTitle"></h2><button x-ref="lifecycleCancel" type="button" class="admin-text-action" x-on:click="closeLifecycleModal" :disabled="submitting">{{ __('lf.LF_common_button_close') }}</button></header>
            <form method="POST" action="{{ route($routePrefix.'.bulk-lifecycle') }}" x-on:submit="guardLifecycleSubmit">
                @csrf
                <input type="hidden" name="action" :value="lifecycleAction">
                <template x-for="id in selectedIds" :key="`lifecycle-${id}`"><input type="hidden" name="enrollment_ids[]" :value="id"></template>
                <div class="course-enrollment-lifecycle-modal__body"><p id="bulk-lifecycle-body" x-text="lifecycleBody"></p></div>
                <footer class="admin-form-footer"><div class="admin-form-footer-primary"><button type="button" class="btn btn-secondary" x-on:click="closeLifecycleModal" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button><button type="submit" :class="lifecycleAction === 'cancel' ? 'btn btn-danger' : 'btn btn-primary'" :disabled="submitting"><span x-show="!submitting" x-text="lifecycleConfirm"></span><span x-cloak x-show="submitting">{{ __('lf.LF_course_enrollment_lifecycle_processing') }}</span></button></div></footer>
                <p class="sr-only" aria-live="polite" x-text="submitting ? @js(__('lf.LF_course_enrollment_lifecycle_processing')) : ''"></p>
            </form>
        </section>
    </div>
    </div>

    <script>
        function enrollmentIndexBulk(pageIds, pageStatuses, initialSelectedIds, hasValidationErrors, initialLifecycleAction, lifecycleCopy) {
            return { pageIds, pageStatuses, selectedIds: initialSelectedIds, editModalOpen: hasValidationErrors && !initialLifecycleAction && initialSelectedIds.length > 0, lifecycleModalOpen: hasValidationErrors && Boolean(initialLifecycleAction) && initialSelectedIds.length > 0, lifecycleAction: initialLifecycleAction || '', lifecycleCopy, lifecycleTrigger: null, submitting: false,
                actions: { access_starts_at: 'preserve', access_ends_at: 'preserve', review_starts_at: 'preserve', review_ends_at: 'preserve', notes: 'preserve' },
                init() { if (this.lifecycleModalOpen) this.$nextTick(() => this.$refs.lifecycleCancel.focus()) },
                get allPageSelected() { return this.pageIds.length > 0 && this.pageIds.every(id => this.selectedIds.includes(id)) },
                get selectedLabel() { return @js(__('lf.LF_course_enrollment_bulk_selected')).replace(':count', this.selectedIds.length) },
                get selectedStatuses() { return this.selectedIds.map(id => this.pageStatuses[String(id)]).filter(Boolean) },
                get canSuspend() { return this.selectedStatuses.length === this.selectedIds.length && this.selectedStatuses.length > 0 && this.selectedStatuses.every(status => status === 'active') },
                get canReactivate() { return this.selectedStatuses.length === this.selectedIds.length && this.selectedStatuses.length > 0 && this.selectedStatuses.every(status => status === 'suspended') },
                get canCancel() { return this.selectedStatuses.length === this.selectedIds.length && this.selectedStatuses.length > 0 && this.selectedStatuses.every(status => ['pending', 'active', 'suspended'].includes(status)) },
                get lifecycleTitle() { return (this.lifecycleCopy[this.lifecycleAction]?.title || '').replace(':count', this.selectedIds.length) },
                get lifecycleBody() { return this.lifecycleCopy[this.lifecycleAction]?.body || '' },
                get lifecycleConfirm() { return this.lifecycleCopy[this.lifecycleAction]?.confirm || '' },
                togglePage(checked) { this.selectedIds = checked ? [...this.pageIds] : [] },
                clearSelection() { this.selectedIds = []; this.editModalOpen = false; this.lifecycleModalOpen = false },
                openEditModal() { this.submitting = false; this.editModalOpen = true },
                closeEditModal() { if (!this.submitting) this.editModalOpen = false },
                async openLifecycle(action, trigger) { if ((action === 'suspend' && !this.canSuspend) || (action === 'reactivate' && !this.canReactivate) || (action === 'cancel' && !this.canCancel)) return; this.lifecycleAction = action; this.lifecycleTrigger = trigger; this.submitting = false; this.lifecycleModalOpen = true; await this.$nextTick(); this.$refs.lifecycleCancel.focus() },
                async closeLifecycleModal() { if (this.submitting) return; this.lifecycleModalOpen = false; await this.$nextTick(); this.lifecycleTrigger?.focus(); this.lifecycleTrigger = null },
                guardLifecycleSubmit(event) { if (this.submitting) { event.preventDefault(); return } this.submitting = true },
            }
        }
    </script>
@endsection
