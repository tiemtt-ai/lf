@extends('layouts.backend')

@section('title', __('lf.LF_course_category_common_title'))
@section('page_title', __('lf.LF_course_category_common_title'))

@section('vite')
    @parent
    @vite('resources/css/admin/admin-ui-kit.css')
@endsection

@section('content')
<div class="lf-admin-ui lf-admin-ui--list">
    @php
        $hasActiveFilters = $keyword !== '' || $status;
    @endphp

    @if (session('success'))
        <div class="admin-alert admin-alert-success" role="status">
            {{ session('success') }}
        </div>
    @endif

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
        <form class="mk-tablebar mk-tablebar--filters" method="GET" action="{{ route($routePrefix.'.index') }}">
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
                choose(item) { if (!item) return; this.value = item.value; this.close(); this.$refs.input.focus(); this.open = false; },
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
            @if ($hasActiveFilters)
                <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.index') }}">
                    {{ __('lf.LF_course_category_common_clear_filters') }}
                </a>
            @endif
            <a href="{{ route($routePrefix.'.create') }}" class="mk-toolbar-action ui-button ui-button--primary cursor-interaction">
                <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                {{ __('lf.LF_course_category_common_create') }}
            </a>
        </form>

    <div class="mk-table-scroll" role="region" aria-label="{{ __('lf.LF_course_category_common_title') }}" tabindex="0">
        <table aria-labelledby="admin-page-title">
            <colgroup>
                <col class="mk-col-sequence">
                <col>
                <col class="mk-col-parent">
                <col class="mk-col-status">
                <col class="mk-col-action">
            </colgroup>
            <thead>
            <tr>
                <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_category_common_name') }}</th>
                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_category_common_parent') }}</th>
                <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_category_common_status') }}</th>
                <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($categories as $category)
                <tr>
                    <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">
                        {{ $categories->firstItem() + $loop->index }}
                    </td>
                    <td class="mk-text-cell" data-label="{{ __('lf.LF_course_category_common_name') }}">
                        <span>{{ $category->name }}</span>
                        @if ($category->is_featured)
                            <span class="ui-caption">{{ __('lf.LF_course_category_common_featured') }}</span>
                        @endif
                        <p class="ui-caption">{{ $category->slug }}</p>
                    </td>
                    <td @class(['mk-text-cell', 'mk-na' => ! $category->parent_name])
                        data-label="{{ __('lf.LF_course_category_common_parent') }}">
                        {{ $category->parent_name ?? __('lf.LF_course_category_common_root') }}
                    </td>
                    <td class="ux-action-cell" data-label="{{ __('lf.LF_course_category_common_status') }}">
                        <span>
                            {{ $category->status === 'active'
                                ? __('lf.LF_common_status_common_active')
                                : __('lf.LF_common_status_common_inactive') }}
                        </span>
                    </td>
                    <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                        <a class="ux-row-edit cursor-interaction" href="{{ route($routePrefix.'.edit', $category->id) }}" aria-label="{{ __('lf.LF_course_category_action_edit') }}: {{ $category->name }}" title="{{ __('lf.LF_course_category_action_edit') }}" data-tooltip="{{ __('lf.LF_course_category_action_edit') }}">
                            <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" /><path d="m16 3 5 5-9 9-5 1 1-5Z" /></svg>
                        </a>
                    </td>
                </tr>
            @empty
                <tr class="mk-empty-row">
                    <td class="mk-empty" colspan="5">
                        <div role="status">
                            <strong>{{ $hasActiveFilters ? __('lf.LF_course_category_filter_empty') : __('lf.LF_course_category_common_empty') }}</strong>
                            <p class="ui-caption">{{ $hasActiveFilters ? __('lf.LF_course_category_filter_empty_help') : __('lf.LF_course_category_empty_help') }}</p>
                            @if ($hasActiveFilters)
                                <a class="ui-button ui-button--outline" href="{{ route($routePrefix.'.index') }}">
                                    {{ __('lf.LF_course_category_common_clear_filters') }}
                                </a>
                            @else
                                <a class="ui-button ui-button--primary" href="{{ route($routePrefix.'.create') }}">
                                    {{ __('lf.LF_course_category_common_create') }}
                                </a>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

        <div class="mk-table-foot">
            <span class="mk-footnote">{{ __('lf.LF_common_pagination_summary', ['from' => $categories->firstItem() ?? 0, 'to' => $categories->lastItem() ?? 0, 'total' => $categories->total()]) }}</span>
            <nav class="mk-pages" aria-label="{{ __('lf.LF_course_category_common_title') }}">
                @if ($categories->onFirstPage())
                    <button type="button" class="mk-page" disabled aria-label="{{ html_entity_decode(__('pagination.previous'), ENT_QUOTES, 'UTF-8') }}">‹</button>
                @else
                    <a class="mk-page" rel="prev" href="{{ $categories->previousPageUrl() }}" aria-label="{{ html_entity_decode(__('pagination.previous'), ENT_QUOTES, 'UTF-8') }}">‹</a>
                @endif
                @foreach ($categories->getUrlRange(max(1, $categories->currentPage() - 2), min($categories->lastPage(), $categories->currentPage() + 2)) as $page => $url)
                    <a class="mk-page" href="{{ $url }}" @if ($page === $categories->currentPage()) aria-current="page" @endif>{{ $page }}</a>
                @endforeach
                @if ($categories->hasMorePages())
                    <a class="mk-page" rel="next" href="{{ $categories->nextPageUrl() }}" aria-label="{{ html_entity_decode(__('pagination.next'), ENT_QUOTES, 'UTF-8') }}">›</a>
                @else
                    <button type="button" class="mk-page" disabled aria-label="{{ html_entity_decode(__('pagination.next'), ENT_QUOTES, 'UTF-8') }}">›</button>
                @endif
                <select aria-label="{{ __('lf.LF_course_category_index_page_size') }}" disabled>
                    <option>{{ __('lf.LF_course_category_index_page_size_option', ['count' => $categories->perPage()]) }}</option>
                </select>
            </nav>
        </div>
    </section>
</div>
@endsection
