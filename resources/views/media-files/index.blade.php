@extends('layouts.backend')

@section('title', __('lf.LF_media_file_common_title'))
@section('page_title', __('lf.LF_media_file_common_title'))

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

    @php
        $tabLabels = [
            'all' => __('lf.LF_media_file_common_tab_all'),
            'images' => __('lf.LF_media_file_common_tab_images'),
            'videos' => __('lf.LF_media_file_common_tab_videos'),
            'documents' => __('lf.LF_media_file_common_tab_documents'),
            'audio' => __('lf.LF_media_file_common_tab_audio'),
        ];
        $typeLabels = [
            'image' => __('lf.LF_media_file_common_tab_images'),
            'video' => __('lf.LF_media_file_common_tab_videos'),
            'document' => __('lf.LF_media_file_common_tab_documents'),
            'audio' => __('lf.LF_media_file_common_tab_audio'),
        ];
    @endphp

    <div class="admin-tabs media-library-tabs">
        @foreach ($tabs as $availableTab)
            <a href="{{ route('admin.media.index', array_filter([
                'tab' => $availableTab,
                'type' => $type,
                'owner_type' => $ownerType,
                'usage_type' => $usageType,
            ])) }}"
               class="admin-tab {{ $tab === $availableTab ? 'is-active' : '' }}"
               @if ($tab === $availableTab) aria-current="page" @endif>
                {{ $tabLabels[$availableTab] }}
                <span class="media-library-tab-count">{{ $tabCounts[$availableTab] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    <div class="admin-card admin-form-card">
        <form method="GET" action="{{ route('admin.media.index') }}">
            <input type="hidden" name="tab" value="{{ $tab }}">

            <div class="media-library-filter-grid">
                <div class="lf-form-group">
                    <label class="lf-form-label" for="type">
                        {{ __('lf.LF_media_file_common_type') }}
                    </label>
                    <select id="type" name="type" class="lf-form-control">
                        <option value="">{{ __('lf.LF_media_file_common_all_types') }}</option>
                        @foreach ($typeLabels as $value => $label)
                            <option value="{{ $value }}" @selected($type === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="owner_type">
                        {{ __('lf.LF_media_file_common_owner_type') }}
                    </label>
                    <select id="owner_type" name="owner_type" class="lf-form-control">
                        <option value="">{{ __('lf.LF_media_file_common_all_owner_types') }}</option>
                        @foreach ($ownerTypeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($ownerType === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="usage_type">
                        {{ __('lf.LF_media_file_common_usage_type') }}
                    </label>
                    <select id="usage_type" name="usage_type" class="lf-form-control">
                        <option value="">{{ __('lf.LF_media_file_common_all_usage_types') }}</option>
                        @foreach ($usageTypeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($usageType === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_common_button_search') }}
                </button>
                <a href="{{ route('admin.media.index') }}">
                    {{ __('lf.LF_media_file_common_clear_filters') }}
                </a>
            </div>
        </form>
    </div>

    <div class="admin-table-wrap media-library-table-wrap">
        <table class="table media-library-table">
            <thead>
            <tr>
                <th>{{ __('lf.LF_media_file_common_preview') }}</th>
                <th>{{ __('lf.LF_media_file_common_file_name') }}</th>
                <th>{{ __('lf.LF_media_file_common_type') }}</th>
                <th>{{ __('lf.LF_media_file_common_size') }}</th>
                <th>{{ __('lf.LF_media_file_common_uploaded_by') }}</th>
                <th>{{ __('lf.LF_media_file_common_upload_date') }}</th>
                <th>{{ __('lf.LF_media_file_common_usage_count') }}</th>
                <th>{{ __('lf.LF_media_file_common_used_by') }}</th>
                <th>{{ __('lf.LF_common_label_common_action') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($mediaFiles as $mediaFile)
                <tr>
                    <td>
                        <div class="media-library-preview">
                            @if ($mediaFile->preview_url)
                                <img src="{{ $mediaFile->preview_url }}" alt="{{ $mediaFile->display_name }}">
                            @else
                                <span>{{ str($mediaFile->file_type)->headline() }}</span>
                            @endif
                        </div>
                    </td>
                    <td>
                        <div class="media-library-file-name">{{ $mediaFile->display_name }}</div>
                        <div class="media-library-file-meta">{{ $mediaFile->original_name }}</div>
                        @if ($mediaFile->preview_url)
                            <a href="{{ $mediaFile->preview_url }}" target="_blank" rel="noopener">
                                {{ __('lf.LF_media_file_common_view') }}
                            </a>
                        @endif
                    </td>
                    <td>
                        <span class="badge">{{ str($mediaFile->file_type)->headline() }}</span>
                    </td>
                    <td>{{ number_format(((int) $mediaFile->file_size_bytes) / 1024, 1) }} KB</td>
                    <td>{{ $mediaFile->uploaded_by_name ?? '-' }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($mediaFile->created_at)->format('Y-m-d H:i') }}</td>
                    <td>{{ $mediaFile->usage_count }}</td>
                    <td>
                        @if ($mediaFile->active_usages->isNotEmpty())
                            <div class="media-library-used-by">
                                @foreach ($mediaFile->active_usages->take(3) as $usage)
                                    <div>
                                        <strong>{{ str($usage->owner_type)->replace('_', ' ')->headline() }}</strong>
                                        <span>{{ $usage->owner_name }}</span>
                                        <em>{{ str($usage->usage_type)->replace('_', ' ')->headline() }}</em>
                                    </div>
                                @endforeach

                                @if ($mediaFile->active_usages->count() > 3)
                                    <span class="media-library-file-meta">
                                        +{{ $mediaFile->active_usages->count() - 3 }}
                                    </span>
                                @endif
                            </div>
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        @if ((int) $mediaFile->usage_count === 0)
                            <div class="admin-table-actions">
                                <form method="POST" action="{{ route('admin.media.destroy', $mediaFile->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="admin-link-button" type="submit"
                                            onclick="return confirm('{{ __('lf.LF_media_file_common_delete_confirm') }}')">
                                        {{ __('lf.LF_media_file_common_delete') }}
                                    </button>
                                </form>
                            </div>
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" style="text-align: center">
                        {{ __('lf.LF_media_file_common_empty') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
