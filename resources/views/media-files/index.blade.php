@extends('layouts.backend')

@section('title', __('lf.LF_media_file_common_title'))
@section('page_title', __('lf.LF_media_file_common_title'))

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-row-actions.js', 'resources/js/admin-ui-kit-multiselect.js', 'resources/js/admin-ui-kit-cleanup.js'])
@endsection

@section('page_actions')
    {{-- Clean-up of orphan storage files (no longer tied to any Media): a dialog that scans first, then purges with progress.
         It sits in the heading (secondary, outline) so the filters keep one row. --}}
    @php
        $cleanupState = $cleanupStatus === null ? 'unknown' : ((int) $cleanupStatus['items'] === 0 ? 'clean' : 'dirty');
    @endphp
    {{-- A small status label beside the button; the button itself never changes. --}}
    <span class="ui-cleanup-status" data-cleanup-status data-state="{{ $cleanupState }}" @if ($cleanupState === 'unknown') hidden @endif
          data-text-clean="{{ __('lf.LF_media_cleanup_status_clean') }}" data-text-dirty="{{ __('lf.LF_media_cleanup_status_dirty', ['count' => ':count']) }}"
          role="status">{{ match ($cleanupState) {
              'clean' => __('lf.LF_media_cleanup_status_clean'),
              'dirty' => __('lf.LF_media_cleanup_status_dirty', ['count' => $cleanupStatus['items']]),
              default => '',
          } }}</span>
    <button type="button" class="ui-button ui-button--outline cursor-interaction" data-ui-cleanup-open="media-cleanup-dialog"
            aria-haspopup="dialog" aria-describedby="media-storage-purge-note"
            data-tooltip="{{ __('lf.LF_media_storage_purge_tooltip') }}" data-tooltip-placement="bottom">
        <x-backend-icon name="brush-cleaning" class="ui-icon" />
        {{ __('lf.LF_media_storage_purge_short') }}
    </button>
    <span id="media-storage-purge-note" class="ui-sr-only"><strong>{{ __('lf.LF_media_storage_purge_title') }}</strong> {{ __('lf.LF_media_storage_purge_note') }}</span>
@endsection

@section('content')
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
    $hasActiveFilters = $keyword !== null || $ownerType !== null || $usageStatus !== null;
    $selectAttributes = 'data-ui-kit-select data-ui-empty-label="'.e(__('lf.LF_media_file_select_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_media_file_common_clear_filters')).'"';
    $tabQuery = array_filter(['keyword' => $keyword, 'owner_type' => $ownerType, 'owner_refs' => $ownerRefs, 'usage_status' => $usageStatus]);
    $objectChoices = $ownerObjects[$ownerType] ?? [];
@endphp

<div id="media-list" class="lf-admin-ui lf-admin-ui--list" data-ui-row-actions
     data-csrf="{{ csrf_token() }}"
     data-msg-delete-error="{{ __('lf.LF_media_file_delete_error') }}"
     data-msg-session-expired="{{ __('lf.LF_media_file_session_expired') }}"
     x-data="{
         previewOpen: false,
         preview: { name: '', url: '', mimeType: '', mediaType: '' },
         usageOpen: false,
         usage: { name: '', items: [] },
         openMediaPreview(name, url, mimeType, mediaType) {
             this.preview = { name, url, mimeType, mediaType };
             this.previewOpen = true;
         },
         closeMediaPreview() {
             this.previewOpen = false;
             this.preview = { name: '', url: '', mimeType: '', mediaType: '' };
         },
         openUsage(name, items) {
             this.usage = { name, items };
             this.usageOpen = true;
         },
         closeUsage() {
             this.usageOpen = false;
             this.usage = { name: '', items: [] };
         },
     }">
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

    <section class="mk-section" aria-labelledby="admin-page-title">
        <nav class="mk-tabs" data-ui-live-region="tabs" aria-label="{{ __('lf.LF_media_file_common_type') }}">
            @foreach ($tabs as $availableTab)
                <a href="{{ route('admin.media.index', array_merge(['tab' => $availableTab], $tabQuery)) }}"
                   class="mk-tab cursor-interaction"
                   @if ($tab === $availableTab) aria-current="page" @endif>
                    {{ $tabLabels[$availableTab] }}
                    <span class="mk-tab-count">{{ $tabCounts[$availableTab] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>

        <form class="mk-tablebar mk-tablebar--filters ui-media-filters" method="GET" action="{{ route('admin.media.index') }}" data-ui-live-filter>
            <input type="hidden" name="tab" value="{{ $tab }}">

            <div class="ui-field mk-search">
                <label class="ui-field__label" for="keyword">{{ __('lf.LF_media_file_common_keyword') }}</label>
                <div class="ui-search">
                    <input id="keyword" type="search" name="keyword" value="{{ $keyword }}"
                           placeholder="{{ __('lf.LF_media_file_common_keyword_placeholder') }}">
                    <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                        <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                    </button>
                </div>
            </div>

            <div class="ui-field mk-search">
                <label class="ui-field__label" for="owner_type">{{ __('lf.LF_media_file_common_owner_type') }}</label>
                <select id="owner_type" name="owner_type" {!! $selectAttributes !!}>
                    <option value="">{{ __('lf.LF_media_file_common_all_owner_types') }}</option>
                    @foreach ($ownerTypeOptions as $value => $label)
                        <option value="{{ $value }}" @selected($ownerType === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ui-field mk-search">
                <span class="ui-field__label" id="owner-objects-label">{{ __('lf.LF_media_filter_objects') }}</span>
                <div class="ux-combo ui-multiselect" data-ui-multiselect data-ms-depends-on="owner_type" data-ms-name="owner_refs[]"
                     data-ms-options='@json($ownerObjects)'
                     data-text-pick-first="{{ __('lf.LF_media_filter_pick_type_first') }}"
                     data-text-all="{{ __('lf.LF_media_filter_all_objects') }}"
                     data-text-selected="{{ __('lf.LF_media_filter_selected_count') }}">
                    <button type="button" class="ux-combo__control ui-multiselect__trigger cursor-interaction" data-ms-trigger
                            aria-haspopup="true" aria-expanded="false" aria-controls="owner-objects-panel" aria-labelledby="owner-objects-label"
                            @disabled($ownerType === null)>
                        <span class="ui-multiselect__summary" data-ms-summary>{{ $ownerType === null ? __('lf.LF_media_filter_pick_type_first') : ($ownerRefs === [] ? __('lf.LF_media_filter_all_objects') : str_replace(':count', (string) count($ownerRefs), __('lf.LF_media_filter_selected_count'))) }}</span>
                        <span class="ux-combo__icon" aria-hidden="true"></span>
                    </button>
                    <div class="ux-combo__list ui-multiselect__panel" id="owner-objects-panel" data-ms-panel hidden>
                        <input type="search" data-ms-search aria-label="{{ __('lf.LF_media_filter_search') }}" placeholder="{{ __('lf.LF_media_filter_search') }}">
                        <div class="ui-check-list ui-multiselect__list" data-ms-list role="group" aria-labelledby="owner-objects-label">
                            @foreach ($objectChoices as $choice)
                                <label class="ui-check">
                                    <input type="checkbox" name="owner_refs[]" value="{{ $choice['value'] }}" @checked(in_array($choice['value'], $ownerRefs, true))>
                                    <span>{{ $choice['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="ui-field mk-search">
                <label class="ui-field__label" for="usage_status">{{ __('lf.LF_media_file_usage_status') }}</label>
                <select id="usage_status" name="usage_status" {!! $selectAttributes !!}>
                    <option value="">{{ __('lf.LF_media_file_usage_status_all') }}</option>
                    <option value="in_use" @selected($usageStatus === 'in_use')>{{ __('lf.LF_media_file_common_in_use') }}</option>
                    <option value="unused" @selected($usageStatus === 'unused')>{{ __('lf.LF_media_file_common_unused') }}</option>
                </select>
            </div>

            <span class="ui-live-slot" data-ui-live-region="clear-filters">
                @if ($hasActiveFilters)
                    <a class="ui-button ui-button--outline cursor-interaction" href="{{ route('admin.media.index', ['tab' => $tab]) }}">
                        {{ __('lf.LF_media_file_common_clear_filters') }}
                    </a>
                @endif
            </span>
        </form>

        <div class="mk-table-top" data-ui-live-region="summary">
            <strong class="mk-table-top__count" role="status">{{ trans_choice('lf.LF_media_file_count', $mediaFiles->total(), ['count' => $mediaFiles->total()]) }}</strong>
            <div class="mk-table-top__bulk" data-ui-bulk-bar hidden data-text-selected="{{ __('lf.LF_media_file_bulk_selected', ['count' => ':count']) }}">
                <span data-ui-bulk-count></span>
                <button type="button" class="ui-button ui-button--danger cursor-interaction" data-ui-bulk-delete data-ui-bulk-name="media_ids[]"
                        data-url="{{ route('admin.media.bulk-destroy', $listFilters) }}"
                        data-confirm-title="{{ __('lf.LF_media_file_bulk_delete_confirm') }}"
                        data-confirm-message="{{ __('lf.LF_media_file_bulk_delete_body', ['count' => ':count']) }}"
                        data-confirm-label="{{ __('lf.LF_media_file_common_delete') }}">
                    {{ __('lf.LF_media_file_bulk_delete') }}
                </button>
            </div>
            <span class="ui-caption mk-table-top__hint">{{ __('lf.LF_media_file_select_unused_hint') }}</span>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ __('lf.LF_media_file_common_title') }}" tabindex="0">
            <table aria-labelledby="admin-page-title" class="ui-media-table">
                <colgroup>
                    <col class="ui-col-check">
                    <col class="mk-col-sequence">
                    <col class="ui-col-media">
                    <col class="ui-col-type">
                    <col class="ui-col-upload">
                    <col class="ui-col-usage">
                    <col class="mk-col-status">
                </colgroup>
                <thead>
                <tr>
                    <th scope="col" class="ui-check-cell">
                        <input type="checkbox" class="ui-bulk-box" data-ui-bulk-select-all
                               aria-label="{{ __('lf.LF_media_file_select_all_unused') }}">
                    </th>
                    <th scope="col" class="mk-sequence admin-table-sequence">{{ __('lf.table_no') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_media_file_common_file') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_media_file_common_type_and_size') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_media_file_common_upload_information') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_media_file_common_usage') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.LF_media_file_common_status') }}</th>

                </tr>
                </thead>
                <tbody>
                @forelse ($mediaFiles as $mediaFile)
                    @php
                        $isUnused = (int) $mediaFile->usage_count === 0;
                        $usageOwnerLabels = $mediaFile->active_usages
                            ->groupBy('owner_type')
                            ->keys()
                            ->map(fn ($logicalOwnerType) => $ownerTypeOptions[$logicalOwnerType] ?? str($logicalOwnerType)->replace('_', ' ')->headline())
                            ->values();
                        $usageItems = $mediaFile->active_usages->map(fn ($usage) => [
                            'type' => (string) ($ownerTypeOptions[$usage->owner_type] ?? str($usage->owner_type)->replace('_', ' ')->headline()),
                            'name' => (string) $usage->owner_name,
                        ])->values()->all();
                    @endphp
                    <tr>
                        <td class="ui-check-cell" data-label="{{ __('lf.LF_media_file_select_label') }}">
                            @if ($isUnused)
                                <input type="checkbox" class="ui-bulk-box media-library-selection-checkbox" data-ui-bulk-item value="{{ $mediaFile->id }}"
                                       aria-label="{{ __('lf.LF_media_file_select_unused_named', ['name' => $mediaFile->display_name]) }}">
                            @else
                                {{-- A file in use cannot be deleted: the box is locked and says why. --}}
                                <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ __('lf.LF_media_file_locked_in_use', ['count' => count($usageItems)]) }}" data-tooltip-align="start">
                                    <input type="checkbox" class="ui-bulk-box" disabled aria-label="{{ __('lf.LF_media_file_locked_in_use', ['count' => count($usageItems)]) }}">
                                </span>
                            @endif
                        </td>
                        <td class="mk-sequence admin-table-sequence" data-label="{{ __('lf.table_no') }}">
                            <span class="media-library-sequence-number">{{ $mediaFiles->firstItem() + $loop->index }}</span>
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_media_file_common_file') }}">
                            <div class="ui-file-identity">
                                @if ($mediaFile->preview_mode === 'popup')
                                    <button type="button" class="ui-file-tile media-library-preview-button cursor-interaction"
                                            x-on:click="openMediaPreview(@js($mediaFile->display_name), @js($mediaFile->preview_url), @js($mediaFile->mime_type), @js($mediaFile->file_type))"
                                            aria-label="{{ __('lf.LF_media_file_preview_named', ['name' => $mediaFile->display_name]) }}">
                                        <x-media-thumbnail :presentation="$mediaFile->thumbnail_presentation" :alt="$mediaFile->display_name" />
                                        <span class="ui-file-tile__overlay media-library-preview-overlay" aria-hidden="true"><x-backend-icon name="eye" class="ui-icon" /></span>
                                    </button>
                                @elseif ($mediaFile->preview_mode === 'new_tab')
                                    <a class="ui-file-tile media-library-preview-button cursor-interaction" href="{{ $mediaFile->preview_url }}" target="_blank" rel="noopener noreferrer"
                                       aria-label="{{ __('lf.LF_media_file_preview_named', ['name' => $mediaFile->display_name]) }}">
                                        <x-media-thumbnail :presentation="$mediaFile->thumbnail_presentation" :alt="$mediaFile->display_name" />
                                        <span class="ui-file-tile__overlay media-library-preview-overlay" aria-hidden="true"><x-backend-icon name="eye" class="ui-icon" /></span>
                                    </a>
                                @else
                                    <span class="ui-file-tile">
                                        <x-media-thumbnail :presentation="$mediaFile->thumbnail_presentation" :alt="$mediaFile->display_name" />
                                    </span>
                                @endif
                                <div class="ui-file-identity__text">
                                    <span class="ui-file-title">{{ $mediaFile->display_name }}</span>
                                    <p class="ui-caption">{{ $mediaFile->original_name }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_media_file_common_type_and_size') }}">
                            {{ $typeLabels[$mediaFile->file_type] ?? __('lf.LF_media_file_common_other') }}
                            <p class="ui-caption">{{ number_format(((int) $mediaFile->file_size_bytes) / 1024, 1) }} KB</p>
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_media_file_common_upload_information') }}">
                            {{ $mediaFile->uploaded_by_name ?? '—' }}
                            <p class="ui-caption">{{ \Illuminate\Support\Carbon::parse($mediaFile->created_at)->format('d/m/Y H:i') }}</p>
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_media_file_common_usage') }}">
                            @if ($usageItems !== [])
                                <button type="button" class="ui-link-button cursor-interaction" aria-haspopup="dialog"
                                        x-on:click="openUsage(@js($mediaFile->display_name), @js($usageItems))">
                                    {{ trans_choice('lf.LF_media_file_usage_count', count($usageItems), ['count' => count($usageItems)]) }}
                                </button>
                                <p class="ui-caption">
                                    {{ $usageOwnerLabels->first() }}@if ($usageOwnerLabels->count() > 1) {{ __('lf.LF_media_file_usage_more', ['count' => $usageOwnerLabels->count() - 1]) }}@endif
                                </p>
                            @else
                                <span class="ui-caption">{{ __('lf.LF_media_file_usage_none') }}</span>
                            @endif
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.LF_media_file_common_status') }}">
                            <span @class(['ui-status', 'ui-status--active' => ! $isUnused, 'ui-status--archived' => $isUnused])>
                                {{ $isUnused ? __('lf.LF_media_file_common_unused') : __('lf.LF_media_file_common_in_use') }}
                            </span>
                            <p class="ui-caption">
                                {{ $isUnused ? __('lf.LF_media_file_status_can_delete') : trans_choice('lf.LF_media_file_usage_count', count($usageItems), ['count' => count($usageItems)]) }}
                            </p>
                        </td>
                    </tr>
                @empty
                    <tr class="mk-empty-row">
                        <td class="mk-empty" colspan="7">
                            <div role="status" class="media-library-empty-state">
                                <strong>{{ $hasActiveFilters ? __('lf.LF_media_file_filter_empty') : __('lf.LF_media_file_common_empty') }}</strong>
                                <p class="ui-caption">{{ $hasActiveFilters ? __('lf.LF_media_file_filter_empty_help') : __('lf.LF_media_file_empty_help') }}</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <x-ui-list-foot :paginator="$mediaFiles"
            :label="__('lf.LF_media_file_common_title')"
            :page-size-label="__('lf.LF_media_file_page_size')"
            :page-size-option="__('lf.LF_media_file_page_size_option', ['count' => $mediaFiles->perPage()])" />
    </section>

    <dialog class="ui-modal" id="media-cleanup-dialog" data-columns="1" aria-labelledby="media-cleanup-title" data-ui-cleanup
            data-status-url="{{ route('admin.media.cleanup-status') }}" data-scan-url="{{ route('admin.media.cleanup-scan') }}" data-run-url="{{ route('admin.media.cleanup-run') }}"
            data-csrf="{{ csrf_token() }}" data-batch="20"
            data-text-found="{{ __('lf.LF_media_cleanup_found') }}" data-text-unreadable="{{ __('lf.LF_media_cleanup_unreadable') }}"
            data-text-progress="{{ __('lf.LF_media_cleanup_progress') }}" data-text-done="{{ __('lf.LF_media_cleanup_done') }}"
            data-text-done-failed="{{ __('lf.LF_media_cleanup_done_failed') }}" data-text-error="{{ __('lf.LF_media_cleanup_error') }}">
        <div class="ui-modal__form">
            <header class="ui-modal__header">
                <div>
                    <h3 id="media-cleanup-title">{{ __('lf.LF_media_storage_purge_short') }}</h3>
                </div>
                <button type="button" class="ui-modal__close cursor-interaction" data-cleanup-close data-cleanup-x aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
            </header>
            <div class="ui-modal__body ui-cleanup" aria-live="polite">
                <div data-cleanup-step="scan" role="status" class="ui-cleanup__step">
                    <span class="ui-cleanup__icon ui-cleanup__icon--wait" aria-hidden="true">…</span>
                    <div><strong>{{ __('lf.LF_media_cleanup_scanning') }}</strong></div>
                </div>
                <div data-cleanup-step="result" hidden class="ui-cleanup__step">
                    <span class="ui-cleanup__icon ui-cleanup__icon--warn" aria-hidden="true">!</span>
                    <div>
                        <strong data-cleanup-text="found"></strong>
                        <p class="ui-caption" data-cleanup-text="unreadable" hidden></p>
                        <p class="ui-caption">{{ __('lf.LF_media_cleanup_irreversible') }}</p>
                    </div>
                </div>
                <div data-cleanup-step="empty" hidden role="status" class="ui-cleanup__step">
                    <span class="ui-cleanup__icon ui-cleanup__icon--ok" aria-hidden="true">✓</span>
                    <div>
                        <strong>{{ __('lf.LF_media_cleanup_clean') }}</strong>
                        <p class="ui-caption">{{ __('lf.LF_media_storage_purge_nothing') }}</p>
                    </div>
                </div>
                <div data-cleanup-step="run" hidden class="ui-cleanup__step ui-cleanup__step--stack">
                    <div class="ui-progress"><span class="ui-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
                                                   aria-label="{{ __('lf.LF_media_cleanup_scanning') }}" data-cleanup-bar></span></div>
                    <p class="ui-caption" data-cleanup-text="progress"></p>
                </div>
                <div data-cleanup-step="done" hidden role="status" class="ui-cleanup__step">
                    <span class="ui-cleanup__icon ui-cleanup__icon--ok" aria-hidden="true">✓</span>
                    <div><strong data-cleanup-text="done"></strong></div>
                </div>
                <div data-cleanup-step="error" hidden role="alert" class="ui-cleanup__step">
                    <span class="ui-cleanup__icon ui-cleanup__icon--bad" aria-hidden="true">×</span>
                    <div><strong data-cleanup-text="error"></strong></div>
                </div>
                <p class="ui-cleanup__note">{{ __('lf.LF_media_cleanup_intro') }}</p>
            </div>
            <footer class="ui-modal__footer">
                <button type="button" class="ui-button cursor-interaction" data-cleanup-close>{{ __('lf.LF_common_button_close') }}</button>
                <button type="button" class="ui-button cursor-interaction" data-cleanup-retry hidden>{{ __('lf.LF_media_cleanup_retry') }}</button>
                <button type="button" class="ui-button ui-button--danger cursor-interaction" data-cleanup-run hidden>{{ __('lf.LF_media_storage_purge_action') }}</button>
            </footer>
        </div>
    </dialog>

    <x-media-preview-modal ui-kit id="media-library-preview-title" show="previewOpen" close="closeMediaPreview()" title="preview.name" compact="false">
        <template x-if="previewOpen && preview.mediaType === 'image'">
            <img x-bind:src="preview.url" x-bind:alt="preview.name" class="media-library-modal-image">
        </template>
        <template x-if="previewOpen && preview.mediaType === 'video'">
            <video controls preload="metadata" autoplay x-bind:src="preview.url"></video>
        </template>
        <template x-if="previewOpen && preview.mediaType === 'audio'">
            <audio controls preload="metadata" autoplay x-bind:src="preview.url"></audio>
        </template>
    </x-media-preview-modal>

    <x-media-preview-modal ui-kit id="media-library-usage-title" show="usageOpen" close="closeUsage()" title="usage.name" compact="true">
        <h4 class="ui-modal__section-title">{{ __('lf.LF_media_file_usage_all_places') }}</h4>
        <ul class="ui-usage-list">
            <template x-for="item in usage.items" x-bind:key="item.type + item.name">
                <li><span class="ui-caption" x-text="item.type"></span> <span x-text="item.name"></span></li>
            </template>
        </ul>
    </x-media-preview-modal>
</div>
@endsection
