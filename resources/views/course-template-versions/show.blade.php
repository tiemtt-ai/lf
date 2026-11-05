@extends('layouts.backend')

@section('title', __('lf.LF_course_template_version_detail_title'))
@section('page_title', __('lf.LF_course_template_version_detail_title'))

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css'])
@endsection

@section('page_back')
    <x-page-back-link :href="route('admin.course-templates.edit', ['id' => $template->id, 'tab' => 'history'])" :label="__('lf.LF_course_template_version_detail_back')" />
@endsection

@section('content')
    <div class="course-template-version-detail"
         x-data="{
             previewOpen: false,
             preview: { name: '', url: '', type: '', mimeType: '' },
             openVersionPreview(name, url, type, mimeType = '') {
                 this.preview = { name, url, type, mimeType };
                 this.previewOpen = true;
             },
             closeVersionPreview() {
                 this.previewOpen = false;
                 this.preview = { name: '', url: '', type: '', mimeType: '' };
             },
         }">
        {{-- UI Kit header and saved information; the published content below mirrors the authoring Content tab. --}}
        <div class="lf-admin-ui lf-admin-ui--list ui-version">
            <section class="mk-section" aria-labelledby="course-version-title">
                <div class="ui-summary-head ui-version__head">
                    <div class="ui-version__identity">
                        <p class="ui-version__eyebrow">
                            {{ __('lf.LF_course_template_version_number', ['version' => $version->version_number]) }}
                            <span aria-hidden="true">·</span>
                            <span>{{ $version->version_code }}</span>
                        </p>
                        <h2 id="course-version-title">{{ $version->title_snapshot }}</h2>
                        <div class="ui-version__chips">
                            {{-- The type frozen into this Version (ADR-0022), not the one the working Template has now. --}}
                            @if ($version->course_type_snapshot ?? null)
                                <span class="ui-course-type ui-course-type--{{ $version->course_type_snapshot }}" data-course-type="{{ $version->course_type_snapshot }}" title="{{ __('lf.LF_course_type_label') }}">{{ __(\App\Support\CourseType::labelKey($version->course_type_snapshot)) }}</span>
                            @endif
                            @if ($version->is_current)
                                <span class="ui-status ui-status--active">{{ __('lf.LF_version_detail_current_published') }}</span>
                            @else
                                <span @class(['ui-status', 'ui-status--draft' => $version->status === 'published', 'ui-status--inactive' => $version->status === 'deprecated', 'ui-status--archived' => $version->status === 'archived'])>{{ __('lf.LF_course_template_version_status_'.$version->status) }}</span>
                            @endif
                        </div>
                    </div>
                    {{-- An archived Template is read-only, so it cannot take a Version back into its draft. --}}
                    @if ($template->status !== 'archived' && in_array($version->status, ['published', 'deprecated', 'archived'], true))
                        <form method="POST"
                              action="{{ route('admin.course-templates.versions.duplicate-to-draft', ['templateId' => $template->id, 'versionId' => $version->id]) }}"
                              data-lf-confirm="{{ __('lf.LF_course_template_duplicate_confirm_named', ['version' => $version->version_number]) }}"
                              data-lf-confirm-label="{{ __('lf.LF_course_template_duplicate_action') }}">
                            @csrf
                            <button type="submit" class="ui-button ui-button--outline cursor-interaction">
                                <x-backend-icon name="copy" class="ui-icon" />{{ __('lf.LF_course_template_duplicate_action') }}
                            </button>
                        </form>
                    @endif
                </div>

                <dl class="ui-summary ui-version__facts">
                    <div class="ui-summary__item">
                        <dt>{{ __('lf.LF_course_template_history_published_at') }}</dt>
                        <dd>{{ \Illuminate\Support\Carbon::parse($version->published_at)->format('d/m/Y H:i') }}</dd>
                    </div>
                    <div class="ui-summary__item">
                        <dt>{{ __('lf.LF_course_template_history_published_by') }}</dt>
                        <dd>{{ $version->published_by_name ?? __('lf.LF_course_template_history_unknown_publisher') }}</dd>
                    </div>
                    <div class="ui-summary__item">
                        <dt>{{ __('lf.LF_version_detail_source_revision') }}</dt>
                        <dd>{{ $version->source_working_revision }}</dd>
                    </div>
                    <div class="ui-summary__item">
                        <dt>{{ __('lf.LF_course_template_version_detail_lesson_count') }}</dt>
                        <dd>{{ $version->lesson_count_snapshot }}</dd>
                    </div>
                </dl>
                <p class="ui-version__note">
                    <x-backend-icon name="lock" class="ui-icon" />
                    <span>{{ __('lf.LF_version_detail_immutable_note') }}</span>
                </p>
            </section>

            <section class="mk-section" aria-labelledby="course-version-information-title">
                <div class="ui-summary-head">
                    <h2 id="course-version-information-title">{{ __('lf.LF_course_template_version_detail_information') }}</h2>
                </div>
                <div class="ui-summary-group">
                    <dl class="ui-summary ui-version__info">
                        <div class="ui-summary__item">
                            <dt>{{ __('lf.LF_course_template_common_category') }}</dt>
                            <dd>{{ $version->category_name_snapshot ?? __('lf.LF_course_template_common_no_category') }}</dd>
                        </div>
                        <div class="ui-summary__item">
                            <dt>{{ __('lf.LF_course_template_common_publisher_name') }}</dt>
                            <dd>{{ $version->publisher_name_snapshot ?? __('lf.LF_course_template_publish_not_available') }}</dd>
                        </div>
                        <div class="ui-summary__item">
                            <dt>{{ __('lf.LF_course_template_common_difficulty_level') }}</dt>
                            <dd>{{ $version->difficulty_level_snapshot ? __('lf.LF_course_template_common_'.$version->difficulty_level_snapshot) : __('lf.LF_course_template_common_no_difficulty') }}</dd>
                        </div>
                        <div class="ui-summary__item">
                            <dt>{{ __('lf.LF_course_template_estimated_minutes_per_lesson') }}</dt>
                            <dd>{{ $version->estimated_minutes_per_lesson_snapshot ?? '—' }}</dd>
                        </div>
                        <div class="ui-summary__item">
                            <dt>{{ __('lf.LF_course_template_estimated_lesson_count') }}</dt>
                            <dd>{{ $version->estimated_lesson_count_snapshot ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>

                @if ($version->short_description_snapshot || $version->description_snapshot)
                    <div class="ui-summary-group">
                        <dl class="ui-summary">
                            @foreach ([
                                __('lf.LF_course_template_common_short_description') => $version->short_description_snapshot,
                                __('lf.LF_course_template_common_description') => $version->description_snapshot,
                            ] as $copyLabel => $copyValue)
                                @if ($copyValue)
                                    <div class="ui-summary__item ui-summary__item--wide">
                                        <dt class="course-version-copy-label">{{ $copyLabel }}</dt>
                                        <dd class="course-version-copy-content">{{ $copyValue }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>
                    </div>
                @endif

                <div class="ui-summary-group">
                    <h3>{{ __('lf.LF_course_template_version_detail_media') }}</h3>
                    <div class="ui-version__media">
                        @foreach ([
                            'image' => __('lf.LF_course_template_intro_image'),
                            'video' => __('lf.LF_course_template_intro_video'),
                            'document' => __('lf.LF_course_template_intro_document'),
                        ] as $slot => $label)
                            @php
                                $snapshot = $templateVersionMedia[$slot];
                            @endphp
                            <div class="ui-version__media-slot" data-version-media-slot="{{ $slot }}">
                                <p class="ui-field__label">{{ $label }}</p>
                                @if ($snapshot['state'] === 'empty')
                                    <p class="ui-version__media-empty">{{ __('lf.LF_course_template_version_detail_no_media') }}</p>
                                @elseif ($snapshot['state'] === 'unavailable')
                                    <p class="ui-version__media-empty is-unavailable">{{ __('lf.LF_version_detail_media_unavailable') }}</p>
                                @else
                                    @php
                                        $mediaName = $snapshot['media']->display_name ?? $label;
                                        $previewType = match ($snapshot['viewMode']) {
                                            'modal_image' => 'image',
                                            'modal_video' => 'video',
                                            'modal_embed' => 'embed',
                                            default => null,
                                        };
                                    @endphp
                                    <x-authoring-media-row :presentation="$snapshot['thumbnail']" :alt="$mediaName" :display-name="$mediaName">
                                        @if ($snapshot['viewMode'] === 'new_tab_document')
                                            <a class="authoring-media-overlay-action" href="{{ $snapshot['url'] }}" target="_blank" rel="noopener noreferrer"
                                               aria-label="{{ __('lf.LF_media_file_common_preview_action') }}">
                                                <x-backend-icon name="eye" class="authoring-media-action-icon" />
                                            </a>
                                        @else
                                            <button type="button" class="authoring-media-overlay-action"
                                                    data-preview-name="{{ $mediaName }}"
                                                    data-preview-url="{{ $snapshot['url'] }}"
                                                    data-preview-type="{{ $previewType }}"
                                                    data-preview-mime="{{ $snapshot['media']->mime_type ?? 'text/html' }}"
                                                    aria-label="{{ __('lf.LF_media_file_common_preview_action') }}"
                                                    x-on:click="openVersionPreview($el.dataset.previewName, $el.dataset.previewUrl, $el.dataset.previewType, $el.dataset.previewMime)">
                                                <x-backend-icon name="eye" class="authoring-media-action-icon" />
                                            </button>
                                        @endif
                                    </x-authoring-media-row>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        </div>

        @include('course-template-versions.partials.content-structure')

        {{-- Same UI Kit preview popup (default size) as the Template information tab; x-if drops the player on close, which stops playback. --}}
        <div class="lf-admin-ui">
            <x-media-preview-modal ui-kit id="version-media-preview-title" show="previewOpen" close="closeVersionPreview()" title="preview.name" compact="false">
                <template x-if="previewOpen && preview.type === 'image'">
                    <img x-bind:src="preview.url" x-bind:alt="preview.name">
                </template>
                <template x-if="previewOpen && preview.type === 'video'">
                    <video controls preload="metadata" autoplay x-bind:src="preview.url"></video>
                </template>
                <template x-if="previewOpen && preview.type === 'audio'">
                    <audio controls preload="metadata" autoplay x-bind:src="preview.url"></audio>
                </template>
                <template x-if="previewOpen && preview.type === 'embed'">
                    <iframe x-bind:src="preview.url" x-bind:title="preview.name" loading="lazy"
                            sandbox="allow-scripts allow-same-origin allow-presentation"
                            allow="autoplay; fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                </template>
            </x-media-preview-modal>
        </div>
    </div>
@endsection
