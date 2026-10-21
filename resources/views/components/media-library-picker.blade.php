@props(['type'])

{{--
    Pick a Media that already exists in the library instead of uploading a file (ADR-0021): a button that opens a searchable list
    of the ready Media of this type the user may use (an admin: the tenant's; a teacher: their own and what was shared with
    them). The choice travels as `existing_media_id`; picking a file in the upload tile above clears it. Sits right after the
    `x-ui-media-slot` of the same type inside the activity form, whose Alpine scope provides `activityType`.
--}}
@php
    $pickerUrl = route((auth()->user()?->role === 'teacher' ? 'teacher' : 'admin').'.media.picker');
@endphp
<div class="ui-library-pick"
     x-data="{
         open: false, loading: false, keyword: '', items: [], chosen: null, timer: null,
         toggle() { this.open = ! this.open; if (this.open && this.items.length === 0) this.load(); },
         async load() {
             this.loading = true;
             try {
                 const response = await fetch(@js($pickerUrl) + '?type=' + @js($type) + '&keyword=' + encodeURIComponent(this.keyword), {
                     headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
                 });
                 this.items = response.ok ? (await response.json()).items : [];
             } catch (error) {
                 this.items = [];
             } finally {
                 this.loading = false;
             }
         },
         search() { clearTimeout(this.timer); this.timer = setTimeout(() => this.load(), 250); },
         choose(item) { this.chosen = item; this.open = false; },
         clear() { this.chosen = null; },
     }"
     x-on:change.window="if ($event.target && $event.target.name === @js('activity_'.$type.'_file') && $event.target.files && $event.target.files.length) clear()">
    <input type="hidden" name="existing_media_id" x-bind:value="chosen ? chosen.id : ''" x-bind:disabled="! chosen || activityType !== @js($type)">
    <div class="ui-library-pick__bar">
        <button type="button" class="ui-button ui-button--outline cursor-interaction" aria-haspopup="listbox" x-bind:aria-expanded="open" x-on:click="toggle()">
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
    <div class="ui-library-pick__panel" x-show="open" x-cloak>
        <input type="search" x-model="keyword" x-on:input="search()" placeholder="{{ __('lf.LF_media_pick_search') }}" autocomplete="off">
        <p class="ui-caption" x-show="loading" x-cloak>{{ __('lf.LF_media_pick_loading') }}</p>
        <p class="ui-caption" x-show="! loading && items.length === 0" x-cloak>{{ __('lf.LF_media_pick_empty') }}</p>
        <ul class="ui-library-pick__list" role="listbox">
            <template x-for="item in items" x-bind:key="item.id">
                <li>
                    <button type="button" role="option" class="ui-library-pick__item cursor-interaction" x-on:click="choose(item)">
                        <span class="ui-library-pick__name" x-text="item.name"></span>
                        <span class="ui-caption" x-text="item.source + ' · ' + item.file + ' · ' + item.size"></span>
                        <span class="ui-caption ui-library-pick__note" x-show="item.note" x-text="item.note"></span>
                    </button>
                </li>
            </template>
        </ul>
    </div>
</div>
