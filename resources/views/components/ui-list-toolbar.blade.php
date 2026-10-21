@props(['url', 'name' => 'ids[]', 'label', 'confirmTitle', 'confirmMessage', 'confirmLabel', 'create' => null])

{{-- UI Kit list buttons, one group that lives in the same wrapping row as the filters (`.mk-tablebar`): it stays on the filter
     row when it fits and otherwise moves down WHOLE, right-aligned, never split. In priority order, most important first (also
     when the group wraps): the create button (`create` slot), the delete button (always there, disabled until a row is checked;
     its label carries the number of checked rows, e.g. "Xóa (3)", kept in step by admin-ui-kit-row-actions.js; ":count" in the
     confirmation message is that number), then the other list buttons (default slot: export / import first, other buttons
     after). The page heading carries no list buttons. --}}
<div class="mk-table-top__actions" data-ui-live-region="toolbar">
        {{ $create }}
        <button type="button" class="ui-button ui-button--danger-outline cursor-interaction" data-ui-bulk-delete disabled data-ui-bulk-name="{{ $name }}"
                data-url="{{ $url }}"
                data-confirm-title="{{ $confirmTitle }}"
                data-confirm-message="{{ $confirmMessage }}"
                data-confirm-label="{{ $confirmLabel }}">
            <x-backend-icon name="trash-2" class="ui-icon" />
            {{ $label }}<span data-ui-bulk-count data-text-selected=" ({{ ':count' }})" aria-live="polite"></span>
        </button>
        {{ $slot }}
</div>
