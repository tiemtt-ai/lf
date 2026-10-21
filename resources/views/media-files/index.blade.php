@extends('layouts.backend')

@section('title', __('lf.LF_media_file_common_title'))
@section('page_title', __('lf.LF_media_file_common_title'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-row-actions.js', 'resources/js/admin-ui-kit-multiselect.js', 'resources/js/admin-ui-kit-cleanup.js'])
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
    $hasActiveFilters = $keyword !== null || $ownerType !== null || $usageStatus !== null || $source !== null || $share !== null;
    $selectAttributes = 'data-ui-kit-select data-ui-empty-label="'.e(__('lf.LF_media_file_select_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_media_file_common_clear_filters')).'"';
    $tabQuery = array_filter(['keyword' => $keyword, 'owner_type' => $ownerType, 'owner_refs' => $ownerRefs, 'usage_status' => $usageStatus, 'source' => $source, 'share' => $share]);
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
         shareOpen: false,
         shareFor: { id: 0, name: '', meta: '' },
         shareSaving: false,
         shareUrlTemplate: @js(route($routePrefix.'.media.shares.update', ['id' => '__ID__'])),
         csrf: @js(csrf_token()),
         usageCountText: @js(trans_choice('lf.LF_media_file_usage_count', 2, ['count' => ':count'])),
         usageNoneText: @js($isTeacher ? __('lf.LF_media_usage_popup_mine_none') : __('lf.LF_media_file_usage_none')),
         usageMineText: @js(__('lf.LF_media_file_status_used_by_you', ['count' => ':count'])),
         usageOthersText: @js(__('lf.LF_media_usage_popup_others', ['count' => ':count'])),
         isTeacher: @js($isTeacher),
         shareScope: 'private',
         shareIds: [],
         shareSearch: '',
         shareNote: '',
         shareFeedback: '',
         teachers: @js($shareTeachers),
         get shareMatches() {
             const text = this.shareSearch.trim().toLocaleLowerCase();
             return this.teachers.filter(t => (t.name + ' ' + t.email + ' ' + (t.role === 'customer_admin' ? @js(__('lf.LF_media_share_role_admin')) : @js(__('lf.LF_media_share_role_teacher')))).toLocaleLowerCase().includes(text));
         },
         resetShare(state = null) {
             this.shareScope = state?.scope ?? 'private';
             this.shareIds = [...(state?.user_ids ?? [])];
             this.shareSearch = '';
             this.shareNote = state?.note ?? '';
             this.shareFeedback = '';
         },
         async saveShare() {
             if (this.shareSaving) return;
             if (this.shareScope === 'teachers' && this.shareIds.length === 0) {
                 this.shareFeedback = @js(__('lf.LF_media_share_feedback_none'));
                 return;
             }
             this.shareSaving = true;
             this.shareFeedback = '';
             try {
                 const response = await fetch(this.shareUrlTemplate.replace('__ID__', this.shareFor.id), {
                     method: 'PUT',
                     headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf },
                     credentials: 'same-origin',
                     body: JSON.stringify({ scope: this.shareScope, user_ids: this.shareScope === 'teachers' ? this.shareIds : [], note: this.shareScope === 'private' ? '' : this.shareNote }),
                 });
                 let payload = null;
                 try { payload = await response.json(); } catch (error) { payload = null; }
                 if (response.ok) {
                     // The server flashed the message: the reloaded list shows it as a toast (a toast shown now would vanish with the reload).
                     this.shareOpen = false;
                     window.location.reload();
                     return;
                 }
                 this.shareFeedback = payload?.message ?? Object.values(payload?.errors ?? {}).flat()[0] ?? @js(__('lf.LF_media_share_error_generic'));
             } catch (error) {
                 this.shareFeedback = @js(__('lf.LF_media_share_error_generic'));
             } finally {
                 this.shareSaving = false;
             }
         },
         usage: { name: '', items: [], meta: '', note: '', others: 0 },
         openMediaPreview(name, url, mimeType, mediaType) {
             this.preview = { name, url, mimeType, mediaType };
             this.previewOpen = true;
         },
         closeMediaPreview() {
             this.previewOpen = false;
             this.preview = { name: '', url: '', mimeType: '', mediaType: '' };
         },
         openUsage(name, items, meta, note, others) {
             this.usage = { name, items, meta, note: note || '', others: others || 0 };
             this.usageOpen = true;
         },
         openShare(id, name, meta, state) {
             this.shareFor = { id, name, meta };
             this.resetShare(state);
             this.shareOpen = true;
         },
         closeShare() {
             this.shareOpen = false;
         },
         get usageSubtitle() {
             const count = this.usage.items.length;
             // A teacher is told about their own use only; an admin about every place.
             const tail = this.isTeacher
                 ? (count === 0 ? this.usageNoneText : this.usageMineText.replace(':count', count))
                 : (count === 0 ? this.usageNoneText : this.usageCountText.replace(':count', count));

             return this.usage.name + ' · ' + tail;
         },
         closeUsage() {
             this.usageOpen = false;
             this.usage = { name: '', items: [], meta: '', note: '', others: 0 };
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
                <a href="{{ route($routePrefix.'.media.index', array_merge(['tab' => $availableTab], $tabQuery)) }}"
                   class="mk-tab cursor-interaction"
                   @if ($tab === $availableTab) aria-current="page" @endif>
                    {{ $tabLabels[$availableTab] }}
                    <span class="mk-tab-count">{{ $tabCounts[$availableTab] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>

        <div class="mk-tablebar mk-tablebar--filters ui-media-filters">
        <form class="mk-filter-form" method="GET" action="{{ route($routePrefix.'.media.index') }}" data-ui-live-filter>
            <input type="hidden" name="tab" value="{{ $tab }}">

            <div class="ui-field mk-search">
                <label class="ui-field__label ui-sr-only" for="keyword">{{ __('lf.LF_media_file_common_keyword') }}</label>
                <div class="ui-search">
                    <input id="keyword" type="search" name="keyword" value="{{ $keyword }}"
                           placeholder="{{ __('lf.LF_media_file_common_keyword_placeholder') }}">
                    <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                        <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                    </button>
                </div>
            </div>

            @if ($isTeacher)
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="source">{{ __('lf.LF_media_source_filter') }}</label>
                    <select id="source" name="source" {!! $selectAttributes !!}>
                        <option value="">{{ __('lf.LF_media_source_all') }}</option>
                        <option value="mine" @selected($source === 'mine')>{{ __('lf.LF_media_source_mine') }}</option>
                        <option value="mine_private" @selected($source === 'mine_private')>{{ __('lf.LF_media_source_mine_private') }}</option>
                        <option value="mine_shared" @selected($source === 'mine_shared')>{{ __('lf.LF_media_source_mine_shared') }}</option>
                        <option value="shared" @selected($source === 'shared')>{{ __('lf.LF_media_source_shared') }}</option>
                    </select>
                </div>
            @else
            <div class="ui-field mk-search">
                <label class="ui-field__label ui-sr-only" for="owner_type">{{ __('lf.LF_media_file_common_owner_type') }}</label>
                <select id="owner_type" name="owner_type" {!! $selectAttributes !!}>
                    <option value="">{{ __('lf.LF_media_file_common_all_owner_types') }}</option>
                    @foreach ($ownerTypeOptions as $value => $label)
                        <option value="{{ $value }}" @selected($ownerType === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ui-field mk-search">
                <span class="ui-field__label ui-sr-only" id="owner-objects-label">{{ __('lf.LF_media_filter_objects') }}</span>
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
                <label class="ui-field__label ui-sr-only" for="usage_status">{{ __('lf.LF_media_file_usage_status') }}</label>
                <select id="usage_status" name="usage_status" {!! $selectAttributes !!}>
                    <option value="">{{ __('lf.LF_media_file_usage_status_all') }}</option>
                    <option value="in_use" @selected($usageStatus === 'in_use')>{{ __('lf.LF_media_file_common_in_use') }}</option>
                    <option value="unused" @selected($usageStatus === 'unused')>{{ __('lf.LF_media_file_common_unused') }}</option>
                </select>
            </div>

            <div class="ui-field mk-search">
                <label class="ui-field__label ui-sr-only" for="share">{{ __('lf.LF_media_share_filter') }}</label>
                <select id="share" name="share" {!! $selectAttributes !!}>
                    <option value="">{{ __('lf.LF_media_share_filter_all') }}</option>
                    <option value="private" @selected($share === 'private')>{{ __('lf.LF_media_share_state_private') }}</option>
                    <option value="teachers" @selected($share === 'teachers')>{{ __('lf.LF_media_share_filter_teachers') }}</option>
                    <option value="all_teachers" @selected($share === 'all_teachers')>{{ __('lf.LF_media_share_state_all') }}</option>
                </select>
            </div>

            @endif

            <span class="ui-live-slot" data-ui-live-region="clear-filters">
                @if ($hasActiveFilters)
                    <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.media.index', ['tab' => $tab]) }}">
                        {{ __('lf.LF_media_file_common_clear_filters') }}
                    </a>
                @endif
            </span>
        </form>

        <x-ui-list-toolbar :url="route($routePrefix.'.media.bulk-destroy', $listFilters)" name="media_ids[]"
            :label="__('lf.LF_media_file_bulk_delete')"
            :confirm-title="__('lf.LF_media_file_bulk_delete_confirm')" :confirm-message="__('lf.LF_media_file_bulk_delete_body', ['count' => ':count'])"
            :confirm-label="__('lf.LF_common_button_confirm')">

    @unless ($isTeacher)
    {{-- Clean-up of orphan storage files (no longer tied to any Media): a dialog that scans first, then purges with progress.
         It sits in the list toolbar (secondary, outline). --}}
    @php
        $cleanupState = $cleanupStatus === null ? 'unknown' : ((int) $cleanupStatus['items'] === 0 ? 'clean' : 'dirty');
    @endphp
    <button type="button" class="ui-button ui-button--outline cursor-interaction" data-ui-cleanup-open="media-cleanup-dialog"
            aria-haspopup="dialog" aria-describedby="media-storage-purge-note"
            data-state="{{ $cleanupState }}" @disabled($cleanupState === 'clean')
            data-tooltip="{{ $cleanupState === 'clean' ? __('lf.LF_media_cleanup_tooltip_clean') : __('lf.LF_media_storage_purge_tooltip') }}"
            data-tooltip-default="{{ __('lf.LF_media_storage_purge_tooltip') }}" data-tooltip-clean="{{ __('lf.LF_media_cleanup_tooltip_clean') }}">
        <x-backend-icon name="brush-cleaning" class="ui-icon" />
        {{ __('lf.LF_media_storage_purge_short') }}
    </button>
    <span id="media-storage-purge-note" class="ui-sr-only"><strong>{{ __('lf.LF_media_storage_purge_title') }}</strong> {{ __('lf.LF_media_storage_purge_note') }}</span>
    @endunless
        </x-ui-list-toolbar>
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
                    <col class="ui-col-actions">
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
                    <th scope="col" class="mk-text-cell">{{ $isTeacher ? __('lf.LF_media_source_filter') : __('lf.LF_media_file_common_upload_information') }}</th>
                    <th scope="col" class="mk-text-cell ui-center-cell">{{ __('lf.LF_media_file_common_status') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
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
                            'type' => match ($usage->owner_type) {
                                'course_version_activity' => __('lf.LF_media_usage_type_version_activity'),
                                'course_template_version' => __('lf.LF_media_usage_type_template_version'),
                                default => (string) ($ownerTypeOptions[$usage->owner_type] ?? str($usage->owner_type)->replace('_', ' ')->headline()),
                            },
                            'name' => (string) $usage->owner_name,
                            'url' => $usage->owner_url ?? null,
                        ])->values()->all();
                    @endphp
                    <tr>
                        <td class="ui-check-cell" data-label="{{ __('lf.LF_media_file_select_label') }}">
                            @if ($isUnused && $mediaFile->is_mine)
                                <input type="checkbox" class="ui-bulk-box media-library-selection-checkbox" data-ui-bulk-item value="{{ $mediaFile->id }}"
                                       aria-label="{{ __('lf.LF_media_file_select_unused_named', ['name' => $mediaFile->display_name]) }}">
                            @else
                                {{-- A file in use cannot be deleted: the box is locked and says why. --}}
                                @php
                                    $lockReason = $mediaFile->is_mine ? __('lf.LF_media_file_locked_in_use', ['count' => count($usageItems)]) : __('lf.LF_media_file_locked_not_owner');
                                @endphp
                                <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ $lockReason }}" data-tooltip-align="start">
                                    <input type="checkbox" class="ui-bulk-box" disabled aria-label="{{ $lockReason }}">
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
                        <td class="mk-text-cell" data-label="{{ $isTeacher ? __('lf.LF_media_source_filter') : __('lf.LF_media_file_common_upload_information') }}">
                            {{-- A teacher reads this as the Media's origin: whose it is, how it reached them, and when it was uploaded. --}}
                            @if ($isTeacher && $mediaFile->is_mine)
                                {{ __('lf.LF_media_source_mine') }}
                            @elseif ($isTeacher)
                                {{-- Shared with this teacher: the icon is the only mark, and says by whom (name and account). --}}
                                <span class="mk-source-name">
                                    {{ $mediaFile->uploaded_by_name ?? '—' }}
                                    <span class="ui-source-icon" tabindex="0" data-tooltip="{{ __('lf.LF_media_source_shared_tip', ['name' => $mediaFile->uploaded_by_name ?? '—', 'email' => $mediaFile->uploaded_by_email ?? '—']) }}" data-tooltip-align="start"
                                          aria-label="{{ __('lf.LF_media_source_shared_tip', ['name' => $mediaFile->uploaded_by_name ?? '—', 'email' => $mediaFile->uploaded_by_email ?? '—']) }}">
                                        <x-backend-icon name="share-2" class="ui-icon" />
                                    </span>
                                </span>
                            @else
                                {{ $mediaFile->uploaded_by_name ?? '—' }}
                            @endif
                            <p class="ui-caption">{{ \Illuminate\Support\Carbon::parse($mediaFile->created_at)->format('d/m/Y H:i') }}</p>
                        </td>
                        <td class="mk-text-cell ui-center-cell" data-label="{{ __('lf.LF_media_file_common_status') }}">
                            @php
                                // A teacher sees their own Media as used by anyone, and a shared one as used by themselves only; either way they
                                // get a glimpse of how many other places use it (a count, never what other people built).
                                $myUse = (int) $mediaFile->my_usage_count;
                                $shownUnused = $isTeacher && ! $mediaFile->is_mine ? $myUse === 0 : $isUnused;
                                $othersUse = $isTeacher ? max(0, (int) $mediaFile->usage_count - $myUse) : 0;
                            @endphp
                            <span @class(['ui-status', 'ui-status--active' => ! $shownUnused, 'ui-status--archived' => $shownUnused])>
                                {{ $shownUnused ? __('lf.LF_media_file_common_unused') : __('lf.LF_media_file_common_in_use') }}
                            </span>
                            <p class="ui-caption">
                                @if ($isTeacher)
                                    @php
                                        $parts = array_filter([
                                            $myUse > 0 ? __('lf.LF_media_file_status_used_by_you', ['count' => $myUse]) : null,
                                            $othersUse > 0 ? __('lf.LF_media_file_status_used_by_others', ['count' => $othersUse]) : null,
                                        ]);
                                    @endphp
                                    {{ $parts !== [] ? implode(' · ', $parts) : ($mediaFile->is_mine ? __('lf.LF_media_file_status_can_delete') : __('lf.LF_media_file_status_nobody_uses')) }}
                                @else
                                    {{ $isUnused ? __('lf.LF_media_file_status_can_delete') : trans_choice('lf.LF_media_file_usage_count', count($usageItems), ['count' => count($usageItems)]) }}
                                @endif
                            </p>
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                            <button type="button" class="ux-row-edit cursor-interaction" aria-haspopup="dialog"
                                    aria-label="{{ __('lf.LF_media_file_action_uses') }}: {{ $mediaFile->display_name }}"
                                    data-tooltip="{{ __('lf.LF_media_file_action_uses') }}"
                                    x-on:click="openUsage(@js($mediaFile->display_name), @js($usageItems), @js(trim(($typeLabels[$mediaFile->file_type] ?? '').' · '.strtoupper((string) $mediaFile->extension), ' ·')), @js($mediaFile->share_note), @js($othersUse))">
                                <x-backend-icon name="link-2" class="ui-icon" />
                            </button>
                            @if ($mediaFile->is_mine)
                            <button type="button" class="ux-row-edit cursor-interaction" aria-haspopup="dialog"
                                    aria-label="{{ __('lf.LF_media_file_action_share') }}: {{ $mediaFile->display_name }}"
                                    data-tooltip="{{ __('lf.LF_media_file_action_share') }}"
                                    @if ($mediaFile->share_state['scope'] !== 'private') data-shared @endif
                                    x-on:click="openShare(@js((int) $mediaFile->id), @js($mediaFile->display_name), @js(trim(($typeLabels[$mediaFile->file_type] ?? '').' · '.strtoupper((string) $mediaFile->extension), ' ·')), @js($mediaFile->share_state))">
                                <x-backend-icon name="user-cog" class="ui-icon" />
                            </button>
                            @endif
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

    @unless ($isTeacher)
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
                <button type="button" class="ui-button ui-button--danger-outline cursor-interaction" data-cleanup-run hidden>{{ __('lf.LF_media_storage_purge_action') }}</button>
            </footer>
        </div>
    </dialog>
    @endunless

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

    <dialog class="ui-modal ui-share" data-columns="1" aria-labelledby="media-library-usage-title"
            x-effect="if (usageOpen && !$el.open) $el.showModal(); else if (!usageOpen && $el.open) $el.close()"
            @close="closeUsage()">
        <div class="ui-modal__form">
            <header class="ui-modal__header">
                <div>
                    <h3 id="media-library-usage-title">{{ __('lf.LF_media_file_uses_title') }}</h3>
                    <span class="ui-caption" x-text="usageSubtitle"></span>
                </div>
                <button type="button" class="ui-modal__close cursor-interaction" aria-label="{{ __('lf.LF_common_button_close') }}" @click="closeUsage()">×</button>
            </header>
            <div class="ui-modal__body">
                <div class="ui-note-card" x-show="usage.note" x-cloak>
                    <p class="ui-note-card__title"><x-backend-icon name="message-square" class="ui-icon" /> {{ __('lf.LF_media_share_note_short') }}</p>
                    <p class="ui-note-card__text" x-text="usage.note"></p>
                </div>
                <p class="ui-caption" x-show="usage.items.length === 0 && ! isTeacher">{{ __('lf.LF_media_file_usage_none') }}</p>
                <p class="ui-field__hint" x-show="usage.items.length === 0 && isTeacher" x-cloak>{{ __('lf.LF_media_usage_popup_mine_none_long') }}</p>
                <ul class="ui-usage-list" x-show="usage.items.length > 0">
                    <template x-for="(item, index) in usage.items" x-bind:key="index">
                        <li class="ui-usage-list__item">
                            <x-backend-icon name="book-open" class="ui-icon ui-usage-list__lead" />
                            <div class="ui-usage-list__text"><span x-text="item.name"></span><div class="ui-caption" x-text="item.type"></div></div>
                            <template x-if="item.url">
                                <a class="ux-row-edit cursor-interaction" x-bind:href="item.url" x-bind:target="'_blank'" x-bind:rel="'noopener'" data-tooltip="{{ __('lf.LF_media_file_usage_open') }}" x-bind:aria-label="@js(__('lf.LF_media_file_usage_open')) + ': ' + item.name"><x-backend-icon name="external-link" class="ui-icon" /></a>
                            </template>
                        </li>
                    </template>
                </ul>
                <p class="ui-caption ui-usage-others" x-show="isTeacher && usage.others > 0" x-cloak x-text="usageOthersText.replace(':count', usage.others)"></p>
            </div>
            <footer class="ui-modal__footer">
                <button type="button" class="ui-button cursor-interaction" @click="closeUsage()">{{ __('lf.LF_common_button_close') }}</button>
            </footer>
        </div>
    </dialog>

    <dialog class="ui-modal ui-share" data-columns="1" aria-labelledby="media-library-share-title"
            x-effect="if (shareOpen && !$el.open) $el.showModal(); else if (!shareOpen && $el.open) $el.close()"
            @close="closeShare()">
        <div class="ui-modal__form">
            <header class="ui-modal__header">
                <div>
                    <h3 id="media-library-share-title">{{ __('lf.LF_media_share_title') }}</h3>
                    <span class="ui-caption"><span x-text="shareFor.name"></span><span x-show="shareFor.meta"> · <span x-text="shareFor.meta"></span></span></span>
                </div>
                <button type="button" class="ui-modal__close cursor-interaction" aria-label="{{ __('lf.LF_common_button_close') }}" @click="closeShare()">×</button>
            </header>
            <div class="ui-modal__body">
                <div class="ui-share__stack">
                    <div class="ui-field">
                        <label class="ui-field__label" for="media-share-scope">{{ __('lf.LF_media_share_with') }}</label>
                        <select id="media-share-scope" x-model="shareScope">
                            <option value="private">{{ __('lf.LF_media_share_scope_private') }}</option>
                            <option value="teachers">{{ __('lf.LF_media_share_scope_teachers') }}</option>
                            <option value="all_teachers">{{ __('lf.LF_media_share_scope_public_option') }}</option>
                        </select>
                    </div>
                    <div x-show="shareScope === 'teachers'">
                        <div class="ui-field">
                            <label class="ui-field__label" for="media-share-search">{{ __('lf.LF_media_share_pick_teachers') }}</label>
                            <input id="media-share-search" type="search" x-model="shareSearch" placeholder="{{ __('lf.LF_media_share_search_placeholder') }}" autocomplete="off">
                        </div>
                        <p class="ui-caption" x-show="teachers.length === 0">{{ __('lf.LF_media_share_no_teachers') }}</p>
                        <div class="ui-share__people">
                            <template x-for="person in shareMatches" x-bind:key="person.id">
                                <label class="ui-share__person ui-check cursor-interaction">
                                    <input type="checkbox" x-bind:value="person.id" x-model.number="shareIds">
                                    <span class="ui-share__copy"><span x-text="person.name"></span><span class="ui-caption"><span x-text="person.role === 'customer_admin' ? @js(__('lf.LF_media_share_role_admin')) : @js(__('lf.LF_media_share_role_teacher'))"></span> · <span x-text="person.email"></span></span></span>
                                </label>
                            </template>
                        </div>
                        <p class="ui-caption" aria-live="polite" x-text="@js(__('lf.LF_media_share_selected')) + ' ' + shareIds.length"></p>
                    </div>
                    <p class="ui-caption" x-show="shareScope === 'all_teachers'">{{ __('lf.LF_media_share_public_note') }}</p>
                    <div class="ui-field" x-show="shareScope !== 'private'">
                        <label class="ui-field__label" for="media-share-note">{{ __('lf.LF_media_share_note_label') }}</label>
                        <textarea id="media-share-note" rows="3" maxlength="300" x-model="shareNote" placeholder="{{ __('lf.LF_media_share_note_placeholder') }}"></textarea>
                        <span class="ui-field__hint"><span x-text="shareNote.length"></span>/300 · {{ __('lf.LF_media_share_note_hint') }}</span>
                    </div>
                </div>
            </div>
            <footer class="ui-modal__footer">
                <span class="ui-caption" role="status" aria-live="polite" x-text="shareFeedback"></span>
                <button type="button" class="ui-button cursor-interaction" @click="closeShare()">{{ __('lf.LF_media_share_cancel') }}</button>
                <button type="button" class="ui-button ui-button--primary cursor-interaction" @click="saveShare()" x-bind:disabled="shareSaving"><x-backend-icon name="check" class="ui-icon" />{{ __('lf.LF_common_button_confirm') }}</button>
            </footer>
        </div>
    </dialog>
</div>
@endsection
