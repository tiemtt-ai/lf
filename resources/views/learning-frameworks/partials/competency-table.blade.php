{{-- Competency rows of one Version. $marker names the row's data attribute; $canEdit adds the edit popup; $newIds marks rows new against the previous Version. --}}
@php($columns = $canEdit ? 5 : 4)
<div class="mk-table-scroll" role="region" aria-label="{{ __('lf.LF_learning_simple_list_title') }}" tabindex="0">
    <table aria-label="{{ __('lf.LF_learning_simple_list_title') }}">
        <colgroup>
            <col class="mk-col-sequence">
            <col>
            <col class="ui-framework-col-code">
            <col class="mk-col-status">
            @if ($canEdit)
                <col class="mk-col-action">
            @endif
        </colgroup>
        <thead>
        <tr>
            <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
            <th scope="col" class="mk-text-cell">{{ __('lf.LF_learning_simple_field_name') }}</th>
            <th scope="col" class="mk-text-cell">{{ __('lf.LF_learning_code') }}</th>
            <th scope="col" class="ux-action-cell">{{ __('lf.LF_learning_type') }}</th>
            @if ($canEdit)
                <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
            @endif
        </tr>
        </thead>
        <tbody>
        @forelse ($nodes as $node)
            @php($definition = $definitionsById->get((int) $node->node_definition_id))
            <tr {{ $marker }}="{{ $node->node_definition_id }}"
                x-show="matches(@js($node->name_snapshot), @js($node->code_snapshot), @js($definition->node_type ?? 'competency'))">
                <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                <td class="mk-text-cell" data-label="{{ __('lf.LF_learning_simple_field_name') }}">
                    <span class="mk-name-text">{{ $node->name_snapshot }}</span>
                    @if ($newIds->contains((int) $node->node_definition_id))
                        <span class="ui-status ui-status--inactive">{{ __('lf.LF_learning_simple_new') }}</span>
                    @endif
                    @if ($node->description_snapshot)
                        <span class="ui-caption ui-framework-description">{{ $node->description_snapshot }}</span>
                    @endif
                </td>
                <td class="mk-text-cell ui-framework-code" data-label="{{ __('lf.LF_learning_code') }}">{{ $node->code_snapshot }}</td>
                <td class="ux-action-cell" data-label="{{ __('lf.LF_learning_type') }}">
                    <span class="ui-status ui-status--draft">{{ __('lf.LF_learning_simple_type_'.($definition->node_type ?? 'competency')) }}</span>
                </td>
                @if ($canEdit)
                    <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                        @if ($definition && $definition->status === 'active')
                            <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open aria-haspopup="dialog"
                                    data-url="{{ route('admin.learning-frameworks.definitions.form', [$definition->framework_id, $definition->id]) }}"
                                    aria-label="{{ __('lf.LF_learning_simple_edit_named', ['name' => $definition->canonical_name]) }}"
                                    data-tooltip="{{ __('lf.action_edit') }}">
                                <x-backend-icon name="square-pen" class="ui-icon" />
                            </button>
                        @endif
                    </td>
                @endif
            </tr>
        @empty
            <tr class="mk-empty-row">
                <td class="mk-empty" colspan="{{ $columns }}">
                    <div role="status">
                        <strong>{{ __('lf.LF_learning_simple_empty') }}</strong>
                        <p class="ui-caption">{{ __('lf.LF_learning_simple_empty_help') }}</p>
                    </div>
                </td>
            </tr>
        @endforelse
        @if ($nodes->isNotEmpty())
            <tr class="mk-empty-row" x-show="resultCount === 0" x-cloak>
                <td class="mk-empty" colspan="{{ $columns }}">
                    <div role="status">
                        <strong>{{ __('lf.LF_learning_competency_search_empty') }}</strong>
                        <p class="ui-caption">{{ __('lf.LF_learning_competency_search_help') }}</p>
                    </div>
                </td>
            </tr>
        @endif
        </tbody>
    </table>
</div>
