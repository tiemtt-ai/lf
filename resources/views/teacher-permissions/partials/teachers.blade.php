{{-- "Giáo viên của nhóm": tick the teachers of this group. On the default group its own teachers are ticked and locked;
     to move one elsewhere, open the other group and tick them there. Reuses the attendance policy "apply" list styles. --}}
@php
    $isDefault = (bool) $group->is_default;
    $rows = $teachers->map(fn (object $teacher): array => [
        'id' => (int) $teacher->id,
        'name' => $teacher->name,
        'email' => (string) $teacher->email,
        'locked' => $teacher->status !== 'active',
        'groupId' => $teacher->group_id,
        'groupName' => $teacher->group_name,
        'own' => $teacher->group_id === (int) $group->id,
    ])->values();
    $groupOptions = $teachers->pluck('group_name', 'group_id')->sort();
    $closeUrl = route('admin.teacher-permissions.index');
@endphp
<dialog class="ui-modal ui-attendance-policy-apply" data-columns="2" aria-labelledby="teacher-permission-teachers-title"
        x-data="{
            submitting: false,
            isDefault: @js($isDefault),
            teachers: @js($rows),
            ticked: @js($rows->where('own', true)->pluck('id')->values()),
            keyword: '', groupFilter: '',
            locked(teacher) { return this.isDefault && teacher.own },
            visible() {
                const needle = this.keyword.trim().toLowerCase()
                return this.teachers.filter(teacher => (! needle || teacher.name.toLowerCase().includes(needle) || teacher.email.toLowerCase().includes(needle))
                    && (! this.groupFilter || String(teacher.groupId) === this.groupFilter))
            },
            joining() { return this.teachers.filter(teacher => ! teacher.own && this.ticked.includes(teacher.id)) },
            leaving() { return this.teachers.filter(teacher => teacher.own && ! this.ticked.includes(teacher.id)) },
            names(list) { return list.slice(0, 5).map(teacher => teacher.name).join(', ') + (list.length > 5 ? '…' : '') },
        }"
        x-init="$nextTick(() => $el.showModal())" x-on:close="window.location.assign(@js($closeUrl))"
        x-on:cancel="if (submitting) $event.preventDefault()">
    <form method="POST" action="{{ route('admin.teacher-permissions.assign', $group->id) }}" class="ui-modal__form"
          x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
        @csrf @method('PUT')
        <header class="ui-modal__header">
            <div>
                <h3 id="teacher-permission-teachers-title">{{ __('lf.LF_teacher_permission_teachers_title', ['name' => $group->name]) }}</h3>
                <p class="ui-caption">{{ $isDefault ? __('lf.LF_teacher_permission_teachers_help_default') : __('lf.LF_teacher_permission_teachers_help') }}</p>
            </div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$root.close()" :disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            <template x-for="teacher in teachers" :key="'shown-' + teacher.id"><input type="hidden" name="shown_teacher_ids[]" :value="teacher.id"></template>
            <template x-for="id in ticked" :key="'ticked-' + id"><input type="hidden" name="teacher_ids[]" :value="id"></template>
            @if ($teachers->isEmpty())
                <p class="ui-caption" role="status">{{ __('lf.LF_teacher_permission_teachers_empty') }}</p>
            @else
                <div class="ui-attendance-policy-apply__filters ui-teacher-permission-filters">
                    <div class="ui-search">
                        <input type="search" x-model="keyword" aria-label="{{ __('lf.LF_teacher_permission_teachers_search') }}" placeholder="{{ __('lf.LF_teacher_permission_teachers_search') }}">
                    </div>
                    <label class="ui-sr-only" for="teacher-permission-group-filter">{{ __('lf.LF_teacher_permission_teachers_filter_group') }}</label>
                    <select id="teacher-permission-group-filter" x-model="groupFilter" data-ui-kit-select
                            data-ui-clear-label="{{ __('lf.LF_teacher_permission_teachers_filter_group') }}">
                        <option value="">{{ __('lf.LF_teacher_permission_teachers_filter_group') }}</option>
                        @foreach ($groupOptions as $groupId => $groupName)
                            <option value="{{ $groupId }}">{{ $groupName }}</option>
                        @endforeach
                    </select>
                </div>
                <ul class="ui-attendance-policy-apply__list" aria-label="{{ __('lf.LF_teacher_permission_col_teachers') }}">
                    <template x-for="teacher in visible()" :key="teacher.id">
                        <li>
                            <label class="ui-attendance-policy-apply__row" x-bind:class="{ 'is-locked': locked(teacher) }">
                                <input type="checkbox" :value="teacher.id" x-model.number="ticked" x-bind:disabled="locked(teacher)">
                                <span class="ui-attendance-policy-apply__copy">
                                    <b x-text="teacher.name"></b>
                                    <small><span x-text="teacher.email"></span><span x-show="teacher.locked"> · {{ __('lf.LF_common_status_common_inactive') }}</span></small>
                                </span>
                                <span class="ui-caption" x-text="teacher.groupName"></span>
                            </label>
                        </li>
                    </template>
                    <li x-show="visible().length === 0" x-cloak><p class="ui-caption" role="status">{{ __('lf.LF_teacher_permission_teachers_no_match') }}</p></li>
                </ul>
                <div class="ui-attendance-policy-apply__summary" role="status" aria-live="polite">
                    <strong>{{ __('lf.LF_attendance_policy_apply_summary') }}</strong>
                    <p x-show="joining().length === 0 && leaving().length === 0">{{ __('lf.LF_attendance_policy_apply_summary_none') }}</p>
                    <p x-show="joining().length > 0" x-cloak>
                        <span x-text="@js(__('lf.LF_teacher_permission_teachers_summary_join', ['name' => $group->name])).replace(':count', joining().length)"></span>
                        <span class="ui-caption" x-text="names(joining())"></span>
                    </p>
                    <p x-show="leaving().length > 0" x-cloak>
                        <span x-text="@js(__('lf.LF_teacher_permission_teachers_summary_leave')).replace(':count', leaving().length)"></span>
                        <span class="ui-caption" x-text="names(leaving())"></span>
                    </p>
                </div>
            @endif
        </div>
        <footer class="ui-modal__footer">
            <span></span>
            <button type="button" class="ui-button cursor-interaction" x-on:click="$root.close()" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
            <button type="submit" class="ui-button ui-button--primary cursor-interaction"
                    :disabled="submitting || (joining().length === 0 && leaving().length === 0)">
                <x-backend-icon name="check" class="ui-icon" />
                {{ __('lf.LF_teacher_permission_teachers_confirm') }}
            </button>
        </footer>
    </form>
</dialog>
