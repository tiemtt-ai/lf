@extends('layouts.backend')

@section('title', __('lf.LF_course_category_common_title'))
@section('page_title', __('lf.LF_course_category_common_title'))

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
        <form method="GET" action="{{ route($routePrefix.'.index') }}">
            <div class="lf-form-group">
                <label class="lf-form-label" for="keyword">
                    {{ __('lf.LF_course_category_common_keyword') }}
                </label>
                <input id="keyword" type="search" name="keyword" class="lf-form-control"
                       value="{{ $keyword }}"
                       placeholder="{{ __('lf.LF_course_category_common_keyword_placeholder') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="status">
                    {{ __('lf.LF_course_category_common_status') }}
                </label>
                <select id="status" name="status" class="lf-form-control">
                    <option value="">{{ __('lf.LF_course_category_common_all_statuses') }}</option>
                    <option value="active" @selected($status === 'active')>
                        {{ __('lf.LF_course_category_common_active') }}
                    </option>
                    <option value="inactive" @selected($status === 'inactive')>
                        {{ __('lf.LF_course_category_common_inactive') }}
                    </option>
                </select>
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_common_button_search') }}
                </button>
                <a href="{{ route($routePrefix.'.index') }}">
                    {{ __('lf.LF_course_category_common_clear_filters') }}
                </a>
            </div>
        </form>
    </div>

    <div class="admin-form-actions">
        <button type="button"
                class="btn btn-primary"
                x-data
                x-on:click="$dispatch('open-modal', 'create-course-category')">
            {{ __('lf.LF_course_category_common_create') }}
        </button>
    </div>

    <x-modal name="create-course-category"
             max-width="6xl"
             :title="__('lf.LF_course_category_common_create')"
             focusable>
        <div class="lf-modal-card course-category-modal">
            <form method="POST" action="{{ route($routePrefix.'.store') }}" enctype="multipart/form-data">
                @csrf

                @include('course-categories.partials.form', [
                    'category' => null,
                    'fieldPrefix' => 'create-course-category',
                    'parentCategories' => $parentCategories,
                    'thumbnailMedia' => null,
                    'bannerMedia' => null,
                ])

                <div class="lf-modal-actions">
                    <button type="button"
                            class="btn btn-secondary"
                            x-on:click="$dispatch('close-modal', 'create-course-category')">
                        {{ __('lf.LF_common_button_cancel') }}
                    </button>
                    <button type="submit" class="btn btn-primary">
                        {{ __('lf.LF_course_category_common_create') }}
                    </button>
                </div>
            </form>
        </div>
    </x-modal>

    <div class="admin-table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>{{ __('lf.LF_common_label_common_id') }}</th>
                <th>{{ __('lf.LF_course_category_common_thumbnail_image') }}</th>
                <th>{{ __('lf.LF_course_category_common_name') }}</th>
                <th>{{ __('lf.LF_course_category_common_parent') }}</th>
                <th>{{ __('lf.LF_course_category_common_slug') }}</th>
                <th>{{ __('lf.LF_course_category_common_sort_order') }}</th>
                <th>{{ __('lf.LF_course_category_common_featured') }}</th>
                <th>{{ __('lf.LF_course_category_common_status') }}</th>
                <th>{{ __('lf.LF_common_label_common_action') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($categories as $category)
                <tr>
                    <td>{{ $category->id }}</td>
                    <td>
                        @if ($category->thumbnail_media)
                            <img class="course-category-table-thumbnail"
                                 src="{{ $category->thumbnail_media->signed_url }}"
                                 alt="{{ $category->thumbnail_media->display_name }}"
                            >
                        @elseif ($category->thumbnail_image)
                            <img class="course-category-table-thumbnail"
                                 src="{{ $category->thumbnail_image }}"
                                 alt="{{ $category->name }}"
                            >
                        @else
                            {{ __('lf.LF_course_category_common_no') }}
                        @endif
                    </td>
                    <td>{{ $category->name }}</td>
                    <td>{{ $category->parent_name ?? __('lf.LF_course_category_common_root') }}</td>
                    <td>{{ $category->slug }}</td>
                    <td>{{ $category->sort_order }}</td>
                    <td>
                        {{ $category->is_featured
                            ? __('lf.LF_course_category_common_yes')
                            : __('lf.LF_course_category_common_no') }}
                    </td>
                    <td>
                        <span class="badge {{ $category->status === 'active' ? 'badge-success' : 'badge-danger' }}">
                            {{ $category->status === 'active'
                                ? __('lf.LF_course_category_common_active')
                                : __('lf.LF_course_category_common_inactive') }}
                        </span>
                    </td>
                    <td>
                        <div class="admin-table-actions">
                            <button type="button"
                                    class="admin-link-button"
                                    x-data
                                    x-on:click="$dispatch('open-modal', 'edit-course-category-{{ $category->id }}')">
                                {{ __('lf.LF_course_category_common_edit') }}
                            </button>
                            <form method="POST"
                                  action="{{ route($routePrefix.'.toggle-status', $category->id) }}">
                                @csrf
                                <button class="admin-link-button" type="submit">
                                    {{ $category->status === 'active'
                                        ? __('lf.LF_course_category_common_deactivate')
                                        : __('lf.LF_course_category_common_activate') }}
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        {{ __('lf.LF_course_category_common_empty') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @foreach ($categories as $category)
        <x-modal name="edit-course-category-{{ $category->id }}"
                 max-width="6xl"
                 :title="__('lf.LF_course_category_common_edit')"
                 focusable>
            <div class="lf-modal-card course-category-modal">
                <form method="POST"
                      action="{{ route($routePrefix.'.update', $category->id) }}"
                      enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    @include('course-categories.partials.form', [
                        'category' => $category,
                        'fieldPrefix' => 'edit-course-category-'.$category->id,
                        'parentCategories' => $category->parent_categories,
                        'thumbnailMedia' => $category->thumbnail_media,
                        'bannerMedia' => $category->banner_media,
                    ])

                    <div class="lf-modal-actions">
                        <button type="button"
                                class="btn btn-secondary"
                                x-on:click="$dispatch('close-modal', 'edit-course-category-{{ $category->id }}')">
                            {{ __('lf.LF_common_button_cancel') }}
                        </button>
                        <button type="submit" class="btn btn-primary">
                            {{ __('lf.LF_common_button_save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </x-modal>
    @endforeach
@endsection
