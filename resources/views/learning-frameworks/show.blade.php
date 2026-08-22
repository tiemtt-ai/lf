@extends('layouts.backend')

@section('title', $framework->name)
@section('page_title', $framework->name)

@php
    $versionStatusBadge = [
        'draft_snapshot' => 'badge-info',
        'published' => 'badge-success',
        'deprecated' => 'badge-neutral',
        'archived' => 'badge-neutral',
    ];
    $draftCount = $versions->where('status', 'draft_snapshot')->count();
@endphp

@section('content')
    <div class="learning-framework-detail-toolbar">
        <a class="learning-framework-detail-back" href="{{ route('admin.learning-frameworks.index') }}">
            <span aria-hidden="true">←</span>
            {{ __('lf.LF_learning_back') }}
        </a>
        <div class="learning-framework-detail-identity">
            <span class="learning-framework-detail-code">{{ $framework->code }}</span>
            <span class="badge {{ $framework->status === 'active' ? 'badge-success' : 'badge-neutral' }}">
                {{ $framework->status === 'active'
                    ? __('lf.LF_common_status_common_active')
                    : __('lf.LF_learning_status_archived') }}
            </span>
        </div>
    </div>

    @include('learning-frameworks.partials.errors')

    <div class="learning-framework-summary">
        <div class="learning-framework-summary-item">
            <span class="learning-framework-summary-label">{{ __('lf.LF_learning_versions') }}</span>
            <strong class="learning-framework-summary-value">{{ $versions->count() }}</strong>
            <span class="learning-framework-summary-hint">
                {{ __('lf.LF_learning_summary_draft_count', ['count' => $draftCount]) }}
            </span>
        </div>
        <div class="learning-framework-summary-item">
            <span class="learning-framework-summary-label">{{ __('lf.LF_learning_definitions') }}</span>
            <strong class="learning-framework-summary-value">{{ $definitions->count() }}</strong>
            <span class="learning-framework-summary-hint">{{ __('lf.LF_learning_summary_definition_hint') }}</span>
        </div>
        <div class="learning-framework-summary-item">
            <span class="learning-framework-summary-label">{{ __('lf.LF_learning_mastery_scale') }}</span>
            <strong class="learning-framework-summary-value">{{ count($masteryScale['levels']) }}</strong>
            <span class="learning-framework-summary-hint">
                {{ $framework->default_mastery_scale_key }} · v{{ $framework->default_mastery_scale_version }}
            </span>
        </div>
    </div>

    {{-- Framework details --}}
    <section class="admin-card learning-framework-section" x-data="{ open: false }">
        <header class="learning-framework-section-header">
            <div>
                <h2 class="learning-framework-section-title">{{ __('lf.LF_learning_framework_details') }}</h2>
                <p class="learning-framework-section-help">{{ __('lf.LF_learning_group_identity_help') }}</p>
            </div>
            <button type="button" class="btn btn-secondary" x-on:click="open = !open"
                    :aria-expanded="open ? 'true' : 'false'">
                <span x-text="open ? @js(__('lf.LF_common_button_cancel')) : @js(__('lf.LF_learning_edit_framework'))"></span>
            </button>
        </header>

        <div x-show="open" x-cloak>
            <form method="POST" action="{{ route('admin.learning-frameworks.update', $framework->id) }}">
                @csrf
                @method('PUT')
                @include('learning-frameworks.partials.framework-fields', [
                    'framework' => $framework,
                    'masteryScale' => $masteryScale,
                ])
                <footer class="admin-form-footer" data-actions-align="end">
                    <div class="admin-form-footer-primary">
                        <button type="button" class="btn btn-secondary" x-on:click="open = false">
                            {{ __('lf.LF_common_button_cancel') }}
                        </button>
                        <button class="btn btn-primary" type="submit">{{ __('lf.LF_common_button_save') }}</button>
                    </div>
                </footer>
            </form>
        </div>

        <dl x-show="! open" class="learning-framework-facts">
            <div>
                <dt>{{ __('lf.LF_learning_name') }}</dt>
                <dd>{{ $framework->name }}</dd>
            </div>
            <div>
                <dt>{{ __('lf.LF_learning_description') }}</dt>
                <dd>{{ $framework->description ?: '—' }}</dd>
            </div>
            <div>
                <dt>{{ __('lf.LF_learning_levels') }}</dt>
                <dd class="learning-framework-scale-list">
                    @foreach ($masteryScale['levels'] as $level)
                        <span class="badge badge-neutral">{{ $level['key'] }} · {{ $level['threshold'] }}</span>
                    @endforeach
                </dd>
            </div>
        </dl>
    </section>

    {{-- Stable Node Definitions --}}
    <section class="admin-card learning-framework-section" x-data="{ adding: false }">
        <header class="learning-framework-section-header">
            <div>
                <h2 class="learning-framework-section-title">{{ __('lf.LF_learning_definitions') }}</h2>
                <p class="learning-framework-section-help">{{ __('lf.LF_learning_definitions_help') }}</p>
            </div>
            <button type="button" class="btn btn-primary" x-on:click="adding = ! adding"
                    :aria-expanded="adding ? 'true' : 'false'">
                <span aria-hidden="true">+</span>
                {{ __('lf.LF_learning_add_definition') }}
            </button>
        </header>

        <div x-show="adding" x-cloak class="learning-framework-inline-form">
            <form method="POST" action="{{ route('admin.learning-frameworks.definitions.store', $framework->id) }}">
                @csrf
                <input type="hidden" name="framework_id" value="{{ $framework->id }}">
                @include('learning-frameworks.partials.definition-fields', [
                    'definition' => null,
                    'identityLocked' => false,
                ])
                <footer class="admin-form-footer" data-actions-align="end">
                    <div class="admin-form-footer-primary">
                        <button type="button" class="btn btn-secondary" x-on:click="adding = false">
                            {{ __('lf.LF_common_button_cancel') }}
                        </button>
                        <button class="btn btn-primary" type="submit">{{ __('lf.LF_learning_add_definition') }}</button>
                    </div>
                </footer>
            </form>
        </div>

        @forelse ($definitions as $definition)
            <article class="learning-framework-definition" x-data="{ editing: false }">
                <header class="learning-framework-definition-header">
                    <div class="learning-framework-definition-identity">
                        <strong>{{ $definition->canonical_name }}</strong>
                        <span class="learning-framework-index-meta">{{ $definition->code }}</span>
                    </div>
                    <div class="learning-framework-definition-tags">
                        <span class="badge badge-info">{{ $definition->node_type }}</span>
                        @if ($definition->identity_locked)
                            <span class="badge badge-neutral" title="{{ __('lf.LF_learning_definition_identity_locked') }}">
                                <x-backend-icon name="lock" class="learning-framework-lock-icon" />
                                {{ __('lf.LF_learning_locked') }}
                            </span>
                        @endif
                        <button type="button" class="admin-text-action" x-on:click="editing = ! editing"
                                :aria-expanded="editing ? 'true' : 'false'">
                            {{ __('lf.action_edit') }}
                        </button>
                    </div>
                </header>

                <p class="learning-framework-definition-description" x-show="! editing">
                    {{ $definition->description ?: '—' }}
                </p>

                <div x-show="editing" x-cloak class="learning-framework-inline-form">
                    <form method="POST"
                          action="{{ route('admin.learning-frameworks.definitions.update', [$framework->id, $definition->id]) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="framework_id" value="{{ $framework->id }}">
                        @include('learning-frameworks.partials.definition-fields', [
                            'definition' => $definition,
                            'identityLocked' => (bool) $definition->identity_locked,
                        ])
                        <footer class="admin-form-footer" data-actions-align="end">
                            <div class="admin-form-footer-primary">
                                <button type="button" class="btn btn-secondary" x-on:click="editing = false">
                                    {{ __('lf.LF_common_button_cancel') }}
                                </button>
                                <button class="btn btn-primary" type="submit">{{ __('lf.LF_common_button_save') }}</button>
                            </div>
                        </footer>
                    </form>
                </div>
            </article>
        @empty
            <div class="learning-framework-empty-state" role="status">
                <strong>{{ __('lf.LF_learning_definitions_empty') }}</strong>
                <span>{{ __('lf.LF_learning_definitions_empty_help') }}</span>
            </div>
        @endforelse
    </section>

    {{-- Framework Versions --}}
    <section class="admin-card learning-framework-section" x-data="{ creating: false }">
        <header class="learning-framework-section-header">
            <div>
                <h2 class="learning-framework-section-title">{{ __('lf.LF_learning_versions') }}</h2>
                <p class="learning-framework-section-help">{{ __('lf.LF_learning_versions_help') }}</p>
            </div>
            <button type="button" class="btn btn-primary" x-on:click="creating = ! creating"
                    :aria-expanded="creating ? 'true' : 'false'">
                <span aria-hidden="true">+</span>
                {{ __('lf.LF_learning_new_version') }}
            </button>
        </header>

        <div x-show="creating" x-cloak class="learning-framework-inline-form">
            <form method="POST" action="{{ route('admin.learning-frameworks.versions.store', $framework->id) }}">
                @csrf
                <input type="hidden" name="framework_id" value="{{ $framework->id }}">
                <div class="admin-form-field-grid">
                    <div class="lf-form-group admin-form-field">
                        <x-form-label for="new-version-code" :value="__('lf.LF_learning_version_code')" required />
                        <input id="new-version-code" name="version_code" class="lf-form-control" required maxlength="100"
                               value="{{ old('version_code') }}">
                        <p class="lf-form-help">{{ __('lf.LF_learning_version_code_help') }}</p>
                    </div>
                    <div class="lf-form-group admin-form-field">
                        <x-form-label for="new-version-title" :value="__('lf.LF_learning_title')" required />
                        <input id="new-version-title" name="title" class="lf-form-control" required maxlength="255"
                               value="{{ old('title') }}">
                    </div>
                    <div class="lf-form-group admin-form-field--full">
                        <x-form-label for="new-version-description" :value="__('lf.LF_learning_description')" />
                        <textarea id="new-version-description" name="description" class="lf-form-control" rows="3"
                                  maxlength="5000">{{ old('description') }}</textarea>
                    </div>
                </div>
                <footer class="admin-form-footer" data-actions-align="end">
                    <div class="admin-form-footer-primary">
                        <button type="button" class="btn btn-secondary" x-on:click="creating = false">
                            {{ __('lf.LF_common_button_cancel') }}
                        </button>
                        <button class="btn btn-primary" type="submit">{{ __('lf.LF_learning_create_draft') }}</button>
                    </div>
                </footer>
            </form>
        </div>

        @forelse ($versions as $version)
            @php
                $isDraft = $version->status === 'draft_snapshot';
                $versionNodes = $nodesByVersion->get($version->id, collect());
            @endphp

            <article @class(['learning-framework-version', 'is-frozen' => ! $isDraft])
                     x-data="{ editing: false, addingNode: false }">
                <header class="learning-framework-version-header">
                    <div class="learning-framework-version-identity">
                        <strong>
                            {{ __('lf.LF_learning_version') }} {{ $version->version_number }} —
                            {{ $version->title_snapshot }}
                        </strong>
                        <span class="learning-framework-index-meta">{{ $version->version_code }}</span>
                    </div>
                    <div class="learning-framework-version-tags">
                        <span class="badge {{ $versionStatusBadge[$version->status] ?? 'badge-neutral' }}">
                            @if (! $isDraft)
                                <x-backend-icon name="lock" class="learning-framework-lock-icon" />
                            @endif
                            {{ __('lf.LF_learning_version_status_'.$version->status) }}
                        </span>
                        <span class="learning-framework-index-muted">
                            {{ __('lf.LF_learning_node_count', ['count' => $versionNodes->count()]) }}
                        </span>
                        @if ($isDraft)
                            <button type="button" class="admin-text-action" x-on:click="editing = ! editing"
                                    :aria-expanded="editing ? 'true' : 'false'">
                                {{ __('lf.action_edit') }}
                            </button>
                        @endif
                    </div>
                </header>

                @if ($isDraft)
                    <div x-show="editing" x-cloak class="learning-framework-inline-form">
                        <form method="POST"
                              action="{{ route('admin.learning-frameworks.versions.update', [$framework->id, $version->id]) }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="framework_id" value="{{ $framework->id }}">
                            <input type="hidden" name="version_code" value="{{ $version->version_code }}">
                            <div class="admin-form-field-grid">
                                <div class="lf-form-group admin-form-field">
                                    <x-form-label :for="'version-code-'.$version->id" :value="__('lf.LF_learning_version_code')" />
                                    <input id="version-code-{{ $version->id }}" class="lf-form-control" disabled
                                           value="{{ $version->version_code }}">
                                    <p class="lf-form-help">{{ __('lf.LF_learning_version_code_immutable_help') }}</p>
                                </div>
                                <div class="lf-form-group admin-form-field">
                                    <x-form-label :for="'version-title-'.$version->id" :value="__('lf.LF_learning_title')" required />
                                    <input id="version-title-{{ $version->id }}" name="title" class="lf-form-control"
                                           required maxlength="255" value="{{ $version->title_snapshot }}">
                                </div>
                                <div class="lf-form-group admin-form-field--full">
                                    <x-form-label :for="'version-description-'.$version->id" :value="__('lf.LF_learning_description')" />
                                    <textarea id="version-description-{{ $version->id }}" name="description"
                                              class="lf-form-control" rows="3" maxlength="5000">{{ $version->description_snapshot }}</textarea>
                                </div>
                            </div>
                            <footer class="admin-form-footer" data-actions-align="end">
                                <div class="admin-form-footer-primary">
                                    <button type="button" class="btn btn-secondary" x-on:click="editing = false">
                                        {{ __('lf.LF_common_button_cancel') }}
                                    </button>
                                    <button class="btn btn-primary" type="submit">{{ __('lf.LF_common_button_save') }}</button>
                                </div>
                            </footer>
                        </form>
                    </div>
                @endif

                {{-- Nodes --}}
                @if ($versionNodes->isEmpty())
                    <div class="learning-framework-empty-state learning-framework-empty-state--inline" role="status">
                        <strong>{{ __('lf.LF_learning_nodes_empty') }}</strong>
                        <span>{{ __('lf.LF_learning_nodes_empty_help') }}</span>
                    </div>
                @else
                    <ol class="learning-framework-node-list">
                        @foreach ($versionNodes as $node)
                            <li class="learning-framework-node" x-data="{ editing: false }">
                                <div class="learning-framework-node-summary">
                                    <span class="learning-framework-node-sequence">{{ $node->sequence }}</span>
                                    <div class="learning-framework-node-identity">
                                        <strong>{{ $node->name_snapshot }}</strong>
                                        <span class="learning-framework-index-meta">{{ $node->code_snapshot }}</span>
                                    </div>
                                    @if ($isDraft)
                                        <button type="button" class="admin-text-action" x-on:click="editing = ! editing"
                                                :aria-expanded="editing ? 'true' : 'false'">
                                            {{ __('lf.action_edit') }}
                                        </button>
                                    @endif
                                </div>

                                @if (filled($node->criteria_snapshot))
                                    <pre class="learning-framework-criteria" x-show="! editing">{{ json_encode(json_decode($node->criteria_snapshot, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                @endif

                                @if ($isDraft)
                                    <div x-show="editing" x-cloak class="learning-framework-inline-form">
                                        <form method="POST"
                                              action="{{ route('admin.learning-frameworks.nodes.update', [$framework->id, $version->id, $node->id]) }}">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="framework_version_id" value="{{ $version->id }}">
                                            @include('learning-frameworks.partials.node-fields', [
                                                'node' => $node,
                                                'editing' => true,
                                            ])
                                            <footer class="admin-form-footer" data-actions-align="end">
                                                <div class="admin-form-footer-primary">
                                                    <button type="button" class="btn btn-secondary" x-on:click="editing = false">
                                                        {{ __('lf.LF_common_button_cancel') }}
                                                    </button>
                                                    <button class="btn btn-primary" type="submit">
                                                        {{ __('lf.LF_common_button_save') }}
                                                    </button>
                                                </div>
                                            </footer>
                                        </form>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if ($isDraft)
                    <div class="learning-framework-version-actions">
                        <button type="button" class="btn btn-secondary" x-on:click="addingNode = ! addingNode"
                                :aria-expanded="addingNode ? 'true' : 'false'">
                            <span aria-hidden="true">+</span>
                            {{ __('lf.LF_learning_add_node') }}
                        </button>

                        <form method="POST"
                              action="{{ route('admin.learning-frameworks.versions.publish', [$framework->id, $version->id]) }}"
                              onsubmit="return confirm('{{ __('lf.LF_learning_publish_confirm') }}')">
                            @csrf
                            <button class="btn btn-primary" type="submit">{{ __('lf.LF_learning_publish') }}</button>
                        </form>
                    </div>

                    <p class="admin-form-inline-notice">
                        <span class="admin-form-inline-notice-icon" aria-hidden="true">i</span>
                        <span>
                            {{ __('lf.LF_learning_publish_help') }}
                            @if ($versionNodes->isEmpty())
                                {{ __('lf.LF_learning_publish_empty_warning') }}
                            @endif
                        </span>
                    </p>

                    <div x-show="addingNode" x-cloak class="learning-framework-inline-form">
                        <form method="POST"
                              action="{{ route('admin.learning-frameworks.nodes.store', [$framework->id, $version->id]) }}">
                            @csrf
                            <input type="hidden" name="framework_version_id" value="{{ $version->id }}">
                            @include('learning-frameworks.partials.node-fields', [
                                'node' => null,
                                'editing' => false,
                            ])
                            <footer class="admin-form-footer" data-actions-align="end">
                                <div class="admin-form-footer-primary">
                                    <button type="button" class="btn btn-secondary" x-on:click="addingNode = false">
                                        {{ __('lf.LF_common_button_cancel') }}
                                    </button>
                                    <button class="btn btn-primary" type="submit">{{ __('lf.LF_learning_add_node') }}</button>
                                </div>
                            </footer>
                        </form>
                    </div>
                @endif
            </article>
        @empty
            <div class="learning-framework-empty-state" role="status">
                <strong>{{ __('lf.LF_learning_versions_empty') }}</strong>
                <span>{{ __('lf.LF_learning_versions_empty_help') }}</span>
            </div>
        @endforelse
    </section>
@endsection
