@extends('layouts.backend')

@section('title', __('lf.LF_media_category_common_title'))
@section('page_title', __('lf.LF_media_category_common_title'))

@section('content')
    @if (session('success'))
        <div class="admin-alert admin-alert-success">
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

    <div class="admin-card admin-form-card">
        <form method="GET" action="{{ route('admin.media-categories.index') }}">
            <div class="lf-form-group">
                <label class="lf-form-label" for="keyword">
                    {{ __('lf.LF_media_category_common_keyword') }}
                </label>
                <input id="keyword" type="search" name="keyword" class="lf-form-control"
                       value="{{ $keyword }}"
                       placeholder="{{ __('lf.LF_media_category_common_keyword_placeholder') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="status">
                    {{ __('lf.LF_media_category_common_status') }}
                </label>
                <select id="status" name="status" class="lf-form-control">
                    <option value="">{{ __('lf.LF_media_category_common_all_statuses') }}</option>
                    <option value="active" @selected($status === 'active')>
                        {{ __('lf.LF_media_category_common_active') }}
                    </option>
                    <option value="archived" @selected($status === 'archived')>
                        {{ __('lf.LF_media_category_common_archived') }}
                    </option>
                </select>
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_common_button_search') }}
                </button>
                <a href="{{ route('admin.media-categories.index') }}">
                    {{ __('lf.LF_media_category_common_clear_filters') }}
                </a>
            </div>
        </form>
    </div>

    <div class="admin-form-actions">
        <a href="{{ route('admin.media-categories.create') }}" class="btn btn-primary">
            {{ __('lf.LF_media_category_common_create') }}
        </a>
    </div>

    <div class="admin-table-wrap">
        <table class="table admin-table-has-actions">
            <thead>
            <tr>
                <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                <th>{{ __('lf.LF_media_category_common_name') }}</th>
                <th>{{ __('lf.LF_media_category_common_parent') }}</th>
                <th>{{ __('lf.LF_media_category_common_slug') }}</th>
                <th>{{ __('lf.LF_media_category_common_sort_order') }}</th>
                <th>{{ __('lf.LF_media_category_common_status') }}</th>
                <th>{{ __('lf.table_actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($categories as $category)
                <tr>
                    <td class="admin-table-sequence">{{ $categories->firstItem() + $loop->index }}</td>
                    <td>{{ $category->name }}</td>
                    <td>{{ $category->parent_name ?? __('lf.LF_media_category_common_root') }}</td>
                    <td>{{ $category->slug }}</td>
                    <td>{{ $category->sort_order }}</td>
                    <td>
                        <span class="badge {{ $category->status === 'active' ? 'badge-success' : 'badge-danger' }}">
                            {{ $category->status === 'active'
                                ? __('lf.LF_common_status_common_active')
                                : __('lf.LF_common_status_common_inactive') }}
                        </span>
                    </td>
                    <td>
                        <x-admin-action-menu :label="__('lf.table_actions').': '.$category->name">
                            <a class="admin-table-action-link admin-text-action" href="{{ route('admin.media-categories.edit', $category->id) }}">
                                <x-admin-action-icon name="edit" />
                                {{ __('lf.action_edit') }}
                            </a>
                        </x-admin-action-menu>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        {{ __('lf.LF_media_category_common_empty') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($categories->hasPages())
        <div class="admin-pagination">
            {{ $categories->links() }}
        </div>
    @endif
@endsection
