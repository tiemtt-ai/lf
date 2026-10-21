@extends('layouts.backend')

@section('title', __('lf.LF_course_category_common_title'))
@section('page_title', __('lf.LF_course_category_common_title'))

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js', 'resources/js/admin-ui-kit-row-actions.js'])
@endsection

@section('content')
<div id="category-list" class="lf-admin-ui lf-admin-ui--list" data-ui-dialog-form-scope data-ui-row-actions
     data-csrf="{{ csrf_token() }}"
     data-msg-delete-error="{{ __('lf.LF_course_category_delete_error') }}"
     data-msg-session-expired="{{ __('lf.LF_course_category_dialog_session_expired') }}">
    @php
        $hasActiveFilters = $keyword !== '' || $status;
        // Only admins manage categories; a teacher reads the list (and picks a category in the Template form).
        $canManage = str_starts_with($routePrefix, 'admin');
    @endphp

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

    <section class="mk-section" aria-labelledby="admin-page-title">
        <div class="mk-tablebar mk-tablebar--filters">
        <form class="mk-filter-form" method="GET" action="{{ route($routePrefix.'.index') }}" data-ui-live-filter>
            <div class="ui-field mk-search">
                <label class="ui-field__label" for="keyword">
                    {{ __('lf.LF_course_category_common_keyword') }}
                </label>
                <div class="ui-search">
                    <input id="keyword" type="search" name="keyword" value="{{ $keyword }}"
                           placeholder="{{ __('lf.LF_course_category_common_keyword_placeholder') }}">
                    <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                        <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                    </button>
                </div>
            </div>

            <div class="ui-field mk-search" x-data="{
                value: @js($status ?? ''), open: false, query: '', active: -1,
                options: @js([
                    ['value' => '', 'label' => __('lf.LF_course_category_common_all_statuses')],
                    ['value' => 'active', 'label' => __('lf.LF_course_category_common_active')],
                    ['value' => 'inactive', 'label' => __('lf.LF_course_category_common_inactive')],
                ]),
                get matches() { return this.options.filter(item => item.label.toLocaleLowerCase().includes(this.query.toLocaleLowerCase())); },
                get label() { return this.options.find(item => item.value === this.value)?.label ?? ''; },
                close() { this.open = false; this.query = ''; this.active = -1; },
                choose(item) { if (!item) return; const changed = this.value !== item.value; this.value = item.value; this.close(); this.$refs.input.focus(); this.open = false; if (changed) this.$nextTick(() => this.$refs.input.form?.requestSubmit()); },
                move(step) { this.open = true; this.active = Math.max(0, Math.min(this.matches.length - 1, this.active + step)); }
            }" @click.outside="close()" @focusout="if (!$el.contains($event.relatedTarget)) close()">
                <label class="ui-field__label" for="status" x-bind:for="'category-status-input'">
                    {{ __('lf.LF_course_category_common_status') }}
                </label>
                <select id="status" name="status" x-model="value" x-show="false">
                    <option value="">{{ __('lf.LF_course_category_common_all_statuses') }}</option>
                    <option value="active" @selected($status === 'active')>{{ __('lf.LF_course_category_common_active') }}</option>
                    <option value="inactive" @selected($status === 'inactive')>{{ __('lf.LF_course_category_common_inactive') }}</option>
                </select>
                <div class="ux-combo" x-cloak>
                    <div class="ux-combo__control">
                        <input id="category-status-input" x-ref="input" type="text" autocomplete="off" role="combobox"
                               aria-autocomplete="list" aria-controls="category-status-options" :aria-expanded="open.toString()"
                               :aria-activedescendant="open && active >= 0 ? 'category-status-option-' + active : null"
                               :value="open ? query : label" :placeholder="label"
                               @click="open = true" @input="query = $event.target.value; open = true; active = -1"
                               @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
                               @keydown.enter="if (open) { $event.preventDefault(); choose(matches[active >= 0 ? active : 0]); }"
                               @keydown.escape.prevent.stop="close()">
                        <button class="ux-clear cursor-interaction" type="button" x-show="value !== ''"
                                aria-label="{{ __('lf.LF_course_category_common_clear_filters') }}"
                                @click="choose(options[0])">×</button>
                        <span class="ux-combo__icon" aria-hidden="true"></span>
                    </div>
                    <div class="ux-combo__list" id="category-status-options" role="listbox"
                         aria-label="{{ __('lf.LF_course_category_common_status') }}" x-show="open">
                        <template x-for="(item, index) in matches" :key="item.value">
                            <button class="ux-option cursor-interaction" type="button" role="option" tabindex="-1"
                                    :id="'category-status-option-' + index" :aria-selected="(item.value === value).toString()"
                                    @mousedown.prevent @click="choose(item)">
                                <span x-text="item.label"></span><span x-show="item.value === value" aria-hidden="true">✓</span>
                            </button>
                        </template>
                        <div class="ui-caption" role="status" x-show="matches.length === 0">{{ __('lf.LF_course_category_index_no_options') }}</div>
                    </div>
                </div>
            </div>
            <span class="ui-live-slot" data-ui-live-region="clear-filters">
                @if ($hasActiveFilters)
                    <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.index') }}">
                        {{ __('lf.LF_course_category_common_clear_filters') }}
                    </a>
                @endif
            </span>
        </form>

    @if ($canManage)
    <x-ui-list-toolbar :url="route($routePrefix.'.bulk-destroy')" name="ids[]"
        :label="__('lf.LF_course_category_bulk_delete')"
        :confirm-title="__('lf.LF_course_category_bulk_delete_title')" :confirm-message="__('lf.LF_course_category_bulk_delete_confirm', ['count' => ':count'])"
        :confirm-label="__('lf.LF_common_button_confirm')">
            <x-slot:create>
                <button type="button" class="ui-button ui-button--outline cursor-interaction"
                data-ui-dialog-form-open data-ui-dialog-form-for="category-list" data-url="{{ route($routePrefix.'.create') }}" aria-haspopup="dialog">
            <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
            {{ __('lf.LF_course_category_common_create_short') }}
        </button>
            </x-slot:create>
        </x-ui-list-toolbar>
    @endif
        </div>

    <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ __('lf.LF_course_category_common_title') }}" tabindex="0">
        <table aria-labelledby="admin-page-title">
            <colgroup>
                @if ($canManage)<col class="ui-col-check">@endif
                <col class="mk-col-sequence">
                <col>
                <col class="mk-col-parent">
                <col class="mk-col-count">
                <col class="mk-col-status">
                @if ($canManage)<col class="mk-col-action">@endif
            </colgroup>
            <thead>
            <tr>
                @if ($canManage)
                <th scope="col" class="ui-check-cell">
                    <input type="checkbox" class="ui-bulk-box" data-ui-bulk-select-all aria-label="{{ __('lf.LF_course_category_select_all') }}">
                </th>
                @endif
                <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_category_common_name') }}</th>
                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_category_common_parent') }}</th>
                <th scope="col" class="mk-count-cell">{{ __('lf.LF_course_category_index_product_count') }}</th>
                <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_category_common_status') }}</th>
                @if ($canManage)<th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>@endif
            </tr>
            </thead>
            <tbody>
            @forelse ($categories as $category)
                @php
                    $deleteBlocker = match (true) {
                        (int) $category->product_count > 0 => __('lf.LF_course_category_delete_blocked_products', ['count' => $category->product_count]),
                        (int) $category->template_count > 0 => __('lf.LF_course_category_delete_blocked_templates', ['count' => $category->template_count]),
                        (int) $category->child_count > 0 => __('lf.LF_course_category_delete_blocked_children', ['count' => $category->child_count]),
                        default => null,
                    };
                @endphp
                <tr>
                    @if ($canManage)
                    <td class="ui-check-cell" data-label="{{ __('lf.LF_course_category_select') }}">
                        @if ($deleteBlocker)
                            <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ $deleteBlocker }}" data-tooltip-align="start">
                                <input type="checkbox" class="ui-bulk-box" disabled aria-label="{{ $deleteBlocker }}">
                            </span>
                        @else
                            <input type="checkbox" class="ui-bulk-box" data-ui-bulk-item value="{{ $category->id }}"
                                   aria-label="{{ __('lf.LF_course_category_select') }}: {{ $category->name }}">
                        @endif
                    </td>
                    @endif
                    <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">
                        {{ $categories->firstItem() + $loop->index }}
                    </td>
                    <td class="mk-text-cell" data-label="{{ __('lf.LF_course_category_common_name') }}">
                        <span class="mk-name-line">
                            <span class="mk-name-text">{{ $category->name }}</span>
                            @if ($category->is_featured)
                                <span class="mk-featured" title="{{ __('lf.LF_course_category_common_featured') }}" data-tooltip="{{ __('lf.LF_course_category_common_featured') }}">
                                    <svg class="ui-icon" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" /></svg>
                                    <span class="ui-sr-only">{{ __('lf.LF_course_category_common_featured') }}</span>
                                </span>
                            @endif
                        </span>
                        <p class="ui-caption">{{ $category->slug }}</p>
                    </td>
                    <td @class(['mk-text-cell', 'mk-na' => ! $category->parent_name])
                        data-label="{{ __('lf.LF_course_category_common_parent') }}">
                        {{ $category->parent_name ?? __('lf.LF_course_category_common_root') }}
                    </td>
                    <td @class(['mk-count-cell', 'mk-na' => (int) $category->product_count === 0])
                        data-label="{{ __('lf.LF_course_category_index_product_count') }}">
                        {{ number_format((int) $category->product_count) }}
                    </td>
                    <td class="ux-action-cell" data-label="{{ __('lf.LF_course_category_common_status') }}">
                        @if ($canManage)
                        <form method="POST" action="{{ route($routePrefix.'.toggle-status', $category->id) }}">
                            @csrf
                            <button type="submit" class="ux-row-switch cursor-interaction" role="switch"
                                    aria-checked="{{ $category->status === 'active' ? 'true' : 'false' }}"
                                    aria-label="{{ __('lf.LF_course_category_action_toggle_status') }}: {{ $category->name }}"
                                    title="{{ $category->status === 'active' ? __('lf.LF_common_status_common_active') : __('lf.LF_common_status_common_inactive') }}"></button>
                        </form>
                        @else
                            <span @class(['ui-status', 'ui-status--active' => $category->status === 'active', 'ui-status--archived' => $category->status !== 'active'])>
                                {{ $category->status === 'active' ? __('lf.LF_common_status_common_active') : __('lf.LF_common_status_common_inactive') }}
                            </span>
                        @endif
                    </td>
                    @if ($canManage)
                    <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                        <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open
                                data-url="{{ route($routePrefix.'.edit', $category->id) }}" aria-haspopup="dialog"
                                aria-label="{{ __('lf.LF_course_category_action_edit') }}: {{ $category->name }}" data-tooltip="{{ __('lf.LF_course_category_action_edit') }}">
                            <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" /><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z" /></svg>
                        </button>

                    </td>
                    @endif
                </tr>
            @empty
                <tr class="mk-empty-row">
                    <td class="mk-empty" colspan="{{ $canManage ? 7 : 5 }}">
                        <div role="status">
                            <strong>{{ $hasActiveFilters ? __('lf.LF_course_category_filter_empty') : __('lf.LF_course_category_common_empty') }}</strong>
                            <p class="ui-caption">{{ $hasActiveFilters ? __('lf.LF_course_category_filter_empty_help') : __('lf.LF_course_category_empty_help') }}</p>
                            @if ($canManage && ! $hasActiveFilters)
                                <button type="button" class="ui-button ui-button--outline cursor-interaction"
                                        data-ui-dialog-form-open data-url="{{ route($routePrefix.'.create') }}" aria-haspopup="dialog">
                                    {{ __('lf.LF_course_category_common_create_short') }}
                                </button>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

        <x-ui-list-foot :paginator="$categories"
            :label="__('lf.LF_course_category_common_title')"
            :page-size-label="__('lf.LF_course_category_index_page_size')"
            :page-size-option="__('lf.LF_course_category_index_page_size_option', ['count' => $categories->perPage()])" />
    </section>

    <dialog class="ui-modal" data-columns="2" aria-labelledby="category-dialog-title" data-ui-dialog-form
            data-msg-load-error="{{ __('lf.LF_course_category_dialog_load_error') }}"
            data-msg-discard-title="{{ __('lf.LF_course_category_dialog_discard_title') }}"
            data-msg-discard-text="{{ __('lf.LF_course_category_dialog_discard_text') }}"
            data-msg-discard="{{ __('lf.LF_course_category_dialog_discard') }}"
            data-msg-save-error="{{ __('lf.LF_course_category_dialog_save_error') }}"
            data-msg-session-expired="{{ __('lf.LF_course_category_dialog_session_expired') }}"
            data-msg-required-input="{{ __('lf.LF_course_category_dialog_required_input') }}"
            data-msg-required-select="{{ __('lf.LF_course_category_dialog_required_select') }}"></dialog>
</div>
@endsection
