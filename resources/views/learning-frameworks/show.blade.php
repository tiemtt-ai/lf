@extends('layouts.backend')

@section('title', $framework->name)
@section('page_title', $framework->name)

@section('page_title_hidden', '1')

@section('page_back')
    <x-page-back-link :href="route('admin.learning-frameworks.index')" :label="__('lf.LF_learning_index_title')" />
@endsection

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js'])
@endsection

@php
    // One working set: the open draft when there is one, otherwise the newest published Version.
    $published = $versions->firstWhere('status', 'published');
    $draft = $versions->firstWhere('status', 'draft_snapshot');
    $activeNodes = fn ($version) => $version === null
        ? collect()
        : $nodesByVersion->get($version->id, collect())->where('status', 'active')->sortBy('sequence')->values();
    $publishedNodes = $activeNodes($published);
    $draftNodes = $activeNodes($draft);
    $workingNodes = $draft ? $draftNodes : $publishedNodes;
    $definitionsById = $definitions->keyBy('id');
    $publishedDefinitionIds = $publishedNodes->pluck('node_definition_id')->map(fn ($id) => (int) $id);
    $workingDefinitionIds = $workingNodes->pluck('node_definition_id')->map(fn ($id) => (int) $id);
    $added = $draft ? $draftNodes->reject(fn ($node) => $publishedDefinitionIds->contains((int) $node->node_definition_id)) : collect();
    $removed = $draft ? $publishedNodes->reject(fn ($node) => $workingDefinitionIds->contains((int) $node->node_definition_id)) : collect();
    $unplaced = $definitions->where('status', 'active')
        ->reject(fn ($definition) => $workingDefinitionIds->contains((int) $definition->id))->values();
    $nextNumber = $draft ? (int) $draft->version_number : 1 + (int) $versions->max('version_number');
    $isActive = $framework->status === 'active';
    $typeLabel = fn (string $type) => __('lf.LF_learning_simple_type_'.$type);
    $levelLabel = fn (string $key) => \Illuminate\Support\Facades\Lang::has('lf.LF_learning_simple_level_'.$key)
        ? __('lf.LF_learning_simple_level_'.$key)
        : $key;
    // A Version keeps the scale frozen when it was created; the framework default only feeds the next one.
    $versionLevels = fn ($version) => $version === null
        ? $masteryScale['levels']
        : (json_decode((string) $version->mastery_scale_snapshot, true)['levels'] ?? []);
    $levelText = fn (array $level) => (float) $level['threshold'] > 0
        ? __('lf.LF_learning_simple_scale_level', ['label' => $levelLabel($level['key']), 'percent' => round((float) $level['threshold'] * 100)])
        : $levelLabel($level['key']);
    $levelSignature = fn (array $levels) => collect($levels)->map(fn ($l) => $l['key'].'@'.(float) $l['threshold'])->implode('|');
    $workingVersion = $draft ?? $published;
    $workingLevels = $versionLevels($workingVersion);
    $nextLevelsDiffer = $workingVersion !== null && $levelSignature($workingLevels) !== $levelSignature($masteryScale['levels']);
    $showUrl = fn (array $query = []) => route('admin.learning-frameworks.show', [$framework->id, ...$query]);
    $frameworkTabs = [
        'content' => [__('lf.LF_learning_simple_tab_content'), $workingNodes->count()],
        'scale' => [__('lf.LF_learning_simple_tab_scale'), null],
        'history' => [__('lf.LF_learning_simple_tab_history'), $versions->count()],
    ];
@endphp

@section('content')
<div id="framework-detail" class="lf-admin-ui lf-admin-ui--list ui-framework" data-ui-dialog-form-scope>
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

    <section class="mk-section ui-framework__identity" aria-labelledby="framework-identity-title">
        <div class="ui-version__identity">
            <p class="ui-version__eyebrow">{{ $framework->code }}</p>
            <h2 id="framework-identity-title">{{ $framework->name }}</h2>
            @if ($framework->description)
                <p class="ui-caption">{{ $framework->description }}</p>
            @endif
            <div class="ui-version__chips">
                @if ($published)
                    <span class="ui-status ui-status--active" data-lf-framework-current>{{ __('lf.LF_learning_simple_status_current', ['number' => $published->version_number]) }}</span>
                @else
                    <span class="ui-status ui-status--archived">{{ __('lf.LF_learning_simple_status_unpublished') }}</span>
                @endif
                @unless ($isActive)
                    <span class="ui-status ui-status--archived">{{ __('lf.LF_learning_status_archived') }}</span>
                @endunless
            </div>
        </div>
        @if ($isActive)
            <button type="button" class="ui-button ui-button--outline cursor-interaction" data-ui-dialog-form-open aria-haspopup="dialog"
                    data-url="{{ route('admin.learning-frameworks.form', $framework->id) }}">
                <x-backend-icon name="square-pen" class="ui-icon" />
                {{ __('lf.LF_learning_simple_edit_framework') }}
            </button>
        @endif
    </section>

    <nav class="course-template-tabs" aria-label="{{ __('lf.LF_learning_simple_tabs_label') }}">
        @foreach ($frameworkTabs as $key => [$label, $count])
            <a @class(['course-template-tab', 'is-active' => $tab === $key])
               href="{{ $showUrl($key === 'content' ? [] : ['tab' => $key]) }}"
               @if ($tab === $key) aria-current="page" @endif data-lf-framework-tab="{{ $key }}">{{ $label }}@if ($count !== null) <span class="ui-tab-count">{{ $count }}</span>@endif</a>
        @endforeach
    </nav>

    @if ($tab === 'content')
        @if ($draft)
            <section class="mk-section ui-framework__draft" aria-labelledby="lf-framework-draft-title" data-lf-framework-draft="{{ $draft->id }}">
                <div>
                    <h2 id="lf-framework-draft-title">{{ $published ? __('lf.LF_learning_simple_draft_title') : __('lf.LF_learning_simple_draft_first_title') }}</h2>
                    @if ($added->isNotEmpty())
                        <p class="ui-caption">{{ __('lf.LF_learning_simple_draft_added', ['names' => $added->pluck('name_snapshot')->implode(', ')]) }}</p>
                    @endif
                    @if ($removed->isNotEmpty())
                        <p class="ui-caption">{{ __('lf.LF_learning_simple_draft_removed', ['names' => $removed->pluck('name_snapshot')->implode(', ')]) }}</p>
                    @endif
                    @if ($published && $added->isEmpty() && $removed->isEmpty())
                        <p class="ui-caption">{{ __('lf.LF_learning_simple_draft_same') }}</p>
                    @endif
                    @if ($published)
                        <p class="ui-caption">{{ __('lf.LF_learning_simple_draft_note') }}</p>
                    @endif
                </div>
                @if ($isActive)
                    <div class="ui-framework__draft-actions">
                        <form method="POST"
                              action="{{ route('admin.learning-frameworks.versions.destroy', [$framework->id, $draft->id]) }}"
                              data-lf-confirm="{{ __('lf.LF_learning_simple_discard_confirm', ['number' => $draft->version_number]) }}"
                              data-lf-confirm-label="{{ __('lf.LF_learning_simple_discard') }}"
                              data-lf-confirm-tone="danger"
                              data-lf-framework-discard>
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ui-button ui-button--danger-outline cursor-interaction">{{ __('lf.LF_learning_simple_discard') }}</button>
                        </form>
                        @if ($draftNodes->isNotEmpty())
                            <form method="POST"
                                  action="{{ route('admin.learning-frameworks.versions.publish', [$framework->id, $draft->id]) }}"
                                  data-lf-confirm="{{ __('lf.LF_learning_simple_publish_confirm') }}"
                                  data-lf-confirm-label="{{ __('lf.LF_learning_simple_publish', ['number' => $draft->version_number]) }}">
                                @csrf
                                <button type="submit" class="ui-button ui-button--primary cursor-interaction">{{ __('lf.LF_learning_simple_publish', ['number' => $draft->version_number]) }}</button>
                            </form>
                        @endif
                    </div>
                @endif
            </section>
        @endif

        <section class="mk-section" aria-label="{{ __('lf.LF_learning_simple_list_title') }}"
                 x-data="{
                     keyword: '', nodeType: '',
                     nodes: @js($workingNodes->map(fn ($node) => ['name' => $node->name_snapshot, 'code' => $node->code_snapshot, 'type' => $definitionsById->get((int) $node->node_definition_id)->node_type ?? 'competency'])->values()),
                     normalize(value) { return String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLocaleLowerCase(); },
                     matches(name, code, type) { const term = this.normalize(this.keyword.trim()); return (!this.nodeType || type === this.nodeType) && (this.normalize(name).includes(term) || this.normalize(code).includes(term)); },
                     get resultCount() { return this.nodes.filter(node => this.matches(node.name, node.code, node.type)).length; }
                 }">
            <div class="mk-tablebar mk-tablebar--filters">
                <form class="mk-filter-form" role="search" @submit.prevent>
                    @include('learning-frameworks.partials.competency-filter', ['searchId' => 'competency-keyword'])
                </form>
                @if ($isActive)
                    <div class="mk-table-top__actions">
                        <button type="button" class="ui-button ui-button--outline cursor-interaction" data-ui-dialog-form-open aria-haspopup="dialog"
                                data-url="{{ route('admin.learning-frameworks.competencies.create', $framework->id) }}">
                            <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                            {{ __('lf.LF_learning_simple_add') }}
                        </button>
                    </div>
                @endif
            </div>

            @include('learning-frameworks.partials.competency-table', [
                'nodes' => $workingNodes,
                'marker' => 'data-lf-framework-item',
                'canEdit' => $isActive,
                'newIds' => $added->pluck('node_definition_id')->map(fn ($id) => (int) $id),
                'retireNumber' => $isActive ? $nextNumber : null,
            ])

            <div class="mk-table-foot">
                <span class="mk-footnote" role="status" aria-live="polite" x-text="`${resultCount} / ${nodes.length} ` + @js(__('lf.LF_learning_simple_count_unit'))"></span>
            </div>
        </section>

        @if ($unplaced->isNotEmpty())
            <section class="mk-section" aria-labelledby="lf-framework-unplaced-title">
                <div class="ui-framework__section-head">
                    <h2 id="lf-framework-unplaced-title">{{ __('lf.LF_learning_simple_unplaced_title') }}</h2>
                    <p class="ui-caption">{{ __('lf.LF_learning_simple_unplaced_help') }}</p>
                </div>
                <div class="mk-table-scroll" role="region" aria-labelledby="lf-framework-unplaced-title" tabindex="0">
                    <table aria-labelledby="lf-framework-unplaced-title">
                        <colgroup>
                            <col class="mk-col-sequence">
                            <col>
                            <col class="ui-framework-col-code">
                            <col class="mk-col-status">
                            <col class="ui-framework-col-add">
                        </colgroup>
                        <thead>
                        <tr>
                            <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                            <th scope="col" class="mk-text-cell">{{ __('lf.LF_learning_simple_field_name') }}</th>
                            <th scope="col" class="mk-text-cell">{{ __('lf.LF_learning_code') }}</th>
                            <th scope="col" class="ux-action-cell">{{ __('lf.LF_learning_type') }}</th>
                            <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($unplaced as $definition)
                            <tr data-lf-framework-unplaced="{{ $definition->id }}">
                                <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                                <td class="mk-text-cell" data-label="{{ __('lf.LF_learning_simple_field_name') }}"><span class="mk-name-text">{{ $definition->canonical_name }}</span></td>
                                <td class="mk-text-cell ui-framework-code" data-label="{{ __('lf.LF_learning_code') }}">{{ $definition->code }}</td>
                                <td class="ux-action-cell" data-label="{{ __('lf.LF_learning_type') }}"><span class="ui-status ui-status--draft">{{ $typeLabel($definition->node_type) }}</span></td>
                                <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                    @if ($isActive)
                                        <form method="POST" action="{{ route('admin.learning-frameworks.competencies.store', $framework->id) }}">
                                            @csrf
                                            <input type="hidden" name="node_definition_id" value="{{ $definition->id }}">
                                            <button type="submit" class="ui-button ui-button--outline cursor-interaction">{{ __('lf.LF_learning_simple_unplaced_add') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @elseif ($tab === 'scale')
        <section class="mk-section" aria-labelledby="lf-framework-scale-title" data-lf-framework-scale>
            <div class="ui-framework__section-head ui-framework__section-head--actions">
                <div>
                    <h2 id="lf-framework-scale-title">{{ __('lf.LF_learning_simple_scale_title') }}</h2>
                    <p class="ui-caption">{{ __('lf.LF_learning_simple_scale_current_help') }}</p>
                </div>
                @if ($isActive)
                    <button type="button" class="ui-button ui-button--outline cursor-interaction" data-ui-dialog-form-open aria-haspopup="dialog"
                            data-url="{{ route('admin.learning-frameworks.scale.form', $framework->id) }}">
                        <x-backend-icon name="square-pen" class="ui-icon" />
                        {{ __('lf.LF_learning_simple_scale_edit') }}
                    </button>
                @endif
            </div>
            @if ($nextLevelsDiffer)
                <p class="ui-framework__note" data-lf-framework-scale-next>{{ __('lf.LF_learning_simple_scale_next', ['levels' => collect($masteryScale['levels'])->map($levelText)->implode(' · ')]) }}</p>
            @endif
            <div class="mk-table-scroll" role="region" aria-labelledby="lf-framework-scale-title" tabindex="0">
                <table aria-labelledby="lf-framework-scale-title">
                    <colgroup>
                        <col class="mk-col-sequence">
                        <col>
                        <col>
                        <col class="ui-framework-col-code">
                    </colgroup>
                    <thead>
                    <tr>
                        <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_learning_simple_scale_col_level') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_learning_simple_scale_level_code') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_learning_threshold_percent') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($workingLevels as $level)
                        <tr>
                            <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_learning_simple_scale_col_level') }}"><span class="mk-name-text">{{ $levelLabel($level['key']) }}</span></td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_learning_simple_scale_level_code') }}">{{ $level['key'] }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_learning_threshold_percent') }}">{{ (float) $level['threshold'] > 0 ? '≥ '.round((float) $level['threshold'] * 100).'%' : '0%' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @else
        <section class="mk-section" aria-labelledby="lf-framework-history-title" data-lf-framework-history x-data="{ expanded: @js($viewedVersion && $viewedVersion->status !== 'draft_snapshot' ? (int) $viewedVersion->id : null) }">
            <div class="ui-framework__section-head">
                <h2 id="lf-framework-history-title">{{ __('lf.LF_learning_simple_history_title') }}</h2>
                <p class="ui-caption">{{ __('lf.LF_learning_simple_history_help') }}</p>
            </div>
            <div class="mk-table-scroll" role="region" aria-labelledby="lf-framework-history-title" tabindex="0">
                <table aria-labelledby="lf-framework-history-title">
                    <colgroup>
                        <col class="mk-col-sequence">
                        <col>
                        <col class="ui-framework-col-date">
                        <col>
                        <col class="ui-framework-col-count">
                        <col class="mk-col-status">
                        <col class="mk-col-action">
                    </colgroup>
                    <thead>
                    <tr>
                        <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_learning_versions') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_learning_simple_history_published_at') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_learning_simple_history_published_by') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_learning_simple_history_count') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.LF_learning_status') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($versions as $version)
                        <tr data-lf-framework-version="{{ $version->id }}">
                            <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_learning_versions') }}">
                                <span class="mk-name-text">{{ __('lf.LF_learning_simple_version', ['number' => $version->version_number]) }}</span>
                                <span class="ui-caption ui-framework-description">{{ $version->version_code }}</span>
                            </td>
                            <td @class(['mk-text-cell', 'mk-na' => ! $version->published_at]) data-label="{{ __('lf.LF_learning_simple_history_published_at') }}">
                                {{ $version->published_at ? \Illuminate\Support\Carbon::parse($version->published_at)->format('d/m/Y H:i') : '—' }}
                            </td>
                            <td @class(['mk-text-cell', 'mk-na' => ! $version->published_by_name]) data-label="{{ __('lf.LF_learning_simple_history_published_by') }}">{{ $version->published_by_name ?: '—' }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_learning_simple_history_count') }}">{{ $activeNodes($version)->count() }}</td>
                            <td class="ux-action-cell" data-label="{{ __('lf.LF_learning_status') }}">
                                @if ($published && $version->id === $published->id)
                                    <span class="ui-status ui-status--active">{{ __('lf.LF_learning_simple_version_latest') }}</span>
                                @else
                                    <span class="ui-status {{ $version->status === 'draft_snapshot' ? 'ui-status--draft' : 'ui-status--archived' }}">{{ __('lf.LF_learning_version_status_'.$version->status) }}</span>
                                @endif
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                @if ($version->status === 'draft_snapshot')
                                    <a class="ux-row-edit cursor-interaction" href="{{ $showUrl() }}"
                                       aria-label="{{ __('lf.LF_learning_simple_version_open_draft') }}"
                                       data-tooltip="{{ __('lf.LF_learning_simple_version_open_draft') }}">
                                        <x-backend-icon name="square-pen" class="ui-icon" />
                                    </a>
                                @else
                                    <a class="ux-row-edit cursor-interaction" href="{{ $showUrl(['version' => $version->id]) }}" aria-haspopup="dialog"
                                       @click.prevent="expanded = {{ (int) $version->id }}" aria-controls="framework-version-panel-{{ $version->id }}"
                                       aria-label="{{ __('lf.LF_learning_simple_version_view') }}: {{ __('lf.LF_learning_simple_version', ['number' => $version->version_number]) }}"
                                       data-tooltip="{{ __('lf.LF_learning_simple_version_view') }}">
                                        <x-backend-icon name="eye" class="ui-icon" />
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr class="mk-empty-row">
                            <td class="mk-empty" colspan="7"><div role="status"><strong>{{ __('lf.LF_learning_versions_empty') }}</strong></div></td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @foreach ($versions->where('status', '!=', 'draft_snapshot') as $version)
                @include('learning-frameworks.partials.version-view')
            @endforeach
        </section>
    @endif

    <dialog class="ui-modal" data-columns="2" aria-labelledby="framework-dialog-title" data-ui-dialog-form
            data-msg-load-error="{{ __('lf.LF_learning_dialog_load_error') }}"
            data-msg-discard-title="{{ __('lf.LF_learning_dialog_discard_title') }}"
            data-msg-discard-text="{{ __('lf.LF_learning_dialog_discard_text') }}"
            data-msg-discard="{{ __('lf.LF_learning_dialog_discard') }}"
            data-msg-save-error="{{ __('lf.LF_learning_dialog_save_error') }}"
            data-msg-session-expired="{{ __('lf.LF_learning_session_expired') }}"
            data-msg-required-input="{{ __('lf.LF_learning_dialog_required_input') }}"
            data-msg-required-select="{{ __('lf.LF_learning_dialog_required_input') }}"></dialog>
</div>
@endsection
