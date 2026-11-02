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
{{-- Read-only popup of one Version; the history section's x-data holds which one is open, so ?version= opens it too. --}}
<dialog class="ui-modal" data-columns="3" id="framework-version-panel-{{ $version->id }}" aria-labelledby="lf-framework-version-view-title-{{ $version->id }}"
        x-effect="expanded === {{ (int) $version->id }} ? ($el.open || $el.showModal()) : ($el.open && $el.close())"
        @close="if (expanded === {{ (int) $version->id }}) expanded = null">
    <div class="ui-modal__form">
        <header class="ui-modal__header">
            <div>
                <h3 id="lf-framework-version-view-title-{{ $version->id }}">{{ __('lf.LF_learning_simple_version_view_title', ['number' => $version->version_number]) }}</h3>
                <p class="ui-caption">{{ __('lf.LF_learning_simple_version_readonly') }}</p>
            </div>
            <button class="ui-modal__close cursor-interaction" type="button" @click="expanded = null"
                    aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            <section class="ui-framework-version" data-lf-framework-version-view="{{ $version->id }}" aria-labelledby="lf-framework-version-view-title-{{ $version->id }}"
                     x-data="{
                         keyword: '', nodeType: '',
                         nodes: @js($viewedNodes->map(fn ($node) => ['name' => $node->name_snapshot, 'code' => $node->code_snapshot, 'type' => $definitionsById->get((int) $node->node_definition_id)->node_type ?? 'competency'])->values()),
                         normalize(value) { return String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLocaleLowerCase(); },
                         matches(name, code, type) { const term = this.normalize(this.keyword.trim()); return (!this.nodeType || type === this.nodeType) && (this.normalize(name).includes(term) || this.normalize(code).includes(term)); },
                         get resultCount() { return this.nodes.filter(node => this.matches(node.name, node.code, node.type)).length; }
                     }">
                <dl class="ui-framework-version__facts">
                    <div>
                        <dt>{{ __('lf.LF_learning_simple_version_changes') }}</dt>
                        <dd>
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
                        </dd>
                    </div>
                    <div>
                        <dt>{{ __('lf.LF_learning_simple_version_scale') }}</dt>
                        <dd class="ui-version__chips">
                            @foreach ($versionLevels($version) as $level)
                                <span class="ui-status ui-status--archived">{{ $levelLabel($level['key']) }} · {{ (float) $level['threshold'] > 0 ? '≥ '.round((float) $level['threshold'] * 100).'%' : '0%' }}</span>
                            @endforeach
                        </dd>
                    </div>
                </dl>
                @if ($viewedNodes->isNotEmpty())
                    @include('learning-frameworks.partials.competency-search', ['searchId' => 'version-competency-keyword-'.$version->id])
                @endif
                @include('learning-frameworks.partials.competency-table', [
                    'nodes' => $viewedNodes,
                    'marker' => 'data-lf-framework-version-item',
                    'canEdit' => false,
                    'newIds' => $viewedAdded->pluck('node_definition_id')->map(fn ($id) => (int) $id),
                ])
            </section>
        </div>
        <footer class="ui-modal__footer">
            <span></span>
            <button type="button" class="ui-button cursor-interaction" @click="expanded = null">{{ __('lf.LF_common_button_close') }}</button>
        </footer>
    </div>
</dialog>
