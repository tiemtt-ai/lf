@extends('layouts.backend')

@section('title', __('lf.LF_teacher_permission_title'))
@section('page_title', __('lf.LF_teacher_permission_title'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
    @php
        $indexUrl = route('admin.teacher-permissions.index');
        $createRetry = $errors->getBag('groupCreate')->isNotEmpty();
    @endphp
<div class="lf-admin-ui lf-admin-ui--list ui-teacher-permissions" x-data>
    <x-ui-list-toasts />

    @if ($errors->has('group'))
        <div class="admin-alert admin-alert-danger" role="alert">{{ $errors->first('group') }}</div>
    @endif

    <x-ui-list-head :title="__('lf.LF_teacher_permission_title')" :text="__('lf.LF_teacher_permission_description')" />

    <section class="mk-section" aria-labelledby="teacher-permission-groups-title">
        <div class="ui-summary-head">
            <div>
                <h2 id="teacher-permission-groups-title">{{ __('lf.LF_teacher_permission_list_title') }}</h2>
                <p class="ui-caption">{{ __('lf.LF_teacher_permission_list_help') }}</p>
            </div>
            <div class="mk-table-top__actions">
                <span class="ui-caption ui-enroll-nowrap">{{ trans_choice('lf.LF_teacher_permission_group_count', $groups->count(), ['count' => $groups->count()]) }}</span>
                <button type="button" class="ui-button ui-button--outline cursor-interaction" aria-haspopup="dialog"
                        x-on:click="document.getElementById('teacher-permission-group-create').showModal()">
                    <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                    {{ __('lf.LF_teacher_permission_group_add') }}
                </button>
            </div>
        </div>

        <div class="mk-table-scroll" role="region" aria-labelledby="teacher-permission-groups-title" tabindex="0">
            <table>
                <colgroup>
                    <col class="mk-col-sequence">
                    <col class="ui-teacher-permission-col-name">
                    <col>
                    <col class="ui-attendance-policy-col-count">
                    <col class="ui-attendance-policy-col-action">
                </colgroup>
                <thead><tr>
                    <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_teacher_permission_group_field_name') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_teacher_permission_col_permissions') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.LF_teacher_permission_col_teachers') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($groups as $group)
                    @php $granted = array_values(array_intersect(\App\Services\TeacherPermissions::AVAILABLE, $group->keys)); @endphp
                    <tr>
                        <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_teacher_permission_group_field_name') }}">
                            <span class="mk-name-text">{{ $group->name }}</span>
                            @if ($group->is_default && mb_strtolower(trim($group->name)) !== mb_strtolower(__('lf.LF_teacher_permission_group_default_badge')))
                                <span class="ui-status ui-status--active">{{ __('lf.LF_teacher_permission_group_default_badge') }}</span>
                            @endif
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_teacher_permission_col_permissions') }}">
                            @if ($granted === [])
                                <span class="ui-caption">{{ __('lf.LF_teacher_permission_none') }}</span>
                            @else
                                <span class="ui-teacher-permission-chips">
                                    @foreach ($granted as $key)
                                        <span class="ui-teacher-permission-tag">{{ __('lf.LF_teacher_permission_key_'.str_replace('.', '_', $key)) }}</span>
                                    @endforeach
                                </span>
                            @endif
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.LF_teacher_permission_col_teachers') }}">{{ $group->teacher_count }}</td>
                        <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                            <a class="ux-row-edit cursor-interaction" href="{{ $indexUrl }}?group_form=teachers&group_id={{ $group->id }}"
                               aria-label="{{ __('lf.LF_teacher_permission_group_teachers') }}: {{ $group->name }}" data-tooltip="{{ __('lf.LF_teacher_permission_group_teachers') }}">
                                <x-backend-icon name="users" class="ui-icon" />
                            </a>
                            <a class="ux-row-edit cursor-interaction" href="{{ $indexUrl }}?group_form=edit&group_id={{ $group->id }}"
                               aria-label="{{ __('lf.LF_common_button_edit') }}: {{ $group->name }}" data-tooltip="{{ __('lf.LF_common_button_edit') }}">
                                <x-backend-icon name="square-pen" class="ui-icon" />
                            </a>
                            @if ($group->is_default || $group->teacher_count > 0)
                                @php $reason = $group->is_default ? __('lf.LF_teacher_permission_group_delete_default') : __('lf.LF_teacher_permission_group_delete_used'); @endphp
                                <span class="ux-row-delete is-disabled" tabindex="0" role="img" aria-label="{{ $reason }}" data-tooltip="{{ $reason }}" data-tooltip-align="end">
                                    <x-backend-icon name="trash" class="ui-icon" />
                                </span>
                            @else
                                <form method="POST" class="ui-cohort-row-form" action="{{ route('admin.teacher-permissions.destroy', $group->id) }}"
                                      data-lf-confirm="{{ __('lf.LF_teacher_permission_group_delete_confirm', ['name' => $group->name]) }}"
                                      data-lf-confirm-tone="danger" data-lf-confirm-label="{{ __('lf.LF_common_button_delete') }}">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="ux-row-delete cursor-interaction"
                                            aria-label="{{ __('lf.LF_common_button_delete') }}: {{ $group->name }}" data-tooltip="{{ __('lf.LF_common_button_delete') }}">
                                        <x-backend-icon name="trash" class="ui-icon" />
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="mk-section ui-teacher-permission-fixed" aria-labelledby="teacher-permission-fixed-title">
        <h2 id="teacher-permission-fixed-title">{{ __('lf.LF_teacher_permission_fixed_title') }}</h2>
        <p class="ui-caption"><b>{{ __('lf.LF_teacher_permission_always_label') }}</b> {{ __('lf.LF_teacher_permission_always') }}</p>
        <p class="ui-caption"><b>{{ __('lf.LF_teacher_permission_never_label') }}</b> {{ __('lf.LF_teacher_permission_never') }}</p>
    </section>

    @include('teacher-permissions.partials.form', [
        'dialogId' => 'teacher-permission-group-create',
        'group' => null,
        'bag' => 'groupCreate',
        'autoOpen' => $createRetry,
    ])
    @if ($formMode === 'edit')
        @include('teacher-permissions.partials.form', [
            'dialogId' => 'teacher-permission-group-edit',
            'group' => $formGroup,
            'bag' => 'groupEdit',
            'autoOpen' => true,
        ])
    @elseif ($formMode === 'teachers')
        @include('teacher-permissions.partials.teachers', ['group' => $formGroup, 'teachers' => $teachers])
    @endif
</div>
@endsection
