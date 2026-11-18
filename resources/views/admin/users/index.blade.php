@extends('layouts.backend')

@section('title', __('lf.LF_admin_title_admin_users'))
@section('page_title', __('lf.LF_navigation_menu_admin_users'))
@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js', 'resources/js/admin-ui-kit-row-actions.js'])
@endsection

@section('content')
@php
    $hasActiveFilters = $keyword !== '' || $status;
    // Tab order: Học viên, Giáo viên, Quản trị khách hàng (Owner 2026-11-18).
    $roleLabels = [
        'student' => __('lf.LF_common_role_student_student'),
        'teacher' => __('lf.LF_common_role_teacher_teacher'),
        'customer_admin' => __('lf.LF_common_role_admin_customer_admin'),
    ];
    $roleLabel = mb_strtolower($roleLabels[$role]);
    // Only the owner creates admins or touches another admin's account (ADR-0033 D2).
    $mayCreate = $role !== 'customer_admin' || $isOwner;
    $ownerOnly = __('lf.LF_admin_user_error_owner_only');
    $selectAttributes = 'data-ui-kit-select data-ui-empty-label="'.e(__('lf.LF_course_category_index_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_admin_user_clear_filters')).'"';
@endphp

<div id="user-list" class="lf-admin-ui lf-admin-ui--list" data-ui-row-actions data-ui-dialog-form-scope>
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

    <x-ui-list-head :title="__('lf.LF_admin_user_index_heading')" :text="__('lf.LF_admin_user_index_description')" />

    {{-- Page tabs sit above the card, like the Template tabs (Owner 2026-11-18). --}}
    <nav class="course-template-tabs" data-ui-live-region="tabs" aria-label="{{ __('lf.LF_admin_user_role_navigation') }}">
        @foreach ($roleLabels as $roleKey => $label)
            <a href="{{ route('admin.users.index', array_filter(['role' => $roleKey, 'keyword' => $keyword, 'status' => $status])) }}"
               @class(['course-template-tab', 'cursor-interaction', 'is-active' => $role === $roleKey])
               @if ($role === $roleKey) aria-current="page" @endif>{{ $label }} <span class="ui-tab-count">{{ $roleCounts[$roleKey] ?? 0 }}</span></a>
        @endforeach
    </nav>

    <section class="mk-section" aria-labelledby="admin-page-title">

        <div class="mk-tablebar mk-tablebar--filters">
            <form class="mk-filter-form" method="GET" action="{{ route('admin.users.index') }}" data-ui-live-filter>
                <input type="hidden" name="role" value="{{ $role }}">

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="keyword">{{ __('lf.LF_admin_user_search_label') }}</label>
                    <div class="ui-search">
                        <input id="keyword" type="search" name="keyword" value="{{ $keyword }}"
                               placeholder="{{ __('lf.LF_admin_user_search_placeholder') }}">
                        <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                            <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                        </button>
                    </div>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="status">{{ __('lf.LF_common_label_common_status') }}</label>
                    <select id="status" name="status" {!! $selectAttributes !!}>
                        <option value="">{{ __('lf.LF_admin_user_status_filter_all') }}</option>
                        <option value="active" @selected($status === 'active')>{{ __('lf.LF_common_status_common_active') }}</option>
                        <option value="inactive" @selected($status === 'inactive')>{{ __('lf.LF_common_status_common_inactive') }}</option>
                    </select>
                </div>

                <span class="ui-live-slot" data-ui-live-region="clear-filters">
                    @if ($hasActiveFilters)
                        <a class="ui-button ui-button--outline cursor-interaction" href="{{ route('admin.users.index', ['role' => $role]) }}">
                            {{ __('lf.LF_admin_user_clear_filters') }}
                        </a>
                    @endif
                </span>
            </form>

            <div class="mk-table-top__actions" data-ui-live-region="toolbar">
                @if ($mayCreate)
                    <button type="button" class="ui-button ui-button--outline cursor-interaction" data-ui-dialog-form-open
                            data-url="{{ route('admin.users.create', ['role' => $role]) }}" aria-haspopup="dialog">
                        <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                        {{ __('lf.LF_admin_user_create_'.$role) }}
                    </button>
                @endif
                <a class="ui-button ui-button--outline cursor-interaction" download
                   href="{{ route('admin.users.export', array_filter(['role' => $role, 'keyword' => $keyword, 'status' => $status])) }}">
                    <x-backend-icon name="file-output" class="ui-icon" />
                    {{ __('lf.LF_export_excel') }}
                </a>
            </div>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ $roleLabels[$role] }}" tabindex="0">
            <table aria-labelledby="admin-page-title">
                <colgroup>
                    <col class="mk-col-sequence">
                    <col>
                    <col>
                    <col class="mk-col-parent">
                    <col class="mk-col-status">
                    <col class="mk-col-action">
                </colgroup>
                <thead>
                <tr>
                    <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_common_label_name') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_common_label_email') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_common_label_phone') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.LF_common_label_common_status') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $users->firstItem() + $loop->index }}</td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_common_label_name') }}">
                            <span class="mk-name-line"><span class="mk-name-text">{{ $user->name }}</span>
                                @if ((int) $user->id === $ownerId)
                                    <span class="ui-status ui-status--active">{{ __('lf.LF_admin_user_owner_badge') }}</span>
                                @endif
                            </span>
                        </td>
                        <td class="mk-text-cell mk-text-cell--nowrap" data-label="{{ __('lf.LF_common_label_email') }}">{{ $user->email }}</td>
                        <td @class(['mk-text-cell', 'mk-text-cell--nowrap', 'mk-na' => ! $user->phone]) data-label="{{ __('lf.LF_common_label_phone') }}">{{ $user->phone ?: '-' }}</td>
                        <td class="ux-action-cell" data-label="{{ __('lf.LF_common_label_common_status') }}">
                            <span @class(['ui-status', 'ui-status--active' => $user->status === 'active', 'ui-status--archived' => $user->status !== 'active'])>
                                {{ $user->status === 'active' ? __('lf.LF_common_status_common_active') : __('lf.LF_common_status_common_inactive') }}
                            </span>
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                            @php $manageable = in_array((int) $user->id, $manageableIds, true); @endphp
                            @if (! $manageable)
                                <span class="ux-row-edit is-disabled" tabindex="0" role="img" aria-label="{{ $ownerOnly }}" data-tooltip="{{ $ownerOnly }}" data-tooltip-align="end">
                                    <x-backend-icon name="square-pen" class="ui-icon" />
                                </span>
                            @else
                            <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open aria-haspopup="dialog"
                                    data-url="{{ route('admin.users.edit', $user->id) }}"
                                    aria-label="{{ __('lf.action_edit') }}: {{ $user->name }}" data-tooltip="{{ __('lf.action_edit') }}">
                                <x-backend-icon name="square-pen" class="ui-icon" />
                            </button>
                            <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open aria-haspopup="dialog"
                                    data-url="{{ route('admin.users.password.edit', $user->id) }}"
                                    aria-label="{{ __('lf.LF_admin_user_action_reset_password') }}: {{ $user->name }}" data-tooltip="{{ __('lf.LF_admin_user_action_reset_password') }}">
                                <x-backend-icon name="lock" class="ui-icon" />
                            </button>
                            @if ($isOwner && $user->role === 'customer_admin' && $user->status === 'active' && (int) $user->id !== $ownerId)
                                <form method="POST" class="ui-cohort-row-form" action="{{ route('admin.users.transfer-ownership', $user->id) }}"
                                      data-lf-confirm="{{ __('lf.LF_admin_user_owner_transfer_confirm', ['name' => $user->name]) }}"
                                      data-lf-confirm-label="{{ __('lf.LF_admin_user_owner_transfer') }}">
                                    @csrf
                                    <button type="submit" class="ux-row-edit cursor-interaction"
                                            aria-label="{{ __('lf.LF_admin_user_owner_transfer') }}: {{ $user->name }}" data-tooltip="{{ __('lf.LF_admin_user_owner_transfer') }}" data-tooltip-align="end">
                                        <x-backend-icon name="user-cog" class="ui-icon" />
                                    </button>
                                </form>
                            @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr class="mk-empty-row">
                        <td class="mk-empty" colspan="6">
                            <div role="status">
                                <strong>{{ $hasActiveFilters ? __('lf.LF_admin_user_filter_empty') : __('lf.LF_admin_user_empty', ['role' => $roleLabel]) }}</strong>
                                <p class="ui-caption">{{ $hasActiveFilters ? __('lf.LF_admin_user_filter_empty_help') : __('lf.LF_admin_user_empty_help', ['role' => $roleLabel]) }}</p>
                                @if (! $hasActiveFilters && $mayCreate)
                                    <button type="button" class="ui-button ui-button--outline cursor-interaction" data-ui-dialog-form-open
                                            data-url="{{ route('admin.users.create', ['role' => $role]) }}" aria-haspopup="dialog">
                                        {{ __('lf.LF_admin_user_create_'.$role) }}
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <x-ui-list-foot :paginator="$users"
            :label="$roleLabels[$role]"
            :page-size-label="__('lf.LF_course_category_index_page_size')"
            :page-size-option="__('lf.LF_course_category_index_page_size_option', ['count' => $users->perPage()])" />
    </section>

    <x-ui-form-dialog-host title-id="user-dialog-title" />
</div>
@endsection
