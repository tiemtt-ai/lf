{{--
    The library view of the activity dialog (ADR-0021): choose a Media that already exists in the library instead of uploading
    a file. It covers the form inside the same dialog (no second popup): the file slot of a type asks for it with the
    `open-media-library` window event, and the choice goes back with `library-media` { type, chosen }. Lists the ready Media
    of that type the user may use (an admin: the tenant's; a teacher: their own and what was shared with them), with where
    each one comes from, how it is shared and where it is used. Escape and Back return to the form; the search box has no
    name, so nothing here is submitted with the form.
--}}
@php
    $pickerUrl = route((auth()->user()?->role === 'teacher' ? 'teacher' : 'admin').'.media.picker');
    $titles = collect(['video', 'audio', 'document'])->mapWithKeys(fn (string $type) => [$type => __('lf.LF_media_pick_title_'.$type)])->all();
@endphp
<section class="ui-library-view" role="region" aria-labelledby="ui-library-view-title" x-cloak
         x-data="{
             open: false, type: 'video', titles: @js($titles),
             loading: false, keyword: '', items: [], pending: null, timer: null, seq: 0,
             scope: 'all', sort: 'suggested', usageOpen: null, activityName: '', phase: '',
             get rows() {
                 const rows = this.items.filter(item => this.scope === 'all' || (this.scope === 'mine' ? item.mine : item.shared && ! item.mine));
                 const collator = new Intl.Collator(document.documentElement.lang || 'vi');
                 if (this.sort === 'name') rows.sort((a, b) => collator.compare(a.name, b.name));
                 else if (this.sort === 'used') rows.sort((a, b) => b.usage_count - a.usage_count);
                 else if (this.sort === 'new') rows.sort((a, b) => String(b.date).localeCompare(String(a.date)));
                 return rows;
             },
             scopeCount(scope) { return this.items.filter(item => scope === 'all' || (scope === 'mine' ? item.mine : item.shared && ! item.mine)).length; },
             get pendingItem() { return this.items.find(item => item.id === this.pending) ?? null; },
             initials(name) { return String(name).split(' ').filter(Boolean).map(word => word[0]).join('').slice(0, 2).toUpperCase(); },
             show(detail) {
                 this.type = detail.type;
                 this.pending = detail.chosenId ?? null;
                 this.usageOpen = null;
                 this.keyword = '';
                 this.items = [];
                 const form = this.$root.closest('form');
                 this.activityName = form?.querySelector('[name=title]')?.value?.trim() || '';
                 this.phase = form?.querySelector('.ui-context-chip')?.textContent?.trim() || '';
                 this.open = true;
                 this.load();
                 this.$nextTick(() => this.$refs.search.focus());
             },
             back() {
                 this.open = false;
                 this.usageOpen = null;
                 this.$nextTick(() => this.$root.closest('form')?.querySelector('[name=activity_' + this.type + '_file]')?.closest('.ui-field')?.querySelector('button')?.focus());
             },
             async load() {
                 const mine = ++this.seq;
                 this.loading = true;
                 try {
                     const response = await fetch(@js($pickerUrl) + '?type=' + this.type + '&keyword=' + encodeURIComponent(this.keyword), {
                         headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
                     });
                     const items = response.ok ? (await response.json()).items : [];
                     if (mine === this.seq) this.items = items;
                 } catch (error) {
                     if (mine === this.seq) this.items = [];
                 } finally {
                     if (mine === this.seq) this.loading = false;
                 }
             },
             search() { clearTimeout(this.timer); this.timer = setTimeout(() => this.load(), 250); },
             select(item) { this.pending = item.id; this.usageOpen = null; },
             step(event, direction) {
                 const sibling = direction > 0 ? event.target.nextElementSibling : event.target.previousElementSibling;
                 sibling?.focus();
             },
             confirm() {
                 const item = this.pendingItem;
                 if (! item) return;
                 this.$dispatch('library-media', { type: this.type, chosen: item });
                 this.back();
             },
         }"
         x-show="open"
         x-on:open-media-library.window="show($event.detail)"
         x-on:keydown.escape.prevent.stop="usageOpen ? (usageOpen = null) : back()">
    <header class="ui-modal__header ui-library-view__header">
        <button type="button" class="ui-modal__close cursor-interaction" x-on:click="back()" aria-label="{{ __('lf.LF_media_pick_back') }}">
            <x-backend-icon name="arrow-left" class="ui-icon" />
        </button>
        <div>
            <h3 id="ui-library-view-title" x-text="titles[type]"></h3>
            <p class="ui-caption">
                <span x-text="@js(__('lf.LF_media_pick_for_activity', ['name' => '__NAME__'])).replace('__NAME__', activityName || @js(__('lf.LF_media_pick_unnamed_activity')))"></span>
                <span class="ui-context-chip" x-show="phase" x-text="phase"></span>
            </p>
        </div>
        <button type="button" class="ui-modal__close cursor-interaction" data-ui-dialog-form-cancel aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>

    <div class="ui-library-pick__toolbar">
        <div class="ui-library-pick__toolbar-row">
            <label class="ui-search ui-library-pick__search">
                <x-backend-icon name="search" class="ui-icon" />
                <input type="search" x-ref="search" x-model="keyword" x-on:input="search()" x-on:keydown.enter.prevent
                       placeholder="{{ __('lf.LF_media_pick_search') }}" aria-label="{{ __('lf.LF_media_pick_search') }}" autocomplete="off">
            </label>
            <div class="ui-field ui-library-pick__sort">
                <label class="ui-sr-only" for="ui-library-sort">{{ __('lf.LF_media_pick_sort_label') }}</label>
                <select id="ui-library-sort" data-ui-kit-select x-on:change="sort = $event.target.value"
                        data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_media_pick_sort_suggested') }}">
                    @foreach (['suggested', 'new', 'name', 'used'] as $sortKey)
                        <option value="{{ $sortKey }}" @selected($sortKey === 'suggested')>{{ __('lf.LF_media_pick_sort_'.$sortKey) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="ui-tabs ui-library-pick__tabs" role="tablist" aria-label="{{ __('lf.LF_media_pick_scope_label') }}">
            @foreach (['all', 'mine', 'shared'] as $scopeKey)
                <button type="button" role="tab" class="ui-tabs__tab cursor-interaction" x-on:click="scope = '{{ $scopeKey }}'"
                        x-bind:aria-selected="(scope === '{{ $scopeKey }}').toString()">
                    {{ __('lf.LF_media_pick_scope_'.$scopeKey) }}
                    <span class="ui-library-pick__tab-count" x-show="! loading" x-text="scopeCount('{{ $scopeKey }}')"></span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="ui-library-pick__body" x-on:click.outside="usageOpen = null">
        <div class="ui-library-pick__list" x-show="loading" aria-hidden="true">
            @for ($skeleton = 0; $skeleton < 3; $skeleton++)
                <div class="ui-library-pick__item ui-library-pick__item--skeleton">
                    <span class="ui-library-pick__radio"></span>
                    <span class="ui-library-pick__thumb"></span>
                    <span class="ui-library-pick__main"><span class="ui-skeleton-line"></span><span class="ui-skeleton-line ui-skeleton-line--short"></span></span>
                </div>
            @endfor
        </div>
        <p class="ui-sr-only" role="status" x-show="loading">{{ __('lf.LF_media_pick_loading') }}</p>
        <div class="ui-library-pick__empty" role="status" x-show="! loading && rows.length === 0">
            <span class="ui-library-pick__empty-icon" aria-hidden="true"><x-backend-icon name="search" class="ui-icon" /></span>
            <strong>{{ __('lf.LF_media_pick_empty_title') }}</strong>
            <span class="ui-caption">{{ __('lf.LF_media_pick_empty_hint') }}</span>
        </div>
        <div class="ui-library-pick__list" role="radiogroup" aria-labelledby="ui-library-view-title" x-show="! loading">
            <template x-for="item in rows" x-bind:key="item.id">
                <div class="ui-library-pick__item" role="radio" tabindex="0"
                     x-bind:aria-checked="(pending === item.id).toString()"
                     x-on:click="select(item)" x-on:dblclick="select(item); confirm()"
                     x-on:keydown.space.prevent="select(item)" x-on:keydown.enter.prevent="select(item)"
                     x-on:keydown.arrow-down.prevent="step($event, 1)" x-on:keydown.arrow-up.prevent="step($event, -1)">
                    <span class="ui-library-pick__radio" aria-hidden="true"></span>
                    <span class="ui-library-pick__thumb" aria-hidden="true">
                        <span x-show="type === 'video'"><x-backend-icon name="video" class="ui-icon" /></span>
                        <span x-show="type === 'audio'"><x-backend-icon name="audio" class="ui-icon" /></span>
                        <span x-show="type === 'document'"><x-backend-icon name="file-text" class="ui-icon" /></span>
                        <span class="ui-library-pick__duration" x-show="item.duration" x-text="item.duration"></span>
                    </span>
                    <span class="ui-library-pick__main">
                        <span class="ui-library-pick__name" x-text="item.name" x-bind:title="item.name"></span>
                        <span class="ui-library-pick__meta">
                            <span x-text="item.file"></span>
                            <span class="ui-library-pick__dot" aria-hidden="true"></span>
                            <span x-text="item.size"></span>
                        </span>
                        <span class="ui-library-pick__meta">
                            <span class="ui-library-pick__owner"><span class="ui-library-pick__avatar" x-text="initials(item.owner)"></span><span x-text="item.owner"></span></span>
                            <span class="ui-library-pick__dot" aria-hidden="true"></span>
                            <span class="ui-library-pick__share">
                                <span x-show="item.shared"><x-backend-icon name="users" class="ui-icon" /></span>
                                <span x-show="! item.shared"><x-backend-icon name="lock" class="ui-icon" /></span>
                                <span x-text="item.share"></span>
                            </span>
                        </span>
                        <span class="ui-library-pick__note" x-show="item.note" x-text="item.note" x-bind:title="item.note"></span>
                    </span>
                    <span class="ui-library-pick__usage-wrap">
                        <button type="button" class="ui-library-pick__usage" x-show="item.used" x-bind:aria-expanded="(usageOpen === item.id).toString()"
                                x-on:click.stop="usageOpen = usageOpen === item.id ? null : item.id">
                            <x-backend-icon name="link" class="ui-icon" /><span x-text="item.usage"></span>
                        </button>
                        <span class="ui-library-pick__usage is-unused" x-show="! item.used" x-text="item.usage"></span>
                        <span class="ui-library-pick__popover" x-show="usageOpen === item.id" x-on:click.stop>
                            <strong>{{ __('lf.LF_media_pick_used_title') }}</strong>
                            <ul><template x-for="place in item.usages_all" x-bind:key="place"><li x-text="place"></li></template></ul>
                            <small>{{ __('lf.LF_media_pick_used_note') }}</small>
                        </span>
                    </span>
                </div>
            </template>
        </div>
    </div>

    <footer class="ui-modal__footer ui-library-view__footer">
        <span class="ui-caption ui-library-pick__selected" role="status"
              x-text="pendingItem ? @js(__('lf.LF_media_pick_selected_info', ['name' => '__NAME__'])).replace('__NAME__', pendingItem.name) : @js(__('lf.LF_media_pick_none_selected'))"></span>
        <button type="button" class="ui-button cursor-interaction" x-on:click="back()">{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="button" class="ui-button ui-button--primary cursor-interaction" x-bind:disabled="! pendingItem" x-on:click="confirm()">{{ __('lf.LF_media_pick_select') }}</button>
    </footer>
</section>
