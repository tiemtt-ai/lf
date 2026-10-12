@extends('layouts.backend')

@section('title', $framework->name)
@section('page_title', $framework->name)

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
        'content' => __('lf.LF_learning_simple_tab_content').' ('.$workingNodes->count().')',
        'scale' => __('lf.LF_learning_simple_tab_scale'),
        'history' => __('lf.LF_learning_simple_tab_history').' ('.$versions->count().')',
    ];
@endphp

@section('content')
    @include('learning-frameworks.partials.errors')

    <div class="lf-framework">
        <header class="lf-framework__header">
            <a class="admin-text-action" href="{{ route('admin.learning-frameworks.index') }}">{{ __('lf.LF_learning_back') }}</a>
            <div class="lf-framework__intro">
                @if ($framework->description)
                    <p class="lf-framework__description">{{ $framework->description }}</p>
                @endif
                <div class="lf-framework__status">
                    @if ($published)
                        <span class="badge badge-info" data-lf-framework-current>{{ __('lf.LF_learning_simple_status_current', ['number' => $published->version_number]) }}</span>
                    @else
                        <span class="badge badge-neutral">{{ __('lf.LF_learning_simple_status_unpublished') }}</span>
                    @endif
                    @unless ($isActive)
                        <span class="badge badge-neutral">{{ __('lf.LF_learning_status_archived') }}</span>
                    @endunless
                    <span class="lf-framework__meta">{{ trans_choice('lf.LF_learning_simple_count', $workingNodes->count(), ['count' => $workingNodes->count()]) }}</span>
                </div>
            </div>
        </header>

        <nav class="course-template-tabs lf-framework__tabs" aria-label="{{ __('lf.LF_learning_simple_tabs_label') }}">
            @foreach ($frameworkTabs as $key => $label)
                <a @class(['course-template-tab', 'is-active' => $tab === $key])
                   href="{{ $showUrl($key === 'content' ? [] : ['tab' => $key]) }}"
                   @if ($tab === $key) aria-current="page" @endif data-lf-framework-tab="{{ $key }}">{{ $label }}</a>
            @endforeach
        </nav>

        @if ($tab === 'content')
            @if ($isActive)
                <div class="lf-framework__actions">
                    <details x-data class="lf-framework__disclosure">
                        <summary class="btn btn-secondary">{{ __('lf.LF_learning_simple_edit_framework') }}</summary>
                        <form class="lf-framework__panel" method="POST" action="{{ route('admin.learning-frameworks.update', $framework->id) }}">
                            @csrf
                            @method('PUT')
                            {{-- Only name and description change here; the code and default scale travel unchanged. --}}
                            <input type="hidden" name="code" value="{{ $framework->code }}">
                            <input type="hidden" name="mastery_scale_key" value="{{ $framework->default_mastery_scale_key }}">
                            <input type="hidden" name="mastery_scale_version" value="{{ $framework->default_mastery_scale_version }}">
                            @foreach ($masteryScale['levels'] as $i => $level)
                                <input type="hidden" name="mastery_scale[levels][{{ $i }}][key]" value="{{ $level['key'] }}">
                                <input type="hidden" name="mastery_scale[levels][{{ $i }}][threshold]" value="{{ $level['threshold'] }}">
                            @endforeach
                            <div class="lf-form-group">
                                <x-form-label for="framework-name" :value="__('lf.LF_learning_name')" required />
                                <input id="framework-name" name="name" class="lf-form-control" required maxlength="255" value="{{ $framework->name }}">
                            </div>
                            <div class="lf-form-group">
                                <x-form-label for="framework-description" :value="__('lf.LF_learning_description')" />
                                <textarea id="framework-description" name="description" class="lf-form-control" rows="3" maxlength="5000">{{ $framework->description }}</textarea>
                            </div>
                            <div class="lf-framework__panel-actions">
                                @include('learning-frameworks.partials.cancel')
                                <button class="btn btn-primary" type="submit">{{ __('lf.LF_common_button_save') }}</button>
                            </div>
                        </form>
                    </details>

                    <details x-data class="lf-framework__disclosure" @if ($workingNodes->isEmpty() && $unplaced->isEmpty()) open @endif>
                        <summary class="btn admin-primary-outline-action"><span aria-hidden="true">+</span> {{ __('lf.LF_learning_simple_add') }}</summary>
                        <form class="lf-framework__panel" method="POST" action="{{ route('admin.learning-frameworks.competencies.store', $framework->id) }}" data-lf-framework-add>
                            @csrf
                            <p class="lf-form-help">{{ __('lf.LF_learning_simple_add_help') }}</p>
                            <div class="lf-form-group">
                                <x-form-label for="competency-name" :value="__('lf.LF_learning_simple_field_name')" required />
                                <input id="competency-name" name="canonical_name" class="lf-form-control" required maxlength="255" value="{{ old('canonical_name') }}">
                            </div>
                            <div class="lf-framework__field-row">
                                <div class="lf-form-group">
                                    <x-form-label for="competency-type" :value="__('lf.LF_learning_type')" required />
                                    <select id="competency-type" name="node_type" class="lf-form-control">
                                        @foreach (['competency', 'objective', 'concept'] as $type)
                                            <option value="{{ $type }}" @selected(old('node_type', 'competency') === $type)>{{ $typeLabel($type) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="lf-form-group">
                                    <x-form-label for="competency-code" :value="__('lf.LF_learning_simple_field_code')" />
                                    <input id="competency-code" name="code" class="lf-form-control" maxlength="120" autocomplete="off" value="{{ old('code') }}">
                                </div>
                            </div>
                            <div class="lf-form-group">
                                <x-form-label for="competency-description" :value="__('lf.LF_learning_description')" />
                                <textarea id="competency-description" name="description" class="lf-form-control" rows="3" maxlength="5000">{{ old('description') }}</textarea>
                            </div>
                            <div class="lf-framework__panel-actions">
                                @include('learning-frameworks.partials.cancel')
                                <button class="btn btn-primary" type="submit">{{ __('lf.LF_learning_simple_add') }}</button>
                            </div>
                        </form>
                    </details>
                </div>
            @endif

            @if ($draft)
                <section class="lf-framework__draft" aria-labelledby="lf-framework-draft-title" data-lf-framework-draft="{{ $draft->id }}">
                    <div class="lf-framework__draft-text">
                        <h2 id="lf-framework-draft-title" class="lf-framework__draft-title">
                            {{ $published ? __('lf.LF_learning_simple_draft_title') : __('lf.LF_learning_simple_draft_first_title') }}
                        </h2>
                        @if ($added->isNotEmpty())
                            <p>{{ __('lf.LF_learning_simple_draft_added', ['names' => $added->pluck('name_snapshot')->implode(', ')]) }}</p>
                        @endif
                        @if ($removed->isNotEmpty())
                            <p>{{ __('lf.LF_learning_simple_draft_removed', ['names' => $removed->pluck('name_snapshot')->implode(', ')]) }}</p>
                        @endif
                        @if ($published && $added->isEmpty() && $removed->isEmpty())
                            <p>{{ __('lf.LF_learning_simple_draft_same') }}</p>
                        @endif
                        @if ($published)
                            <p class="lf-framework__draft-note">{{ __('lf.LF_learning_simple_draft_note') }}</p>
                        @endif
                    </div>
                    @if ($isActive)
                        <div class="lf-framework__draft-actions">
                            <form method="POST"
                                  action="{{ route('admin.learning-frameworks.versions.destroy', [$framework->id, $draft->id]) }}"
                                  data-lf-confirm="{{ __('lf.LF_learning_simple_discard_confirm', ['number' => $draft->version_number]) }}"
                                  data-lf-confirm-label="{{ __('lf.LF_learning_simple_discard') }}"
                                  data-lf-confirm-tone="danger"
                                  data-lf-framework-discard>
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-secondary">{{ __('lf.LF_learning_simple_discard') }}</button>
                            </form>
                            @if ($draftNodes->isNotEmpty())
                                <form method="POST"
                                      action="{{ route('admin.learning-frameworks.versions.publish', [$framework->id, $draft->id]) }}"
                                      data-lf-confirm="{{ __('lf.LF_learning_simple_publish_confirm') }}"
                                      data-lf-confirm-label="{{ __('lf.LF_learning_simple_publish', ['number' => $draft->version_number]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_learning_simple_publish', ['number' => $draft->version_number]) }}</button>
                                </form>
                            @endif
                        </div>
                    @endif
                </section>
            @endif

            <section class="admin-card lf-framework__list" aria-labelledby="lf-framework-list-title"
                     x-data="{
                         keyword: '',
                         nodes: @js($workingNodes->map(fn ($node) => ['name' => $node->name_snapshot, 'code' => $node->code_snapshot])->values()),
                         normalize(value) { return String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLocaleLowerCase(); },
                         matches(name, code) { const term = this.normalize(this.keyword.trim()); return this.normalize(name).includes(term) || this.normalize(code).includes(term); },
                         get resultCount() { return this.nodes.filter(node => this.matches(node.name, node.code)).length; }
                     }">
                <h2 id="lf-framework-list-title" class="admin-form-section-title">{{ __('lf.LF_learning_simple_list_title') }}</h2>

                @if ($workingNodes->isNotEmpty())
                    <form class="lf-framework-index__filter-grid lf-framework__search" role="search" @submit.prevent>
                        <div class="lf-form-group">
                            <label class="lf-form-label" for="competency-keyword">{{ __('lf.LF_common_button_search') }}</label>
                            <input id="competency-keyword" type="search" class="lf-form-control" x-model="keyword" maxlength="255"
                                   placeholder="{{ __('lf.LF_learning_competency_search_placeholder') }}">
                        </div>
                        <div class="admin-form-actions lf-framework-index__filter-actions">
                            <button type="button" class="admin-link-button admin-text-action" x-show="keyword !== ''" x-cloak
                                    @click="keyword = ''; $nextTick(() => document.getElementById('competency-keyword').focus())">{{ __('lf.LF_learning_index_clear') }}</button>
                            <span class="lf-framework__meta" role="status" aria-live="polite"
                                  x-text="`${resultCount} / ${nodes.length}`"></span>
                        </div>
                    </form>
                    <div class="admin-form-empty-state" role="status" x-show="resultCount === 0" x-cloak>
                        <strong>{{ __('lf.LF_learning_competency_search_empty') }}</strong>
                        <span>{{ __('lf.LF_learning_competency_search_help') }}</span>
                    </div>
                @endif

                @forelse ($workingNodes as $node)
                    @php
                        $definition = $definitionsById->get((int) $node->node_definition_id);
                        $isNew = $draft && ! $publishedDefinitionIds->contains((int) $node->node_definition_id);
                    @endphp
                    <article class="lf-framework__item" data-lf-framework-item="{{ $node->node_definition_id }}"
                             x-show="matches(@js($node->name_snapshot), @js($node->code_snapshot))">
                        <div class="lf-framework__item-main">
                            <div class="lf-framework__item-title">
                                <strong>{{ $node->name_snapshot }}</strong>
                                <span class="badge badge-info">{{ $typeLabel($definition->node_type ?? 'competency') }}</span>
                                @if ($isNew)
                                    <span class="badge badge-warning">{{ __('lf.LF_learning_simple_new') }}</span>
                                @endif
                            </div>
                            @if ($node->description_snapshot)
                                <p class="lf-framework__item-description">{{ $node->description_snapshot }}</p>
                            @endif
                            <span class="lf-framework__meta">{{ __('lf.LF_learning_simple_code', ['code' => $node->code_snapshot]) }}</span>
                        </div>
                        @if ($definition && $isActive && $definition->status === 'active')
                            @include('learning-frameworks.partials.competency-edit', ['definition' => $definition])
                        @endif
                    </article>
                @empty
                    <div class="admin-form-empty-state" role="status">
                        <strong>{{ __('lf.LF_learning_simple_empty') }}</strong>
                        <span>{{ __('lf.LF_learning_simple_empty_help') }}</span>
                    </div>
                @endforelse
            </section>

            @if ($unplaced->isNotEmpty())
                <section class="admin-card lf-framework__list" aria-labelledby="lf-framework-unplaced-title">
                    <h2 id="lf-framework-unplaced-title" class="admin-form-section-title">{{ __('lf.LF_learning_simple_unplaced_title') }}</h2>
                    <p class="admin-form-section-help">{{ __('lf.LF_learning_simple_unplaced_help') }}</p>
                    @foreach ($unplaced as $definition)
                        <article class="lf-framework__item" data-lf-framework-unplaced="{{ $definition->id }}">
                            <div class="lf-framework__item-main">
                                <div class="lf-framework__item-title">
                                    <strong>{{ $definition->canonical_name }}</strong>
                                    <span class="badge badge-info">{{ $typeLabel($definition->node_type) }}</span>
                                </div>
                                <span class="lf-framework__meta">{{ __('lf.LF_learning_simple_code', ['code' => $definition->code]) }}</span>
                            </div>
                            @if ($isActive)
                                <form method="POST" action="{{ route('admin.learning-frameworks.competencies.store', $framework->id) }}">
                                    @csrf
                                    <input type="hidden" name="node_definition_id" value="{{ $definition->id }}">
                                    <button type="submit" class="btn btn-secondary">{{ __('lf.LF_learning_simple_unplaced_add') }}</button>
                                </form>
                            @endif
                        </article>
                    @endforeach
                </section>
            @endif
        @elseif ($tab === 'scale')
            <section class="admin-card lf-framework__list" aria-labelledby="lf-framework-scale-title" data-lf-framework-scale>
                <h2 id="lf-framework-scale-title" class="admin-form-section-title">{{ __('lf.LF_learning_simple_scale_title') }}</h2>
                <p class="admin-form-section-help">{{ __('lf.LF_learning_simple_scale_current_help') }}</p>
                <ol class="lf-framework__levels">
                    @foreach ($workingLevels as $level)
                        <li>
                            <span>{{ $levelLabel($level['key']) }}</span>
                            <span class="lf-framework__meta">{{ (float) $level['threshold'] > 0 ? '≥ '.round((float) $level['threshold'] * 100).'%' : '0%' }}</span>
                        </li>
                    @endforeach
                </ol>
                @if ($nextLevelsDiffer)
                    <p class="admin-form-inline-notice" data-lf-framework-scale-next>
                        <span class="admin-form-inline-notice-icon" aria-hidden="true">i</span>
                        <span>{{ __('lf.LF_learning_simple_scale_next', ['levels' => collect($masteryScale['levels'])->map($levelText)->implode(' · ')]) }}</span>
                    </p>
                @endif
                @if ($isActive)
                    @include('learning-frameworks.partials.scale-edit')
                @endif
            </section>
        @else
            <section class="admin-card lf-framework__list" aria-labelledby="lf-framework-history-title" data-lf-framework-history x-data="{ expanded: @js($viewedVersion && $viewedVersion->status !== 'draft_snapshot' ? (int) $viewedVersion->id : null) }">
                <h2 id="lf-framework-history-title" class="admin-form-section-title">{{ __('lf.LF_learning_simple_history_title') }}</h2>
                <p class="admin-form-section-help">{{ __('lf.LF_learning_simple_history_help') }}</p>
                <ul class="lf-framework__history">
                    @forelse ($versions as $version)
                        @php
                            $isViewed = $viewedVersion && $viewedVersion->id === $version->id;
                            $versionCount = $activeNodes($version)->count();
                        @endphp
                        <li @class(['is-viewed' => $isViewed]) :class="{ 'is-viewed': expanded === {{ (int) $version->id }} }" data-lf-framework-version="{{ $version->id }}">
                            <div class="lf-framework__history-main">
                                <strong>{{ __('lf.LF_learning_simple_version', ['number' => $version->version_number]) }}</strong>
                                @if ($published && $version->id === $published->id)
                                    <span class="badge badge-info">{{ __('lf.LF_learning_simple_version_latest') }}</span>
                                @else
                                    <span class="badge {{ $version->status === 'published' ? 'badge-neutral' : ($version->status === 'draft_snapshot' ? 'badge-warning' : 'badge-neutral') }}">{{ __('lf.LF_learning_version_status_'.$version->status) }}</span>
                                @endif
                                <span class="lf-framework__meta">
                                    {{ $version->version_code }}
                                    @if ($version->published_at)
                                        · {{ $version->published_by_name
                                            ? __('lf.LF_learning_simple_published_by', ['date' => \Illuminate\Support\Carbon::parse($version->published_at)->format('d/m/Y'), 'name' => $version->published_by_name])
                                            : __('lf.LF_learning_simple_published_on', ['date' => \Illuminate\Support\Carbon::parse($version->published_at)->format('d/m/Y')]) }}
                                    @endif
                                    · {{ trans_choice('lf.LF_learning_simple_count', $versionCount, ['count' => $versionCount]) }}
                                </span>
                            </div>
                            @if ($version->status === 'draft_snapshot')
                                <a class="btn btn-secondary" href="{{ $showUrl() }}">{{ __('lf.LF_learning_simple_version_open_draft') }}</a>
                            @else
                                <a class="btn btn-secondary" href="{{ $showUrl(['version' => $version->id]) }}"
                                   @click.prevent="expanded = expanded === {{ (int) $version->id }} ? null : {{ (int) $version->id }}"
                                   :aria-expanded="expanded === {{ (int) $version->id }}" aria-controls="framework-version-panel-{{ $version->id }}"
                                   x-text="expanded === {{ (int) $version->id }} ? @js(__('lf.LF_common_button_close')) : @js(__('lf.LF_learning_simple_version_view'))">{{ __('lf.LF_learning_simple_version_view') }}</a>
                                @include('learning-frameworks.partials.version-view')
                            @endif
                        </li>
                    @empty
                        <li class="lf-framework__meta">{{ __('lf.LF_learning_versions_empty') }}</li>
                    @endforelse
                </ul>

            </section>
        @endif
    </div>
@endsection
