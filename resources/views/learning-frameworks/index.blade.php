@extends('layouts.backend')

@section('title', __('lf.LF_learning_frameworks'))
@section('page_title', __('lf.LF_learning_frameworks'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js', 'resources/js/admin-ui-kit-row-actions.js'])
@endsection

@section('content')
<div id="framework-list" class="lf-admin-ui lf-admin-ui--list" data-ui-dialog-form-scope data-ui-row-actions
     data-csrf="{{ csrf_token() }}"
     data-msg-delete-error="{{ __('lf.LF_learning_delete_error') }}"
     data-msg-session-expired="{{ __('lf.LF_learning_session_expired') }}">
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

    <x-ui-list-head :title="__('lf.LF_learning_index_title')" :text="__('lf.LF_learning_manual_help')" />

    <section class="mk-section" aria-labelledby="admin-page-title">
        <div class="mk-tablebar mk-tablebar--filters">
            <form class="mk-filter-form" method="GET" action="{{ route('admin.learning-frameworks.index') }}" role="search" data-ui-live-filter>
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="framework-keyword">{{ __('lf.LF_common_button_search') }}</label>
                    <div class="ui-search">
                        <input id="framework-keyword" type="search" name="keyword" maxlength="255" value="{{ $keyword }}"
                               placeholder="{{ __('lf.LF_learning_index_search_placeholder') }}">
                        <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                            <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                        </button>
                    </div>
                </div>

                <span class="ui-live-slot" data-ui-live-region="clear-filters">
                    @if ($keyword !== '')
                        <a class="ui-button ui-button--outline cursor-interaction" href="{{ route('admin.learning-frameworks.index') }}">{{ __('lf.LF_learning_index_clear') }}</a>
                    @endif
                </span>
            </form>

            <x-ui-list-toolbar :url="route('admin.learning-frameworks.bulk-destroy')" name="ids[]"
                :label="__('lf.LF_learning_bulk_delete')"
                :confirm-title="__('lf.LF_learning_bulk_delete_title')" :confirm-message="__('lf.LF_learning_bulk_delete_confirm', ['count' => ':count'])"
                :confirm-label="__('lf.LF_learning_bulk_delete')">
                <x-slot:create>
                    @if ($totalCount > 0)
                        <button type="button" class="ui-button ui-button--outline cursor-interaction"
                                data-ui-dialog-form-open data-ui-dialog-form-for="framework-list" data-url="{{ route('admin.learning-frameworks.create') }}" aria-haspopup="dialog">
                            <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                            {{ __('lf.LF_learning_create_framework') }}
                        </button>
                    @endif
                </x-slot:create>
            </x-ui-list-toolbar>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ __('lf.LF_learning_frameworks') }}" tabindex="0">
            <table aria-labelledby="admin-page-title">
                <colgroup>
                    <col class="ui-col-check">
                    <col class="mk-col-sequence">
                    <col>
                    <col class="ui-framework-col-use">
                    <col class="mk-col-status">
                    <col class="mk-col-action">
                </colgroup>
                <thead>
                <tr>
                    <th scope="col" class="ui-check-cell">
                        <input type="checkbox" class="ui-bulk-box" data-ui-bulk-select-all aria-label="{{ __('lf.LF_learning_select_all') }}">
                    </th>
                    <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_learning_name') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_learning_simple_index_in_use') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.LF_learning_status') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($frameworks as $framework)
                    <tr data-lf-framework-row="{{ $framework->id }}">
                        <td class="ui-check-cell" data-label="{{ __('lf.LF_learning_select') }}">
                            @if ($framework->delete_blocker)
                                <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ $framework->delete_blocker }}" data-tooltip-align="start">
                                    <input type="checkbox" class="ui-bulk-box" disabled aria-label="{{ $framework->delete_blocker }}">
                                </span>
                            @else
                                <input type="checkbox" class="ui-bulk-box" data-ui-bulk-item value="{{ $framework->id }}"
                                       aria-label="{{ __('lf.LF_learning_select') }}: {{ $framework->name }}">
                            @endif
                        </td>
                        <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $frameworks->firstItem() + $loop->index }}</td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_learning_name') }}">
                            <span class="mk-name-text">{{ $framework->name }}</span>
                            @if ($framework->description)
                                <span class="ui-caption ui-framework-description">{{ \Illuminate\Support\Str::limit($framework->description, 140) }}</span>
                            @endif
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_learning_simple_index_in_use') }}">
                            <span class="ui-framework-use">
                                @if ($framework->current_version_number)
                                    <span class="ui-status ui-status--active">{{ __('lf.LF_learning_simple_version', ['number' => $framework->current_version_number]) }}</span>
                                    <span class="ui-caption">{{ trans_choice('lf.LF_learning_simple_count', (int) $framework->competency_count, ['count' => (int) $framework->competency_count]) }}</span>
                                @else
                                    <span class="ui-status ui-status--archived">{{ __('lf.LF_learning_simple_status_unpublished') }}</span>
                                @endif
                                @if ((int) $framework->draft_count > 0)
                                    <span class="ui-status ui-status--draft">{{ __('lf.LF_learning_simple_index_has_draft') }}</span>
                                @endif
                            </span>
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.LF_learning_status') }}">
                            <span class="ui-status ui-status--{{ $framework->status === 'active' ? 'active' : 'archived' }}">
                                {{ $framework->status === 'active' ? __('lf.LF_common_status_common_active') : __('lf.LF_learning_status_archived') }}
                            </span>
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                            <a class="ux-row-edit cursor-interaction" href="{{ route('admin.learning-frameworks.show', $framework->id) }}"
                               aria-label="{{ __('lf.LF_learning_manage') }}: {{ $framework->name }}"
                               data-tooltip="{{ __('lf.LF_learning_manage') }}">
                                <x-backend-icon name="sliders" class="ui-icon" />
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr class="mk-empty-row">
                        <td class="mk-empty" colspan="6">
                            <div role="status">
                                <strong>{{ $keyword !== '' ? __('lf.LF_learning_index_no_results') : __('lf.LF_learning_empty') }}</strong>
                                <p class="ui-caption">{{ $keyword !== '' ? __('lf.LF_learning_index_no_results_help') : __('lf.LF_learning_empty_help') }}</p>
                                @if ($totalCount === 0)
                                    <button type="button" class="ui-button ui-button--outline cursor-interaction"
                                            data-ui-dialog-form-open data-url="{{ route('admin.learning-frameworks.create') }}" aria-haspopup="dialog">{{ __('lf.LF_learning_create_framework') }}</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <x-ui-list-foot :paginator="$frameworks"
            :label="__('lf.LF_learning_frameworks')"
            :page-size-label="__('lf.LF_learning_index_page_size')"
            :page-size-option="__('lf.LF_learning_index_page_size_option', ['count' => $frameworks->perPage()])" />
    </section>

    <dialog class="ui-modal" data-columns="2" aria-labelledby="framework-dialog-title" data-ui-dialog-form data-field-id-prefix="framework-form-"
            data-msg-load-error="{{ __('lf.LF_learning_dialog_load_error') }}"
            data-msg-discard-title="{{ __('lf.LF_learning_dialog_discard_title') }}"
            data-msg-discard-text="{{ __('lf.LF_learning_dialog_discard_text') }}"
            data-msg-discard="{{ __('lf.LF_learning_dialog_discard') }}"
            data-msg-save-error="{{ __('lf.LF_learning_dialog_save_error') }}"
            data-msg-session-expired="{{ __('lf.LF_learning_session_expired') }}"
            data-msg-required-input="{{ __('lf.LF_learning_dialog_required_input') }}"
            data-msg-required-select="{{ __('lf.LF_learning_dialog_required_input') }}"></dialog>
</div>
@endsection
