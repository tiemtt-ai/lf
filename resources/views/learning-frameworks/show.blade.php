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
@endphp

@section('content')
    @include('learning-frameworks.partials.errors')

    <div class="lf-framework">
        <header class="lf-framework__header">
            <div class="lf-framework__intro">
                @if ($framework->description)
                    <p class="lf-framework__description">{{ $framework->description }}</p>
                @endif
                <div class="lf-framework__status">
                    @if ($published)
                        <span class="badge badge-success" data-lf-framework-current>{{ __('lf.LF_learning_simple_status_current', ['number' => $published->version_number]) }}</span>
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

        @if ($isActive)
            <div class="lf-framework__actions">
                <details class="lf-framework__disclosure">
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
                            <button class="btn btn-primary" type="submit">{{ __('lf.LF_common_button_save') }}</button>
                        </div>
                    </form>
                </details>

                <details class="lf-framework__disclosure" @if ($workingNodes->isEmpty() && $unplaced->isEmpty()) open @endif>
                    <summary class="btn btn-primary"><span aria-hidden="true">+</span> {{ __('lf.LF_learning_simple_add') }}</summary>
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

        <section class="admin-card lf-framework__list" aria-labelledby="lf-framework-list-title">
            <h2 id="lf-framework-list-title" class="admin-form-section-title">{{ __('lf.LF_learning_simple_list_title') }}</h2>

            @forelse ($workingNodes as $node)
                @php
                    $definition = $definitionsById->get((int) $node->node_definition_id);
                    $isNew = $draft && ! $publishedDefinitionIds->contains((int) $node->node_definition_id);
                @endphp
                <article class="lf-framework__item" data-lf-framework-item="{{ $node->node_definition_id }}">
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

        <details class="admin-card lf-framework__advanced" @if ($errors->hasAny(['mastery_scale', 'mastery_scale.*'])) open @endif>
            <summary class="lf-framework__advanced-summary">{{ __('lf.LF_learning_simple_advanced') }}</summary>
            <div class="lf-framework__advanced-body">
                <section aria-labelledby="lf-framework-scale-title">
                    <h3 id="lf-framework-scale-title" class="admin-form-subsection-title">{{ __('lf.LF_learning_simple_scale_title') }}</h3>
                    <ol class="lf-framework__scale">
                        @foreach ($masteryScale['levels'] as $level)
                            <li>{{ (float) $level['threshold'] > 0
                                ? __('lf.LF_learning_simple_scale_level', ['label' => $levelLabel($level['key']), 'percent' => round((float) $level['threshold'] * 100)])
                                : $levelLabel($level['key']) }}</li>
                        @endforeach
                    </ol>
                    @if ($isActive)
                        @include('learning-frameworks.partials.scale-edit')
                    @endif
                </section>
                <section aria-labelledby="lf-framework-history-title">
                    <h3 id="lf-framework-history-title" class="admin-form-subsection-title">{{ __('lf.LF_learning_simple_history_title') }}</h3>
                    <ul class="lf-framework__history">
                        @forelse ($versions as $version)
                            <li>
                                <strong>{{ __('lf.LF_learning_simple_version', ['number' => $version->version_number]) }}</strong>
                                <span class="lf-framework__meta">{{ $version->title_snapshot }}</span>
                                <span class="badge {{ $version->status === 'published' ? 'badge-success' : ($version->status === 'draft_snapshot' ? 'badge-warning' : 'badge-neutral') }}">{{ __('lf.LF_learning_version_status_'.$version->status) }}</span>
                                @if ($version->published_at)
                                    <span class="lf-framework__meta">{{ __('lf.LF_learning_simple_published_on', ['date' => \Illuminate\Support\Carbon::parse($version->published_at)->format('d/m/Y')]) }}</span>
                                @endif
                            </li>
                        @empty
                            <li class="lf-framework__meta">{{ __('lf.LF_learning_versions_empty') }}</li>
                        @endforelse
                    </ul>
                    <p class="lf-form-help">{{ __('lf.LF_learning_simple_history_help') }}</p>
                </section>
            </div>
        </details>
    </div>
@endsection
