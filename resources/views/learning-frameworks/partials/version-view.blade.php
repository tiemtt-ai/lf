@php
    $viewedNodes = $activeNodes($version);
    $previous = $versions->filter(fn ($v) => (int) $v->version_number < (int) $version->version_number && $v->status !== 'draft_snapshot')
        ->sortByDesc('version_number')->first();
    $previousNodes = $activeNodes($previous);
    $previousIds = $previousNodes->pluck('node_definition_id')->map(fn ($id) => (int) $id);
    $viewedIds = $viewedNodes->pluck('node_definition_id')->map(fn ($id) => (int) $id);
    $viewedAdded = $previous ? $viewedNodes->reject(fn ($n) => $previousIds->contains((int) $n->node_definition_id)) : collect();
    $viewedRemoved = $previous ? $previousNodes->reject(fn ($n) => $viewedIds->contains((int) $n->node_definition_id)) : collect();
@endphp
<section id="framework-version-panel-{{ $version->id }}" x-show="expanded === {{ (int) $version->id }}" x-cloak x-transition.opacity.duration.150ms class="lf-framework__version-view" aria-labelledby="lf-framework-version-view-title-{{ $version->id }}" data-lf-framework-version-view="{{ $version->id }}">
    <header class="lf-framework__version-view-head">
        <h3 id="lf-framework-version-view-title-{{ $version->id }}" class="admin-form-subsection-title">{{ __('lf.LF_learning_simple_version_view_title', ['number' => $version->version_number]) }}</h3>
        <span class="lf-framework__meta">{{ __('lf.LF_learning_simple_version_readonly') }}</span>
    </header>
    <p class="lf-framework__version-diff">
        @if ($previous === null)
            {{ __('lf.LF_learning_simple_version_first') }}
        @else
            @if ($viewedAdded->isNotEmpty())
                {{ __('lf.LF_learning_simple_version_diff_added', ['number' => $previous->version_number, 'names' => $viewedAdded->pluck('name_snapshot')->implode(', ')]) }}
            @endif
            @if ($viewedRemoved->isNotEmpty())
                {{ __('lf.LF_learning_simple_version_diff_removed', ['number' => $previous->version_number, 'names' => $viewedRemoved->pluck('name_snapshot')->implode(', ')]) }}
            @endif
            @if ($viewedAdded->isEmpty() && $viewedRemoved->isEmpty())
                {{ __('lf.LF_learning_simple_version_diff_same', ['number' => $previous->version_number]) }}
            @endif
        @endif
    </p>
    @forelse ($viewedNodes as $node)
        @php
            $definition = $definitionsById->get((int) $node->node_definition_id);
        @endphp
        <article class="lf-framework__item" data-lf-framework-version-item="{{ $node->node_definition_id }}">
            <div class="lf-framework__item-main">
                <div class="lf-framework__item-title">
                    <strong>{{ $node->name_snapshot }}</strong>
                    <span class="badge badge-info">{{ $typeLabel($definition->node_type ?? 'competency') }}</span>
                    @if ($viewedAdded->contains('id', $node->id))
                        <span class="badge badge-warning">{{ __('lf.LF_learning_simple_new') }}</span>
                    @endif
                </div>
                @if ($node->description_snapshot)
                    <p class="lf-framework__item-description">{{ $node->description_snapshot }}</p>
                @endif
                <span class="lf-framework__meta">{{ __('lf.LF_learning_simple_code', ['code' => $node->code_snapshot]) }}</span>
            </div>
        </article>
    @empty
        <p class="lf-framework__meta">{{ __('lf.LF_learning_simple_empty') }}</p>
    @endforelse
    <div class="lf-framework__version-scale">
        <h4 class="lf-framework__version-scale-title">{{ __('lf.LF_learning_simple_version_scale') }}</h4>
        <ol class="lf-framework__levels">
            @foreach ($versionLevels($version) as $level)
                <li>
                    <span>{{ $levelLabel($level['key']) }}</span>
                    <span class="lf-framework__meta">{{ (float) $level['threshold'] > 0 ? '≥ '.round((float) $level['threshold'] * 100).'%' : '0%' }}</span>
                </li>
            @endforeach
        </ol>
    </div>
</section>
