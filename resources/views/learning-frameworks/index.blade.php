@extends('layouts.backend')

@section('title', __('lf.LF_learning_frameworks'))
@section('page_title', __('lf.LF_learning_frameworks'))

@section('content')
    @include('learning-frameworks.partials.errors')

    <div class="learning-framework-index-toolbar">
        <div class="learning-framework-index-intro">
            <span class="learning-framework-index-count">
                {{ trans_choice('lf.LF_learning_index_count', $frameworks->count(), ['count' => $frameworks->count()]) }}
            </span>
            <p class="learning-framework-index-help">{{ __('lf.LF_learning_manual_help') }}</p>
        </div>
        <a href="{{ route('admin.learning-frameworks.create') }}"
           class="btn btn-primary learning-framework-create-action">
            <span aria-hidden="true">+</span>
            {{ __('lf.LF_learning_create_framework') }}
        </a>
    </div>

    <div class="admin-table-wrap">
        <table class="table learning-framework-index-table admin-table-has-actions">
            <thead>
                <tr>
                    <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                    <th class="learning-framework-index-name">{{ __('lf.LF_learning_name') }}</th>
                    <th class="learning-framework-index-versions">{{ __('lf.LF_learning_versions') }}</th>
                    <th class="learning-framework-index-status">{{ __('lf.LF_learning_status') }}</th>
                    <th class="learning-framework-index-actions">{{ __('lf.table_actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($frameworks as $framework)
                    <tr>
                        <td class="admin-table-sequence" data-label="{{ __('lf.table_no') }}">
                            {{ $loop->iteration }}
                        </td>
                        <td class="learning-framework-index-name" data-label="{{ __('lf.LF_learning_name') }}">
                            <a class="learning-framework-index-primary"
                               href="{{ route('admin.learning-frameworks.show', $framework->id) }}">
                                {{ $framework->name }}
                            </a>
                            <span class="learning-framework-index-meta">{{ $framework->code }}</span>
                        </td>
                        <td class="learning-framework-index-versions" data-label="{{ __('lf.LF_learning_versions') }}">
                            @if ((int) $framework->version_count === 0)
                                <span class="learning-framework-index-muted">{{ __('lf.LF_learning_no_version') }}</span>
                            @else
                                <span class="learning-framework-version-chips">
                                    @if ((int) $framework->draft_count > 0)
                                        <span class="badge badge-info">
                                            {{ (int) $framework->draft_count }} {{ __('lf.LF_learning_draft') }}
                                        </span>
                                    @endif
                                    @if ((int) $framework->published_count > 0)
                                        <span class="badge badge-success">
                                            {{ (int) $framework->published_count }} {{ __('lf.LF_learning_published') }}
                                        </span>
                                    @endif
                                    <span class="learning-framework-index-muted">
                                        {{ __('lf.LF_learning_version_total', ['count' => (int) $framework->version_count]) }}
                                    </span>
                                </span>
                            @endif
                        </td>
                        <td class="learning-framework-index-status" data-label="{{ __('lf.LF_learning_status') }}">
                            <span class="badge {{ $framework->status === 'active' ? 'badge-success' : 'badge-neutral' }}">
                                {{ $framework->status === 'active'
                                    ? __('lf.LF_common_status_common_active')
                                    : __('lf.LF_learning_status_archived') }}
                            </span>
                        </td>
                        <td class="learning-framework-index-actions" data-label="{{ __('lf.table_actions') }}">
                            <x-admin-action-menu :label="__('lf.table_actions').': '.$framework->name">
                                <a class="admin-table-action-link admin-text-action"
                                   href="{{ route('admin.learning-frameworks.show', $framework->id) }}">
                                    <x-admin-action-icon name="edit" />
                                    {{ __('lf.LF_learning_manage') }}
                                </a>
                            </x-admin-action-menu>
                        </td>
                    </tr>
                @empty
                    <tr class="learning-framework-empty-row">
                        <td class="learning-framework-empty-cell" colspan="5">
                            <div class="learning-framework-empty-state" role="status">
                                <strong>{{ __('lf.LF_learning_empty') }}</strong>
                                <span>{{ __('lf.LF_learning_empty_help') }}</span>
                                <a class="btn btn-primary" href="{{ route('admin.learning-frameworks.create') }}">
                                    {{ __('lf.LF_learning_create_framework') }}
                                </a>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
