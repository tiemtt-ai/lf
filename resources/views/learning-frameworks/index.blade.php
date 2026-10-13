@extends('layouts.backend')

@section('title', __('lf.LF_learning_frameworks'))
@section('page_title', __('lf.LF_learning_frameworks'))

@section('content')
    @include('learning-frameworks.partials.errors')

    <div class="lf-framework-index">
        <div class="lf-framework-index__toolbar">
            <div class="lf-framework-index__intro">
                <span class="lf-framework-index__count">
                    {{ $keyword !== '' ? __('lf.LF_learning_index_results', ['count' => $frameworks->count(), 'total' => $totalCount]) : trans_choice('lf.LF_learning_index_count', $totalCount, ['count' => $totalCount]) }}
                </span>
                <p class="lf-framework-index__help">{{ __('lf.LF_learning_manual_help') }}</p>
            </div>
            @if ($totalCount > 0)
                <a href="{{ route('admin.learning-frameworks.create') }}" class="btn admin-primary-outline-action lf-framework-index__create">
                    <span aria-hidden="true">+</span>
                    {{ __('lf.LF_learning_create_framework') }}
                </a>
            @endif
        </div>

        <div class="admin-card admin-form-card lf-framework-index__filter">
            <form class="lf-framework-index__filter-grid" method="GET" action="{{ route('admin.learning-frameworks.index') }}" role="search">
                <div class="lf-form-group">
                    <label class="lf-form-label" for="framework-keyword">{{ __('lf.LF_common_button_search') }}</label>
                    <input class="lf-form-control" id="framework-keyword" type="search" name="keyword" maxlength="255"
                           value="{{ $keyword }}" placeholder="{{ __('lf.LF_learning_index_search_placeholder') }}">
                </div>
                <div class="admin-form-actions lf-framework-index__filter-actions">
                    <button class="btn btn-primary" type="submit">{{ __('lf.LF_common_button_search') }}</button>
                    @if ($keyword !== '')
                        <a class="admin-text-action" href="{{ route('admin.learning-frameworks.index') }}">{{ __('lf.LF_learning_index_clear') }}</a>
                    @endif
                </div>
            </form>
        </div>

        @if ($frameworks->isEmpty() && $keyword !== '')
            <div class="admin-card lf-framework-index__empty" role="status">
                <strong>{{ __('lf.LF_learning_index_no_results') }}</strong>
                <span>{{ __('lf.LF_learning_index_no_results_help') }}</span>
            </div>
        @elseif ($frameworks->isEmpty())
            <div class="admin-card lf-framework-index__empty" role="status">
                <strong>{{ __('lf.LF_learning_empty') }}</strong>
                <span>{{ __('lf.LF_learning_empty_help') }}</span>
                <a class="btn admin-primary-outline-action lf-framework-index__create" href="{{ route('admin.learning-frameworks.create') }}">
                    <span aria-hidden="true">+</span>
                    {{ __('lf.LF_learning_create_framework') }}
                </a>
            </div>
        @else
            <div class="admin-table-wrap lf-framework-index__table-wrap">
                <table class="table lf-framework-index__table admin-managed-list">
                    <thead>
                        <tr>
                            <th scope="col" class="admin-table-sequence admin-managed-sequence">{{ __('lf.table_no') }}</th>
                            <th scope="col">{{ __('lf.LF_learning_name') }}</th>
                            <th scope="col" class="lf-framework-index__in-use">{{ __('lf.LF_learning_simple_index_in_use') }}</th>
                            <th scope="col" class="admin-managed-status">{{ __('lf.LF_learning_status') }}</th>
                            <th scope="col" class="admin-managed-actions">{{ __('lf.table_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($frameworks as $framework)
                            <tr data-lf-framework-row="{{ $framework->id }}">
                                <td class="admin-table-sequence admin-managed-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                                <td data-label="{{ __('lf.LF_learning_name') }}">
                                    <div class="lf-framework-index__identity">
                                        <a class="lf-framework-index__name" href="{{ route('admin.learning-frameworks.show', $framework->id) }}">{{ $framework->name }}</a>
                                        @if ($framework->description)
                                            <span class="lf-framework-index__description">{{ \Illuminate\Support\Str::limit($framework->description, 140) }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="lf-framework-index__in-use" data-label="{{ __('lf.LF_learning_simple_index_in_use') }}">
                                    <span class="lf-framework-index__versions">
                                        @if ($framework->current_version_number)
                                            <span class="badge badge-success">{{ __('lf.LF_learning_simple_version', ['number' => $framework->current_version_number]) }}</span>
                                            <span class="lf-framework-index__meta">{{ trans_choice('lf.LF_learning_simple_count', (int) $framework->competency_count, ['count' => (int) $framework->competency_count]) }}</span>
                                        @else
                                            <span class="badge badge-neutral">{{ __('lf.LF_learning_simple_status_unpublished') }}</span>
                                        @endif
                                        @if ((int) $framework->draft_count > 0)
                                            <span class="badge lf-framework-index__draft">{{ __('lf.LF_learning_simple_index_has_draft') }}</span>
                                        @endif
                                    </span>
                                </td>
                                <td class="admin-managed-status" data-label="{{ __('lf.LF_learning_status') }}">
                                    <span class="badge {{ $framework->status === 'active' ? 'badge-success' : 'badge-neutral' }}">
                                        {{ $framework->status === 'active' ? __('lf.LF_common_status_common_active') : __('lf.LF_learning_status_archived') }}
                                    </span>
                                </td>
                                <td class="admin-managed-actions" data-label="{{ __('lf.table_actions') }}">
                                        <a class="btn btn-secondary course-template-manage" href="{{ route('admin.learning-frameworks.show', $framework->id) }}">
                                            <x-admin-action-icon name="edit" />
                                            {{ __('lf.LF_learning_manage') }}
                                        </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
