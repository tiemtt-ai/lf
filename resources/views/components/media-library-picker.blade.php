@props(['type'])

{{--
    Pick a Media that already exists in the library instead of uploading a file (ADR-0021): a button that opens a popup with a
    searchable list of the ready Media of this type the user may use (an admin: the tenant's; a teacher: their own and what was
    shared with them). Each row says where it comes from, how it is shared (or how it reached a teacher), and where it is used;
    what is not used yet comes first. The choice travels as `existing_media_id`; picking a file in the upload tile above clears it.
    Sits right after the `x-ui-media-slot` of the same type inside the activity form, whose Alpine scope provides `activityType`.
    The popup is a nested <dialog> inside that form: its search box has no name and Enter does not submit the form.
--}}
@php
    $pickerUrl = route((auth()->user()?->role === 'teacher' ? 'teacher' : 'admin').'.media.picker');
    $dialogId = 'media-library-picker-'.$type;
    $fileInputName = 'activity_'.$type.'_file';
@endphp
<div class="ui-library-pick"
     x-data="{
         loading: false, keyword: '', items: [], chosen: null, pending: null, timer: null, seq: 0,
         scope: 'all', sort: 'suggested', usageOpen: null, activityName: '',
         get rows() {
             const rows = this.items.filter(item => this.scope === 'all' || (this.scope === 'mine' ? item.mine : item.shared && ! item.mine));
             const collator = new Intl.Collator(document.documentElement.lang || 'vi');
             if (this.sort === 'name') rows.sort((a, b) => collator.compare(a.name, b.name));
             else if (this.sort === 'used') rows.sort((a, b) => b.usage_count - a.usage_count);
             else if (this.sort === 'new') rows.sort((a, b) => String(b.date).localeCompare(String(a.date)));
             return rows;
         },
         get pendingItem() { return this.items.find(item => item.id === this.pending) ?? null; },
         initials(name) { return String(name).split(' ').filter(Boolean).map(word => word[0]).join('').slice(0, 2).toUpperCase(); },
         openPicker() {
             this.pending = this.chosen ? this.chosen.id : null;
             this.usageOpen = null;
             this.activityName = this.$root.closest('form')?.querySelector('[name=title]')?.value?.trim() || '';
             this.$refs.dialog.showModal();
             this.load();
             this.$nextTick(() => this.$refs.search.focus());
         },
         closePicker() { this.usageOpen = null; this.$refs.dialog.close(); },
         async load() {
             const mine = ++this.seq;
             this.loading = true;
             try {
                 const response = await fetch(@js($pickerUrl) + '?type=' + @js($type) + '&keyword=' + encodeURIComponent(this.keyword), {
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
         confirm() { const item = this.pendingItem; if (! item) return; this.chosen = item; this.closePicker(); this.announce(true); },
         uploadNew() { this.closePicker(); this.$root.closest('form')?.querySelector('input[name=' + @js($fileInputName) + ']')?.click(); },
         clear() { this.chosen = null; this.pending = null; this.announce(false); },
         announce(chosen) { this.$dispatch('library-media', { type: @js($type), chosen }); },
     }"
     x-on:change.window="if ($event.target && $event.target.name === @js($fileInputName) && $event.target.files && $event.target.files.length) clear()">
    <input type="hidden" name="existing_media_id" x-bind:value="chosen ? chosen.id : ''" x-bind:disabled="! chosen || activityType !== @js($type)">
    <div class="ui-library-pick__bar">
        <button type="button" class="ui-button ui-button--outline cursor-interaction" aria-haspopup="dialog" aria-controls="{{ $dialogId }}" x-on:click="openPicker()">
            <x-backend-icon name="folder" class="ui-icon" />
            {{ __('lf.LF_media_pick_button') }}
        </button>
        <span class="ui-library-pick__chosen" x-show="chosen" x-cloak>
            <span class="ui-caption">{{ __('lf.LF_media_pick_chosen') }}:</span>
            <strong x-text="chosen ? chosen.name : ''"></strong>
            <button type="button" class="ux-row-edit cursor-interaction" x-on:click="clear()" aria-label="{{ __('lf.LF_media_pick_remove') }}" data-tooltip="{{ __('lf.LF_media_pick_remove') }}">×</button>
        </span>
    </div>
    <p class="ui-field__hint" x-show="chosen" x-cloak>{{ __('lf.LF_media_pick_hint') }}</p>

    <dialog id="{{ $dialogId }}" class="ui-modal ui-library-pick__dialog" data-columns="2" aria-labelledby="{{ $dialogId }}-title" x-ref="dialog">
        <div class="ui-modal__form">
            <header class="ui-modal__header">
                <div>
                    <h3 id="{{ $dialogId }}-title">{{ __('lf.LF_media_pick_title_'.$type) }}</h3>
                    <p class="ui-caption" x-show="activityName" x-cloak
                       x-text="@js(__('lf.LF_media_pick_attach_to', ['name' => '__NAME__'])).replace('__NAME__', activityName)"></p>
                </div>
                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="closePicker()" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
            </header>

            <div class="ui-library-pick__toolbar">
                <div class="ui-library-pick__toolbar-row">
                    <label class="ui-search ui-library-pick__search">
                        <x-backend-icon name="search" class="ui-icon" />
                        <input type="search" x-ref="search" x-model="keyword" x-on:input="search()" x-on:keydown.enter.prevent
                               placeholder="{{ __('lf.LF_media_pick_search') }}" aria-label="{{ __('lf.LF_media_pick_search') }}" autocomplete="off">
                    </label>
                    <select class="ui-library-pick__sort" x-model="sort" aria-label="{{ __('lf.LF_media_pick_sort_label') }}">
                        @foreach (['suggested', 'new', 'name', 'used'] as $sortKey)
                            <option value="{{ $sortKey }}">{{ __('lf.LF_media_pick_sort_'.$sortKey) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ui-library-pick__toolbar-row">
                    <div class="ui-library-pick__segmented" role="group" aria-label="{{ __('lf.LF_media_pick_scope_label') }}">
                        @foreach (['all', 'mine', 'shared'] as $scopeKey)
                            <button type="button" x-on:click="scope = '{{ $scopeKey }}'" x-bind:aria-pressed="(scope === '{{ $scopeKey }}').toString()">{{ __('lf.LF_media_pick_scope_'.$scopeKey) }}</button>
                        @endforeach
                    </div>
                    <span class="ui-caption ui-library-pick__count" role="status" x-show="! loading" x-cloak
                          x-text="@js(__('lf.LF_media_pick_count', ['count' => '__N__'])).replace('__N__', rows.length)"></span>
                </div>
            </div>

            <div class="ui-modal__body ui-library-pick__body" x-on:click.outside="usageOpen = null">
                <p class="ui-caption" role="status" x-show="loading" x-cloak>{{ __('lf.LF_media_pick_loading') }}</p>
                <div class="ui-library-pick__empty" role="status" x-show="! loading && rows.length === 0" x-cloak>
                    <strong>{{ __('lf.LF_media_pick_empty_title') }}</strong>
                    <span class="ui-caption">{{ __('lf.LF_media_pick_empty_hint') }}</span>
                </div>
                <div class="ui-library-pick__list" role="radiogroup" aria-labelledby="{{ $dialogId }}-title">
                    <template x-for="item in rows" x-bind:key="item.id">
                        <div class="ui-library-pick__item" role="radio" tabindex="0"
                             x-bind:aria-checked="(pending === item.id).toString()"
                             x-on:click="select(item)"
                             x-on:keydown.space.prevent="select(item)" x-on:keydown.enter.prevent="select(item)"
                             x-on:keydown.arrow-down.prevent="step($event, 1)" x-on:keydown.arrow-up.prevent="step($event, -1)">
                            <span class="ui-library-pick__radio" aria-hidden="true"></span>
                            <span class="ui-library-pick__thumb ui-library-pick__thumb--{{ $type }}" aria-hidden="true">
                                <x-backend-icon name="{{ $type === 'video' ? 'file-video' : ($type === 'audio' ? 'audio' : 'file-text') }}" class="ui-icon" />
                                <span class="ui-library-pick__duration" x-show="item.duration" x-text="item.duration"></span>
                            </span>
                            <span class="ui-library-pick__main">
                                <span class="ui-library-pick__head">
                                    <span class="ui-library-pick__name" x-text="item.name"></span>
                                    <span class="ui-library-pick__usage-wrap">
                                        <button type="button" class="ui-library-pick__usage" x-show="item.used" x-bind:aria-expanded="(usageOpen === item.id).toString()"
                                                x-on:click.stop="usageOpen = usageOpen === item.id ? null : item.id" x-text="item.usage"></button>
                                        <span class="ui-library-pick__usage is-unused" x-show="! item.used" x-text="item.usage"></span>
                                        <span class="ui-library-pick__popover" x-show="usageOpen === item.id" x-cloak x-on:click.stop>
                                            <strong>{{ __('lf.LF_media_pick_used_title') }}</strong>
                                            <ul><template x-for="place in item.usages_all" x-bind:key="place"><li x-text="place"></li></template></ul>
                                            <small>{{ __('lf.LF_media_pick_used_note') }}</small>
                                        </span>
                                    </span>
                                </span>
                                <span class="ui-caption" x-text="item.file + ' · ' + item.size"></span>
                                <span class="ui-caption ui-library-pick__meta">
                                    <span class="ui-library-pick__owner"><span class="ui-library-pick__avatar" x-text="initials(item.owner)"></span><span x-text="item.owner"></span></span>
                                    <span x-text="item.share"></span>
                                </span>
                                <span class="ui-caption ui-library-pick__note" x-show="item.note" x-text="item.note"></span>
                            </span>
                        </div>
                    </template>
                </div>
            </div>

            <footer class="ui-modal__footer">
                <button type="button" class="ui-button cursor-interaction ui-library-pick__upload" x-on:click="uploadNew()">
                    <x-backend-icon name="upload" class="ui-icon" />
                    {{ __('lf.LF_media_pick_upload_new') }}
                </button>
                <span class="ui-caption ui-library-pick__selected" role="status"
                      x-text="pendingItem ? @js(__('lf.LF_media_pick_selected_info', ['name' => '__NAME__'])).replace('__NAME__', pendingItem.name) : @js(__('lf.LF_media_pick_none_selected'))"></span>
                <button type="button" class="ui-button cursor-interaction" x-on:click="closePicker()">{{ __('lf.LF_common_button_cancel') }}</button>
                <button type="button" class="ui-button ui-button--primary cursor-interaction" x-bind:disabled="! pendingItem" x-on:click="confirm()">{{ __('lf.LF_media_pick_select') }}</button>
            </footer>
        </div>
    </dialog>
</div>
