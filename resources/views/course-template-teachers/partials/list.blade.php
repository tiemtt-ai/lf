@php
    // Only admins assign teachers; a teacher reads who is assigned.
    $canAssign = str_starts_with($teacherRoutePrefix, 'admin');
    $activeAssignments = $teacherAssignments->where('status', 'active');
    $removedAssignments = $teacherAssignments->where('status', '!=', 'active');
    $columnCount = $canAssign ? 5 : 4;
@endphp
<div class="lf-admin-ui lf-admin-ui--list ui-template-teachers" data-ui-dialog-form-scope data-ui-row-actions
     data-csrf="{{ csrf_token() }}"
     data-msg-delete-error="{{ __('lf.LF_common_dialog_save_error') }}"
     data-msg-session-expired="{{ __('lf.LF_common_dialog_session_expired') }}">
    <x-ui-list-toasts />

    <section id="course-template-teachers" class="mk-section" aria-labelledby="course-template-teachers-title">
        <div class="ui-summary-head">
            <div>
                <h2 id="course-template-teachers-title">{{ __('lf.LF_course_template_teacher_common_list_title') }}</h2>
                <p class="ui-caption">{{ __('lf.LF_course_template_teacher_common_list_help') }}</p>
            </div>
            @if ($canAssign)
                <button type="button" class="ui-button ui-button--outline cursor-interaction"
                        data-ui-dialog-form-open aria-haspopup="dialog"
                        data-url="{{ route($teacherRoutePrefix.'.create', $template->id) }}">
                    {{ __('lf.LF_course_template_teacher_common_add_action') }}
                </button>
            @endif
        </div>

        <div class="mk-table-scroll" role="region" aria-labelledby="course-template-teachers-title" tabindex="0">
            <table aria-labelledby="course-template-teachers-title">
                <colgroup>
                    <col>
                    <col class="mk-col-secondary">
                    <col class="mk-col-secondary">
                    <col class="mk-col-status">
                    @if ($canAssign)<col class="mk-col-action">@endif
                </colgroup>
                <thead>
                <tr>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_template_teacher_common_name') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_template_teacher_common_email') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_template_teacher_common_role') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_template_teacher_common_status') }}</th>
                    @if ($canAssign)<th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>@endif
                </tr>
                </thead>
                <tbody>
                {{-- Active assignments first; a removed one stays listed (its history) and is assigned again from Add. --}}
                @forelse ($activeAssignments->concat($removedAssignments) as $assignment)
                    @php($isActive = $assignment->status === 'active')
                    <tr @class(['ui-row--muted' => ! $isActive])>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_template_teacher_common_name') }}">
                            <span class="mk-name-text">{{ $assignment->teacher_name }}</span>
                        </td>
                        <td class="mk-text-cell mk-text-cell--nowrap" data-label="{{ __('lf.LF_course_template_teacher_common_email') }}">{{ $assignment->teacher_email }}</td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_template_teacher_common_role') }}">
                            {{ __('lf.LF_course_template_teacher_common_role_'.$assignment->role) }}
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.LF_course_template_teacher_common_status') }}">
                            <span class="ui-status ui-status--{{ $isActive ? 'active' : 'archived' }}">{{ __('lf.LF_course_template_teacher_common_status_'.$assignment->status) }}</span>
                        </td>
                        @if ($canAssign)
                            <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                @if ($isActive)
                                    <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open aria-haspopup="dialog"
                                            data-url="{{ route($teacherRoutePrefix.'.edit', [$template->id, $assignment->id]) }}"
                                            aria-label="{{ __('lf.LF_course_template_teacher_common_edit') }}: {{ $assignment->teacher_name }}"
                                            data-tooltip="{{ __('lf.LF_course_template_teacher_common_edit') }}">
                                        <x-backend-icon name="pencil" class="ui-icon" />
                                    </button>
                                    <button type="button" class="ux-row-edit ui-row-action--danger cursor-interaction" data-ui-row-delete
                                            data-url="{{ route($teacherRoutePrefix.'.destroy', [$template->id, $assignment->id]) }}"
                                            data-confirm-title="{{ __('lf.LF_course_template_teacher_common_remove') }}"
                                            data-confirm-message="{{ __('lf.LF_course_template_teacher_remove_confirm_named', ['name' => $assignment->teacher_name]) }}"
                                            data-confirm-label="{{ __('lf.LF_course_template_teacher_common_remove_yes') }}"
                                            aria-label="{{ __('lf.LF_course_template_teacher_common_remove') }}: {{ $assignment->teacher_name }}"
                                            data-tooltip="{{ __('lf.LF_course_template_teacher_common_remove') }}">
                                        <x-backend-icon name="trash-2" class="ui-icon" />
                                    </button>
                                @else
                                    <span class="ui-caption" data-tooltip="{{ __('lf.LF_course_template_teacher_reassign_hint') }}">—</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr class="mk-empty-row">
                        <td class="mk-empty" colspan="{{ $columnCount }}">
                            <div role="status">
                                <strong>{{ trans_choice('lf.LF_course_template_teacher_common_count', 0, ['count' => 0]) }}</strong>
                                <p class="ui-caption">{{ __('lf.LF_course_template_teacher_common_empty') }}</p>
                                @if ($canAssign)
                                    <button type="button" class="ui-button ui-button--outline cursor-interaction"
                                            data-ui-dialog-form-open aria-haspopup="dialog"
                                            data-url="{{ route($teacherRoutePrefix.'.create', $template->id) }}">
                                        {{ __('lf.LF_course_template_teacher_common_add_action') }}
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($teacherAssignments->isNotEmpty())
            <div class="mk-table-foot">
                <span class="mk-footnote" role="status">
                    {{ trans_choice('lf.LF_course_template_teacher_common_count', $activeAssignments->count(), ['count' => $activeAssignments->count()]) }}
                </span>
            </div>
        @endif
    </section>

    @if ($canAssign)
        <x-ui-form-dialog-host title-id="teacher-dialog-title" :columns="1" />
    @endif
</div>
