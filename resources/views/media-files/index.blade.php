@extends('layouts.backend')

@section('title', __('lf.LF_media_file_common_title'))
@section('page_title', __('lf.LF_media_file_common_title'))

@section('content')
    @if (session('success'))
        <div class="admin-alert admin-alert-success" role="status">
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
        $hasActiveFilters = $ownerType !== null || $usageType !== null || $usageStatus !== null;
        $unusedMediaIds = $mediaFiles->getCollection()
            ->filter(fn (object $mediaFile): bool => (int) $mediaFile->usage_count === 0)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    @endphp

    <div class="admin-tabs media-library-tabs">
        @foreach ($tabs as $availableTab)
            <a href="{{ route('admin.media.index', array_filter([
                'tab' => $availableTab,
                'owner_type' => $ownerType,
                'usage_type' => $usageType,
                'usage_status' => $usageStatus,
            ])) }}"
               class="admin-tab {{ $tab === $availableTab ? 'is-active' : '' }}"
               @if ($tab === $availableTab) aria-current="page" @endif>
                {{ $tabLabels[$availableTab] }}
                <span class="media-library-tab-count">{{ $tabCounts[$availableTab] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    <div class="admin-card admin-form-card media-library-filter-card">
        <form class="media-library-filter-form" method="GET" action="{{ route('admin.media.index') }}">
            <input type="hidden" name="tab" value="{{ $tab }}">

            <div class="media-library-filter-grid">
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

                <div class="lf-form-group">
                    <label class="lf-form-label" for="usage_status">
                        {{ __('lf.LF_media_file_usage_status') }}
                    </label>
                    <select id="usage_status" name="usage_status" class="lf-form-control">
                        <option value="">{{ __('lf.LF_media_file_usage_status_all') }}</option>
                        <option value="in_use" @selected($usageStatus === 'in_use')>
                            {{ __('lf.LF_media_file_common_in_use') }}
                        </option>
                        <option value="unused" @selected($usageStatus === 'unused')>
                            {{ __('lf.LF_media_file_common_unused') }}
                        </option>
                    </select>
                </div>

                <div class="admin-form-actions media-library-filter-actions">
                    <button type="submit" class="btn btn-primary">
                        {{ __('lf.LF_common_button_search') }}
                    </button>
                    @if ($hasActiveFilters)
                        <a class="admin-text-action" href="{{ route('admin.media.index', ['tab' => $tab]) }}">
                            {{ __('lf.LF_media_file_common_clear_filters') }}
                        </a>
                    @endif
                </div>
            </div>
        </form>
    </div>

    <div class="media-library-page"
         x-data="{
             previewOpen: false,
             previewLoaded: false,
             videoSrc: '',
             audioSrc: '',
             preview: {
                 name: '',
                 url: '',
                 mimeType: '',
                 mediaType: '',
             },
             deleteOpen: false,
             deleteSubmitting: false,
             deleteTarget: {
                 name: '',
                 action: '',
                 ids: [],
                 bulk: false,
             },
             selectedMediaIds: [],
             availableUnusedIds: @js($unusedMediaIds),
             get allUnusedSelected() {
                 return this.availableUnusedIds.length > 0
                     && this.availableUnusedIds.every((id) => this.selectedMediaIds.includes(id));
             },
             toggleAllUnused(checked) {
                 this.selectedMediaIds = checked ? [...this.availableUnusedIds] : [];
             },
             openMediaPreview(name, url, mimeType, mediaType) {
                 this.resetMediaPreview();
                 this.preview = { name, url: ['image', 'audio'].includes(mediaType) ? url : '', mimeType, mediaType };
                 this.previewLoaded = true;

                 if (mediaType === 'video') {
                     this.videoSrc = url;
                     this.$refs.videoPreviewSource?.setAttribute('src', url);
                     this.$refs.videoPreviewPlayer?.load();
                 }

                 if (mediaType === 'audio') {
                     this.audioSrc = url;
                 }

                 this.previewOpen = true;
                 this.$nextTick(() => {
                     if (mediaType === 'video') {
                         this.playVideoPreview();
                     }
                 });
             },
             playVideoPreview() {
                 const player = this.$refs.videoPreviewPlayer;

                 if (! player) {
                     return;
                 }

                 player.muted = false;
                 const playAttempt = player.play();

                 if (playAttempt !== undefined) {
                     playAttempt.catch(() => {
                         player.muted = true;
                         player.play().catch(() => {});
                     });
                 }
             },
             resetMediaPreview() {
                 const player = this.$refs.videoPreviewPlayer;
                 const audioPlayer = this.$refs.audioPreviewPlayer;

                 if (player) {
                     player.pause();
                     player.muted = false;
                     player.removeAttribute('src');
                     player.querySelectorAll('source').forEach((source) => {
                         source.removeAttribute('src');
                     });
                     player.load();
                 }

                 if (audioPlayer) {
                     audioPlayer.pause();
                     audioPlayer.removeAttribute('src');
                     audioPlayer.load();
                 }

                 this.previewLoaded = false;
                 this.videoSrc = '';
                 this.audioSrc = '';
                 this.preview = { name: '', url: '', mimeType: '', mediaType: '' };
             },
             closeMediaPreview() {
                 this.resetMediaPreview();
                 this.previewOpen = false;
             },
             openMediaDelete(name, action, ids = []) {
                 this.deleteTarget = { name, action, ids, bulk: ids.length > 0 };
                 this.deleteSubmitting = false;
                 this.deleteOpen = true;
                 this.$nextTick(() => this.$refs.deleteCancel?.focus());
             },
             openBulkMediaDelete() {
                 if (this.selectedMediaIds.length === 0) {
                     return;
                 }

                 this.openMediaDelete(
                     '',
                     @js(route('admin.media.bulk-destroy')),
                     [...this.selectedMediaIds]
                 );
             },
             closeMediaDelete() {
                 if (this.deleteSubmitting) {
                     return;
                 }

                 this.deleteOpen = false;
                 this.deleteTarget = { name: '', action: '', ids: [], bulk: false };
             },
             handleEscape() {
                 if (this.deleteOpen) {
                     this.closeMediaDelete();
                     return;
                 }

                 if (this.previewOpen) {
                     this.closeMediaPreview();
                 }
             },
         }"
         x-on:keydown.escape.window="handleEscape()">
    <div class="media-library-bulk-toolbar"
         x-cloak
         x-show="selectedMediaIds.length > 0"
         x-transition.opacity
         role="region"
         aria-live="polite">
        <span x-text="@js(__('lf.LF_media_file_bulk_selected', ['count' => ':count'])).replace(':count', selectedMediaIds.length)"></span>
        <button type="button"
                class="btn btn-danger"
                x-on:click="openBulkMediaDelete()">
            {{ __('lf.LF_media_file_bulk_delete') }}
        </button>
    </div>

    <div class="admin-table-wrap media-library-table-wrap media-library-index-table-wrap">
        <table class="table media-library-table media-library-index-table">
            <thead>
            <tr>
                <th class="admin-table-sequence">
                    <div class="media-library-sequence-heading">
                        @if ($unusedMediaIds !== [])
                            <input type="checkbox"
                                   class="media-library-selection-checkbox"
                                   x-bind:checked="allUnusedSelected"
                                   x-on:change="toggleAllUnused($event.target.checked)"
                                   aria-label="{{ __('lf.LF_media_file_select_all_unused') }}">
                        @else
                            <span class="media-library-selection-placeholder" aria-hidden="true"></span>
                        @endif
                        <span>{{ __('lf.table_no') }}</span>
                    </div>
                </th>
                <th>{{ __('lf.LF_media_file_common_file') }}</th>
                <th>{{ __('lf.LF_media_file_common_type_and_size') }}</th>
                <th>{{ __('lf.LF_media_file_common_upload_information') }}</th>
                <th>{{ __('lf.LF_media_file_common_usage') }}</th>
                <th class="media-library-index-actions">{{ __('lf.LF_media_file_common_status') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($mediaFiles as $mediaFile)
                <tr>
                    <td class="admin-table-sequence" data-label="{{ __('lf.table_no') }}">
                        <div class="media-library-sequence-cell">
                            @if ((int) $mediaFile->usage_count === 0)
                                <input type="checkbox"
                                       class="media-library-selection-checkbox"
                                       value="{{ $mediaFile->id }}"
                                       x-model.number="selectedMediaIds"
                                       aria-label="{{ __('lf.LF_media_file_select_unused_named', ['name' => $mediaFile->display_name]) }}">
                            @else
                                <span class="media-library-selection-placeholder" aria-hidden="true"></span>
                            @endif
                            <span class="media-library-sequence-number">{{ $mediaFiles->firstItem() + $loop->index }}</span>
                        </div>
                    </td>
                    <td data-label="{{ __('lf.LF_media_file_common_file') }}">
                        <div class="media-library-media-cell">
                            <div class="media-library-preview-cell">
                                @if ($mediaFile->preview_mode === 'popup')
                                    <button type="button"
                                            class="media-library-preview media-library-preview-button"
                                            x-on:click="openMediaPreview(
                                                @js($mediaFile->display_name),
                                                @js($mediaFile->preview_url),
                                                @js($mediaFile->mime_type),
                                                @js($mediaFile->file_type)
                                            )"
                                            aria-label="{{ __('lf.LF_media_file_preview_named', ['name' => $mediaFile->display_name]) }}">
                                        <x-media-thumbnail :presentation="$mediaFile->thumbnail_presentation" :alt="$mediaFile->display_name" />
                                        <span class="media-library-preview-overlay" aria-hidden="true">
                                            <x-backend-icon name="eye" />
                                        </span>
                                    </button>
                                @elseif ($mediaFile->preview_mode === 'new_tab')
                                    <a class="media-library-preview media-library-preview-button"
                                       href="{{ $mediaFile->preview_url }}"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       aria-label="{{ __('lf.LF_media_file_preview_named', ['name' => $mediaFile->display_name]) }}">
                                        <x-media-thumbnail :presentation="$mediaFile->thumbnail_presentation" :alt="$mediaFile->display_name" />
                                        <span class="media-library-preview-overlay" aria-hidden="true">
                                            <x-backend-icon name="eye" />
                                        </span>
                                    </a>
                                @else
                                    <div class="media-library-preview">
                                        <x-media-thumbnail :presentation="$mediaFile->thumbnail_presentation" :alt="$mediaFile->display_name" />
                                    </div>
                                @endif
                            </div>
                            <div class="media-library-file-information">
                                <div class="media-library-file-name">{{ $mediaFile->display_name }}</div>
                                <div class="media-library-file-meta">{{ $mediaFile->original_name }}</div>
                            </div>
                        </div>
                    </td>
                    <td data-label="{{ __('lf.LF_media_file_common_type_and_size') }}">
                        <span class="media-library-type-label">{{ $typeLabels[$mediaFile->file_type] ?? __('lf.LF_media_file_common_other') }}</span>
                        <span class="media-library-file-meta media-library-size-meta">{{ number_format(((int) $mediaFile->file_size_bytes) / 1024, 1) }} KB</span>
                    </td>
                    <td data-label="{{ __('lf.LF_media_file_common_upload_information') }}">
                        {{ $mediaFile->uploaded_by_name ?? '—' }}
                        <span class="media-library-file-meta media-library-upload-date">{{ \Illuminate\Support\Carbon::parse($mediaFile->created_at)->format('d/m/Y H:i') }}</span>
                    </td>
                    <td data-label="{{ __('lf.LF_media_file_common_usage') }}">
                        <span class="media-library-usage-count">
                            {{ trans_choice('lf.LF_media_file_usage_count', $mediaFile->active_usages->count(), ['count' => $mediaFile->active_usages->count()]) }}
                        </span>
                        @if ($mediaFile->active_usages->isNotEmpty())
                            <div class="media-library-usage-summary">
                                @foreach ($mediaFile->active_usages->groupBy('owner_type')->keys() as $logicalOwnerType)
                                    <div class="media-library-usage-summary-item">
                                        <span>{{ $ownerTypeOptions[$logicalOwnerType] ?? str($logicalOwnerType)->replace('_', ' ')->headline() }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </td>
                    <td class="media-library-index-actions" data-label="{{ __('lf.LF_media_file_common_status') }}">
                        @if ((int) $mediaFile->usage_count === 0)
                            <div class="media-library-status-stack">
                                <span class="media-library-status-badge media-library-status-badge--unused">
                                    {{ __('lf.LF_media_file_common_unused') }}
                                </span>
                                <button class="admin-link-button admin-danger-text-action"
                                        type="button"
                                        data-delete-action="{{ route('admin.media.destroy', $mediaFile->id) }}"
                                        x-on:click="openMediaDelete(
                                            @js($mediaFile->display_name),
                                            $el.dataset.deleteAction
                                        )">
                                    {{ __('lf.LF_media_file_common_delete') }}
                                </button>
                            </div>
                        @else
                            <span class="media-library-status-badge media-library-status-badge--in-use">
                                {{ __('lf.LF_media_file_common_in_use') }}
                            </span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr class="media-library-empty-row">
                    <td colspan="6" class="media-library-empty-cell">
                        <div class="media-library-empty-state" role="status">
                            <strong>{{ $hasActiveFilters ? __('lf.LF_media_file_filter_empty') : __('lf.LF_media_file_common_empty') }}</strong>
                            <span>{{ $hasActiveFilters ? __('lf.LF_media_file_filter_empty_help') : __('lf.LF_media_file_empty_help') }}</span>
                            @if ($hasActiveFilters)
                                <a class="admin-text-action" href="{{ route('admin.media.index', ['tab' => $tab]) }}">
                                    {{ __('lf.LF_media_file_common_clear_filters') }}
                                </a>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($mediaFiles->hasPages())
        <div class="admin-pagination">
            {{ $mediaFiles->links() }}
        </div>
    @endif

    <div class="admin-modal-backdrop"
         x-cloak
         x-show="deleteOpen"
         x-transition.opacity
         x-on:click.self="closeMediaDelete()">
        <section class="admin-modal media-library-delete-modal"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="media-library-delete-title"
                 aria-describedby="media-library-delete-description"
                 x-bind:aria-busy="deleteSubmitting">
            <header class="admin-modal-header">
                <div>
                    <span class="media-library-delete-icon" aria-hidden="true">!</span>
                    <h2 id="media-library-delete-title">
                        <span x-show="! deleteTarget.bulk">{{ __('lf.LF_media_file_common_delete_confirm') }}</span>
                        <span x-cloak x-show="deleteTarget.bulk">{{ __('lf.LF_media_file_bulk_delete_confirm') }}</span>
                    </h2>
                </div>
                <button type="button"
                        class="media-library-delete-close"
                        x-on:click="closeMediaDelete()"
                        x-bind:disabled="deleteSubmitting"
                        aria-label="{{ __('lf.LF_common_button_close') }}">
                    <span aria-hidden="true">×</span>
                </button>
            </header>

            <form method="POST"
                  x-bind:action="deleteTarget.action"
                  x-on:submit="deleteSubmitting = true">
                @csrf
                @method('DELETE')

                <template x-for="mediaId in deleteTarget.ids" x-bind:key="mediaId">
                    <input type="hidden" name="media_ids[]" x-bind:value="mediaId">
                </template>

                <div class="media-library-delete-body">
                    <p id="media-library-delete-description"
                       x-show="! deleteTarget.bulk"
                       x-text="@js(__('lf.LF_media_file_delete_confirm_body', ['name' => ':name'])).replace(':name', deleteTarget.name)"></p>
                    <p x-cloak
                       x-show="deleteTarget.bulk"
                       x-text="@js(__('lf.LF_media_file_bulk_delete_body', ['count' => ':count'])).replace(':count', deleteTarget.ids.length)"></p>
                    <strong>{{ __('lf.LF_media_file_delete_confirm_warning') }}</strong>
                </div>

                <footer class="admin-form-footer" data-actions-align="end">
                    <div class="admin-form-footer-primary">
                        <button x-ref="deleteCancel"
                                type="button"
                                class="btn btn-secondary"
                                x-on:click="closeMediaDelete()"
                                x-bind:disabled="deleteSubmitting">
                            {{ __('lf.LF_common_button_cancel') }}
                        </button>
                        <button type="submit"
                                class="btn btn-danger"
                                x-bind:disabled="deleteSubmitting">
                            <span x-show="! deleteSubmitting">{{ __('lf.LF_media_file_common_delete') }}</span>
                            <span x-cloak x-show="deleteSubmitting">{{ __('lf.LF_media_file_delete_processing') }}</span>
                        </button>
                    </div>
                </footer>
            </form>
        </section>
    </div>

    <div class="media-library-modal"
         x-cloak
         x-show="previewOpen"
         x-transition.opacity
         role="dialog"
         aria-modal="true"
         aria-labelledby="media-library-preview-title">
        <button type="button"
                class="media-library-modal-backdrop"
                aria-label="{{ __('lf.LF_common_button_cancel') }}"
                x-on:click="closeMediaPreview()"></button>

        <div class="media-library-modal-panel">
            <div class="media-library-modal-header">
                <h2 id="media-library-preview-title"
                    x-text="preview.name"></h2>
                <button type="button"
                        class="admin-link-button admin-text-action"
                        x-on:click="closeMediaPreview()">
                    {{ __('lf.LF_common_button_cancel') }}
                </button>
            </div>

            <div class="media-library-modal-body">
                <template x-if="previewLoaded && preview.mediaType === 'image'">
                    <img x-bind:src="preview.url"
                         x-bind:alt="preview.name"
                         class="media-library-modal-image">
                </template>

                <video x-ref="videoPreviewPlayer"
                       x-show="previewLoaded && preview.mediaType === 'video'"
                       controls
                       preload="metadata"
                       class="media-library-modal-video">
                    <source x-ref="videoPreviewSource"
                            x-bind:src="videoSrc"
                            x-bind:type="preview.mimeType">
                </video>

                <audio x-ref="audioPreviewPlayer"
                       x-show="previewLoaded && preview.mediaType === 'audio'"
                       x-bind:src="audioSrc"
                       controls
                       preload="metadata"
                       class="media-library-modal-audio"></audio>
            </div>
        </div>
    </div>
    </div>
@endsection
