<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('lf.LF_liveclass_whiteboard_title') }} · {{ $context->sessionTitle }}</title>
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/css/liveclass-whiteboard.css', 'resources/js/liveclass-whiteboard/index.js'])
</head>
@php
    $t = fn (string $key, array $replace = []) => __('lf.LF_liveclass_whiteboard_'.$key, $replace);
    $strings = collect([
        'materials_total', 'pages', 'kind_pdf', 'kind_document', 'kind_video', 'kind_embed', 'kind_audio', 'opens_new_tab',
        'kind_converted', 'preview_processing', 'preview_failed',
        'resume_body', 'load_failed', 'too_large', 'too_large_local', 'invalid_pdf', 'storage_full', 'session_expired', 'ended', 'loading',
        'fullscreen', 'exit_fullscreen', 'reset_view',
        'sync_connecting', 'sync_connected', 'sync_disconnected', 'sync_off', 'sync_presenting', 'sync_waiting', 'sync_following',
        'sync_tap_play', 'sync_snapshot_too_large', 'sync_send_failed', 'revoked',
        'listen_room', 'listen_remote', 'listen_room_help', 'listen_remote_help', 'sound_blocked', 'enable_sound_help', 'close_video', 'close_audio', 'preview',
    ])->mapWithKeys(fn (string $key) => [$key => __('lf.LF_liveclass_whiteboard_'.$key)])->all();
    $tools = [
        ['key' => 'laser', 'icon' => 'laser'],
        ['key' => 'move', 'icon' => 'hand'],
        ['key' => 'pen', 'icon' => 'pen'],
        ['key' => 'highlighter', 'icon' => 'highlighter'],
        ['key' => 'eraser', 'icon' => 'eraser'],
        ['key' => 'note', 'icon' => 'sticky-note'],
    ];
    // Same six pen colours as AllVia, in its order; board-engine.js PEN_COLORS must match.
    $colors = collect(['blue' => '#2755ea', 'red' => '#e53935', 'green' => '#43a047', 'orange' => '#fb8c00', 'purple' => '#8e24aa', 'black' => '#333333'])->map(fn (string $value, string $key) => ['key' => $key, 'value' => $value, 'label' => $t('color_'.$key)])->values()->all();
@endphp
<body class="lf-wb-body">
<script type="application/json" id="lf-wb-config">@json($config + ['strings' => $strings, 'colors' => $colors])</script>

<div class="lf-admin-ui lf-wb" x-data="lfWhiteboard" x-bind:data-tool="tool" x-bind:class="{ 'has-document': material.kind === 'pdf', 'is-drawer-open': drawerOpen, 'is-viewing': isViewing }">
    <header class="lf-wb-top">
        <div class="lf-wb-top__group lf-wb-top__group--start">
            <button type="button" class="lf-wb-btn lf-wb-btn--text" x-on:click="leave()" data-tooltip="{{ $t('back') }}" aria-label="{{ $t('back') }}" data-tooltip-placement="bottom-start">
                <x-backend-icon name="arrow-left" class="ui-icon" /><span>{{ $t('back') }}</span>
            </button>
            <label class="lf-wb-classname">
                <span class="ui-sr-only">{{ $t('class_name') }}</span>
                <input type="text" name="class_name" maxlength="120" x-model="className" x-bind:readonly="isViewing" title="{{ $t('class_name') }}">
            </label>
            <span class="lf-wb-session" title="{{ $context->sessionTitle }}">{{ $context->sessionTitle }}</span>
        </div>

        <div class="lf-wb-top__group" role="toolbar" aria-label="{{ $t('title') }}">
            <button type="button" class="lf-wb-btn" x-on:click="undo()" x-bind:disabled="! canUndo" data-tooltip="{{ $t('undo') }}" aria-label="{{ $t('undo') }}"><x-backend-icon name="undo-2" class="ui-icon" /></button>
            <button type="button" class="lf-wb-btn" x-on:click="redo()" x-bind:disabled="! canRedo" data-tooltip="{{ $t('redo') }}" aria-label="{{ $t('redo') }}"><x-backend-icon name="redo-2" class="ui-icon" /></button>
            <button type="button" class="lf-wb-btn" x-on:click="clearPage()" x-bind:disabled="! hasPageContent" data-tooltip="{{ $t('clear_page') }}" aria-label="{{ $t('clear_page') }}"><x-backend-icon name="brush-cleaning" class="ui-icon" /></button>
            <button type="button" class="lf-wb-btn" x-on:click="askClearAll()" x-bind:disabled="! hasAnyContent" data-tooltip="{{ $t('clear_all') }}" aria-label="{{ $t('clear_all') }}"><x-backend-icon name="trash-2" class="ui-icon" /></button>
            <span class="lf-wb-sep" aria-hidden="true"></span>
            <button type="button" class="lf-wb-btn" data-wb-phone-hidden x-on:click="zoom(1 / 1.2)" data-tooltip="{{ $t('zoom_out') }}" aria-label="{{ $t('zoom_out') }}"><x-backend-icon name="zoom-out" class="ui-icon" /></button>
            <button type="button" class="lf-wb-btn lf-wb-btn--value" x-on:click="resetView()" data-tooltip="{{ $t('reset_view') }}" x-bind:aria-label="strings.reset_view + ' (' + zoomPercent + '%)'"><span x-text="zoomPercent + '%'"></span></button>
            <button type="button" class="lf-wb-btn" data-wb-phone-hidden x-on:click="zoom(1.2)" data-tooltip="{{ $t('zoom_in') }}" aria-label="{{ $t('zoom_in') }}"><x-backend-icon name="zoom-in" class="ui-icon" /></button>
            <button type="button" class="lf-wb-btn" data-wb-phone-hidden x-on:click="resetView()" data-tooltip="{{ $t('reset_view') }}" aria-label="{{ $t('reset_view') }}"><x-backend-icon name="scan" class="ui-icon" /></button>
        </div>

        <div class="lf-wb-top__group lf-wb-top__group--end">
            <span class="lf-wb-user">
                <strong>{{ $config['userName'] }}</strong>
                <span>{{ $t('user_id', ['id' => $config['userCode']]) }}</span>
            </span>
            <button type="button" class="lf-wb-btn lf-wb-btn--primary" x-show="! isViewing" x-on:click="toggleDrawer()" x-bind:aria-expanded="drawerOpen" aria-controls="lf-wb-drawer"
                    data-tooltip="{{ $t('materials') }}" aria-label="{{ $t('materials') }}" data-tooltip-placement="bottom-end">
                <x-backend-icon name="folder-open" class="ui-icon" /><span>{{ $t('materials') }}</span>
            </button>
            <button type="button" class="lf-wb-btn" x-on:click="toggleFullscreen()" x-show="fullscreenSupported"
                    x-bind:data-tooltip="fullscreen ? strings.exit_fullscreen : strings.fullscreen" x-bind:aria-label="fullscreen ? strings.exit_fullscreen : strings.fullscreen" data-tooltip-placement="bottom-end">
                <span x-show="! fullscreen"><x-backend-icon name="maximize" class="ui-icon" /></span>
                <span x-show="fullscreen" x-cloak><x-backend-icon name="minimize" class="ui-icon" /></span>
            </button>
        </div>
    </header>

    <div class="lf-wb-main">
        <aside class="lf-wb-tools" role="toolbar" aria-orientation="vertical" aria-label="{{ $t('tools') }}">
            @foreach ($tools as $tool)
                <button type="button" class="lf-wb-tool" x-on:click="selectTool('{{ $tool['key'] }}')" x-bind:aria-pressed="(tool === '{{ $tool['key'] }}').toString()"
                        data-tooltip="{{ $t('tool_'.$tool['key']) }}" data-tooltip-placement="right" aria-label="{{ $t('tool_'.$tool['key']) }}">
                    <x-backend-icon name="{{ $tool['icon'] }}" class="ui-icon" />
                </button>
            @endforeach

            <div class="lf-wb-colors" x-show="tool === 'pen' || tool === 'highlighter'" role="group" aria-label="{{ $t('tool_pen') }}">
                <template x-for="color in colors" :key="color.key">
                    <button type="button" class="lf-wb-color" x-bind:style="{ '--lf-wb-swatch': color.value }" x-on:click="setColor(color.value)"
                            x-bind:aria-pressed="(settings.color === color.value).toString()" x-bind:aria-label="color.label" x-bind:data-tooltip="color.label" data-tooltip-placement="right"></button>
                </template>
            </div>

            <div class="lf-wb-size" x-show="sizeKey" x-cloak>
                <label class="lf-wb-size__label" for="lf-wb-size" x-text="tool === 'laser' ? @js($t('size')) : @js($t('thickness'))"></label>
                <input id="lf-wb-size" type="range" x-bind:min="sizeLimits[0]" x-bind:max="sizeLimits[1]" x-bind:value="sizeValue"
                       x-on:input="setSize($event.target.value)" x-bind:style="{ accentColor: tool === 'laser' ? '#ef4444' : settings.color }">
                <span class="lf-wb-size__value" x-text="sizeValue" aria-hidden="true"></span>
            </div>
        </aside>

        <main class="lf-wb-stage" x-ref="stage">
            <canvas x-ref="canvas" class="lf-wb-canvas" role="img" aria-label="{{ $t('canvas_label') }}"></canvas>

            <div class="lf-wb-notes" x-bind:style="notesTransform">
                <template x-for="note in remoteNotes" :key="'r-' + note.id">
                    <div class="lf-wb-note is-remote" x-bind:style="noteStyle(note)" role="note" aria-label="{{ $t('remote_note') }}">
                        <div class="lf-wb-note__bar" aria-hidden="true"></div>
                        <div class="lf-wb-note__text" x-text="note.text"></div>
                    </div>
                </template>
                <template x-for="note in notes" :key="note.id">
                    <div class="lf-wb-note" x-bind:style="noteStyle(note)" x-bind:data-note="note.id">
                        <div class="lf-wb-note__bar" x-on:pointerdown="startNoteDrag($event, note.id)" data-tooltip="{{ $t('note_drag') }}" data-tooltip-placement="top">
                            <button type="button" class="lf-wb-note__delete" x-on:pointerdown.stop x-on:click="removeNote(note.id)" aria-label="{{ $t('note_delete') }}" data-tooltip="{{ $t('note_delete') }}" data-tooltip-placement="top-end">
                                <x-backend-icon name="x" class="ui-icon" />
                            </button>
                        </div>
                        <textarea class="lf-wb-note__text" name="note" maxlength="2000" placeholder="{{ $t('note_placeholder') }}" aria-label="{{ $t('tool_note') }}"
                                  x-init="$el.value = note.text"
                                  x-effect="if (document.activeElement !== $el && $el.value !== note.text) $el.value = note.text"
                                  x-on:focus="noteFocus(note.id)" x-on:input="noteInput(note, $event.target.value)" x-on:blur="noteBlur(note.id)"></textarea>
                    </div>
                </template>
            </div>

            <div class="lf-wb-laser" x-show="laser.visible" x-cloak x-bind:class="{ 'is-active': laser.active }" x-bind:style="laserStyle" aria-hidden="true"></div>
            <div class="lf-wb-laser is-remote" x-show="remoteLaser.visible" x-cloak x-bind:class="{ 'is-active': remoteLaser.active }" x-bind:style="remoteLaserStyle" aria-hidden="true"></div>

            <div class="lf-wb-syncbar">
                <span class="lf-wb-sync" x-bind:data-status="syncStatus" role="status" aria-live="polite" x-bind:title="syncLabel">
                    <span class="lf-wb-sync__dot" aria-hidden="true"></span>
                    <span class="lf-wb-sync__text" x-text="syncLabel"></span>
                </span>
                <span class="lf-wb-presenter" x-show="mode !== 'pending'" x-cloak x-text="presenterLabel" x-bind:title="presenterLabel"></span>
                <button type="button" class="lf-wb-btn lf-wb-btn--text" x-show="canPresent && isViewing && presenterName" x-cloak x-on:click="takeOver()"
                        data-tooltip="{{ $t('take_over_help') }}" aria-label="{{ $t('take_over') }}" data-tooltip-placement="bottom-start">
                    <x-backend-icon name="hand" class="ui-icon" /><span>{{ $t('take_over') }}</span>
                </button>
                <button type="button" class="lf-wb-btn lf-wb-btn--text" x-show="isViewing && ! following" x-cloak x-on:click="followPresenter()"
                        data-tooltip="{{ $t('follow_presenter_help') }}" aria-label="{{ $t('follow_presenter') }}" data-tooltip-placement="bottom-start">
                    <x-backend-icon name="scan" class="ui-icon" /><span>{{ $t('follow_presenter') }}</span>
                </button>
                <button type="button" class="lf-wb-btn lf-wb-btn--text" x-show="isViewing" x-cloak x-on:click="toggleListen()" data-listen
                        x-bind:data-tooltip="listenHelp" x-bind:aria-label="listenLabel + ' — ' + listenHelp" data-tooltip-placement="bottom-start">
                    <x-backend-icon name="volume-x" class="ui-icon" x-show="! soundOn" />
                    <x-backend-icon name="volume-2" class="ui-icon" x-show="soundOn" x-cloak />
                    <span x-text="listenLabel"></span>
                </button>
                @if ($context->meetingUrl)
                    <a class="lf-wb-btn lf-wb-btn--text" href="{{ $context->meetingUrl }}" target="_blank" rel="noopener noreferrer" data-wb-meeting
                       data-tooltip="{{ __('lf.LF_liveclass_whiteboard_join_meeting') }}" aria-label="{{ __('lf.LF_liveclass_whiteboard_join_meeting') }}" data-tooltip-placement="bottom-start">
                        <x-backend-icon name="video" class="ui-icon" /><span>{{ __('lf.LF_liveclass_whiteboard_join_meeting') }}</span>
                    </a>
                @endif
            </div>

            <div class="lf-wb-notice" x-show="isViewing && material.kind === 'pdf' && material.local" x-cloak role="status">
                <x-backend-icon name="info" class="ui-icon" /><span>{{ $t('local_material_notice') }}</span>
            </div>

            <div class="lf-wb-pdfbar" x-show="material.kind === 'pdf'" x-cloak role="toolbar" aria-label="{{ $t('kind_pdf') }}">
                <button type="button" class="lf-wb-btn" x-show="! isViewing" x-on:click="goPage(page - 1)" x-bind:disabled="page <= 1" data-tooltip="{{ $t('pdf_prev') }}" data-tooltip-placement="top" aria-label="{{ $t('pdf_prev') }}"><x-backend-icon name="chevron-left" class="ui-icon" /></button>
                <span class="lf-wb-pagefield" x-show="isViewing" x-cloak x-text="page + ' / ' + pageCount"></span>
                <label class="lf-wb-pagefield" x-show="! isViewing">
                    <span class="ui-sr-only">{{ $t('pdf_page') }}</span>
                    <input type="number" name="page" min="1" x-bind:max="pageCount" x-bind:value="page" inputmode="numeric"
                           x-on:change="goPage($event.target.value); $event.target.value = page" x-on:keydown.enter.prevent="$event.target.blur()">
                    <span x-text="'/ ' + pageCount"></span>
                </label>
                <button type="button" class="lf-wb-btn" x-show="! isViewing" x-on:click="goPage(page + 1)" x-bind:disabled="page >= pageCount" data-tooltip="{{ $t('pdf_next') }}" data-tooltip-placement="top" aria-label="{{ $t('pdf_next') }}"><x-backend-icon name="chevron-right" class="ui-icon" /></button>
                <span class="lf-wb-sep" aria-hidden="true"></span>
                <button type="button" class="lf-wb-btn" x-on:click="zoom(1 / 1.2)" data-tooltip="{{ $t('pdf_zoom_out') }}" data-tooltip-placement="top" aria-label="{{ $t('pdf_zoom_out') }}"><x-backend-icon name="zoom-out" class="ui-icon" /></button>
                <span class="lf-wb-pdfbar__zoom" x-text="zoomPercent + '%'"></span>
                <button type="button" class="lf-wb-btn" x-on:click="zoom(1.2)" data-tooltip="{{ $t('pdf_zoom_in') }}" data-tooltip-placement="top" aria-label="{{ $t('pdf_zoom_in') }}"><x-backend-icon name="zoom-in" class="ui-icon" /></button>
                <button type="button" class="lf-wb-btn" x-on:click="fitWidth()" data-tooltip="{{ $t('fit_width') }}" data-tooltip-placement="top" aria-label="{{ $t('fit_width') }}"><x-backend-icon name="move-horizontal" class="ui-icon" /></button>
                <button type="button" class="lf-wb-btn" x-on:click="fitHeight()" data-tooltip="{{ $t('fit_height') }}" data-tooltip-placement="top" aria-label="{{ $t('fit_height') }}"><x-backend-icon name="move-vertical" class="ui-icon" /></button>
                <span class="lf-wb-sep" x-show="! isViewing" aria-hidden="true"></span>
                <button type="button" class="lf-wb-btn" x-show="! isViewing" x-on:click="closeMaterial()" data-tooltip="{{ $t('close_material') }}" data-tooltip-placement="top-end" aria-label="{{ $t('close_material') }}"><x-backend-icon name="x" class="ui-icon" /></button>
            </div>

            <div class="lf-wb-media" x-show="media" x-cloak x-bind:data-kind="media?.kind" role="dialog" aria-modal="false" x-bind:aria-label="media?.title">
                <div class="lf-wb-media__head">
                    <strong x-text="media?.title"></strong>
                    <span class="lf-wb-media__badge" x-show="media?.preview" x-cloak>{{ $t('previewing') }}</span>
                    <span class="lf-wb-media__actions">
                        <button type="button" class="lf-wb-btn lf-wb-btn--primary" x-show="isViewing && soundBlocked" x-cloak x-on:click="enableSound()"
                                data-tooltip="{{ $t('enable_sound_help') }}" aria-label="{{ $t('enable_sound') }}" data-tooltip-placement="bottom-end">
                            <x-backend-icon name="volume-2" class="ui-icon" /><span>{{ $t('enable_sound') }}</span>
                        </button>
                        <button type="button" class="lf-wb-btn lf-wb-btn--primary" x-show="isPresenting && media?.preview" x-cloak x-on:click="showToClass()"
                                data-tooltip="{{ $t('show_to_class_help') }}" aria-label="{{ $t('show_to_class') }}" data-tooltip-placement="bottom-end">
                            <x-backend-icon name="presentation" class="ui-icon" /><span>{{ $t('show_to_class') }}</span>
                        </button>
                        <button type="button" class="lf-wb-btn" x-on:click="closeMedia()" x-bind:data-tooltip="media?.kind === 'audio' ? strings.close_audio : strings.close_video"
                                data-tooltip-placement="bottom-end" x-bind:aria-label="media?.kind === 'audio' ? strings.close_audio : strings.close_video"><x-backend-icon name="x" class="ui-icon" /></button>
                    </span>
                </div>
                <p class="lf-wb-media__hint" x-show="showZoomHint" x-cloak><x-backend-icon name="info" class="ui-icon" /><span>{{ $t('zoom_audio_hint') }}</span></p>
                <div class="lf-wb-media__body" x-ref="mediaBody"></div>
            </div>

            <div class="lf-wb-loading" x-show="loading" x-cloak role="status"><span x-text="strings.loading"></span></div>
        </main>

        <aside id="lf-wb-drawer" class="lf-wb-drawer" x-show="drawerOpen && ! isViewing" x-cloak x-on:keydown.escape.stop="drawerOpen = false" aria-labelledby="lf-wb-drawer-title">
            <header class="lf-wb-drawer__head">
                <div>
                    <h2 id="lf-wb-drawer-title">{{ $t('materials') }}</h2>
                    <p class="lf-wb-drawer__total" x-text="strings.materials_total.replace(':count', materials.length)"></p>
                </div>
                <button type="button" class="lf-wb-btn" x-on:click="refreshMaterials()" x-bind:disabled="refreshing" x-bind:aria-busy="refreshing.toString()"
                        data-tooltip="{{ $t('refresh') }}" data-tooltip-placement="bottom-end" aria-label="{{ $t('refresh') }}"><x-backend-icon name="refresh-cw" class="ui-icon" /></button>
                <button type="button" class="lf-wb-btn" x-on:click="drawerOpen = false" data-tooltip="{{ $t('close') }}" data-tooltip-placement="bottom-end" aria-label="{{ $t('close') }}"><x-backend-icon name="x" class="ui-icon" /></button>
            </header>
            <p class="lf-wb-drawer__status" x-show="refreshing" role="status">{{ $t('refreshing') }}</p>
            <ul class="lf-wb-materials">
                <li>
                    <button type="button" class="lf-wb-material" x-on:click="closeMaterial(); drawerOpen = false" x-bind:aria-current="material.key === 'blank' ? 'true' : null">
                        <span class="lf-wb-material__icon"><x-backend-icon name="square-pen" class="ui-icon" /></span>
                        <span class="lf-wb-material__text"><strong>{{ $t('blank_board') }}</strong></span>
                        <span class="lf-wb-material__current" x-show="material.key === 'blank'">{{ $t('current') }}</span>
                    </button>
                </li>
                <template x-for="item in materials" :key="item.id">
                    <li>
                        <button type="button" class="lf-wb-material" x-on:click="openMaterial(item)" x-bind:aria-current="material.key === 'activity:' + item.id ? 'true' : null">
                            <span class="lf-wb-material__icon" x-bind:data-kind="item.kind">
                                <span x-show="item.kind === 'pdf'"><x-backend-icon name="file-pdf" class="ui-icon" /></span>
                                <span x-show="item.kind === 'document'"><x-backend-icon name="document" class="ui-icon" /></span>
                                <span x-show="item.kind === 'video' || item.kind === 'embed'"><x-backend-icon name="video" class="ui-icon" /></span>
                                <span x-show="item.kind === 'audio'"><x-backend-icon name="audio" class="ui-icon" /></span>
                            </span>
                            <span class="lf-wb-material__text">
                                <strong x-text="item.title"></strong>
                                <span x-text="materialMeta(item)"></span>
                            </span>
                            <span class="lf-wb-material__current" x-show="material.key === 'activity:' + item.id || (media && ! media.preview && Number(media.id) === Number(item.id))">{{ $t('current') }}</span>
                            <span class="lf-wb-material__external" x-show="item.kind === 'document'" x-bind:title="strings.opens_new_tab"><x-backend-icon name="external-link" class="ui-icon" /></span>
                        </button>
                        <button type="button" class="lf-wb-btn lf-wb-material__preview" x-show="['video', 'embed', 'audio'].includes(item.kind) && syncStatus !== 'off'" x-on:click="previewMedia(item)"
                                data-tooltip="{{ $t('preview_help') }}" x-bind:aria-label="strings.preview + ': ' + item.title" data-tooltip-placement="bottom-end"><x-backend-icon name="eye" class="ui-icon" /></button>
                    </li>
                </template>
            </ul>
            <div class="lf-wb-drawer__empty" x-show="! materials.length && ! refreshing">
                <strong>{{ $t('no_materials') }}</strong>
                <span>{{ $t('no_materials_help') }}</span>
            </div>
            <footer class="lf-wb-drawer__foot">
                <button type="button" class="ui-button ui-button--outline cursor-interaction" x-on:click="$refs.localPdf.click()">
                    <x-backend-icon name="file-up" class="ui-icon" />{{ $t('open_local_pdf') }}
                </button>
                <input type="file" name="local_pdf" x-ref="localPdf" accept="application/pdf,.pdf" hidden x-on:change="openLocalPdf($event.target.files[0]); $event.target.value = ''">
            </footer>
        </aside>
    </div>

    <div class="lf-wb-toast" role="status" aria-live="polite" x-show="toast" x-cloak x-text="toast"></div>

    <div class="lf-wb-ended" x-show="ended" x-cloak role="alertdialog" aria-labelledby="lf-wb-ended-text">
        <div class="lf-wb-ended__box">
            <p id="lf-wb-ended-text" x-text="endedReason === 'revoked' ? strings.revoked : strings.ended"></p>
            <button type="button" class="ui-button ui-button--primary cursor-interaction" x-on:click="leave()">{{ $t('back') }}</button>
        </div>
    </div>

    <dialog x-ref="clearAllDialog" class="ui-modal" data-columns="1" aria-labelledby="lf-wb-clear-title" aria-describedby="lf-wb-clear-body">
        <div class="ui-modal__form">
            <header class="ui-modal__header">
                <div><h3 id="lf-wb-clear-title">{{ $t('clear_all_title') }}</h3></div>
                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.clearAllDialog.close()" aria-label="{{ $t('close') }}">×</button>
            </header>
            <div class="ui-modal__body"><p id="lf-wb-clear-body" class="ui-enroll-dialog-text">{{ $t('clear_all_body') }}</p></div>
            <footer class="ui-modal__footer">
                <button type="button" class="ui-button cursor-interaction" x-on:click="$refs.clearAllDialog.close()">{{ $t('cancel') }}</button>
                <button type="button" class="ui-button ui-button--danger-outline cursor-interaction" x-on:click="confirmClearAll()">{{ $t('delete') }}</button>
            </footer>
        </div>
    </dialog>

    <dialog x-ref="listenDialog" class="ui-modal" data-columns="1" aria-labelledby="lf-wb-listen-title" aria-describedby="lf-wb-listen-body">
        <div class="ui-modal__form">
            <header class="ui-modal__header">
                <div><h3 id="lf-wb-listen-title">{{ $t('listen_title') }}</h3></div>
                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.listenDialog.close()" aria-label="{{ $t('close') }}">×</button>
            </header>
            <div class="ui-modal__body"><p id="lf-wb-listen-body" class="ui-enroll-dialog-text">{{ $t('listen_body') }}</p></div>
            <footer class="ui-modal__footer">
                <button type="button" class="ui-button cursor-interaction" x-on:click="chooseListen('room')"><x-backend-icon name="volume-x" class="ui-icon" />{{ $t('listen_choose_room') }}</button>
                <button type="button" class="ui-button ui-button--primary cursor-interaction" x-on:click="chooseListen('remote')"><x-backend-icon name="volume-2" class="ui-icon" />{{ $t('listen_choose_remote') }}</button>
            </footer>
        </div>
    </dialog>

    <dialog x-ref="resumeDialog" class="ui-modal" data-columns="1" aria-labelledby="lf-wb-resume-title" aria-describedby="lf-wb-resume-body" x-on:close="resumeAnswered()">
        <div class="ui-modal__form">
            <header class="ui-modal__header">
                <div><h3 id="lf-wb-resume-title">{{ $t('resume_title') }}</h3></div>
                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="answerResume(false)" aria-label="{{ $t('close') }}">×</button>
            </header>
            <div class="ui-modal__body"><p id="lf-wb-resume-body" class="ui-enroll-dialog-text" x-text="strings.resume_body.replace(':page', resumePage)"></p></div>
            <footer class="ui-modal__footer">
                <button type="button" class="ui-button cursor-interaction" x-on:click="answerResume(false)">{{ $t('resume_no') }}</button>
                <button type="button" class="ui-button ui-button--primary cursor-interaction" x-on:click="answerResume(true)"><x-backend-icon name="check" class="ui-icon" />{{ $t('resume_yes') }}</button>
            </footer>
        </div>
    </dialog>
</div>
</body>
</html>
