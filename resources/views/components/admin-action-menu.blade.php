@props([
    'label' => __('lf.table_actions'),
    'tooltip' => __('lf.table_more_actions'),
])

<div class="admin-action-menu"
     x-data="{
         open: false,
         menuStyle: '',
         closeTimer: null,
         openMenu() {
             clearTimeout(this.closeTimer)
             if (this.open) return
             this.open = true
             this.$nextTick(() => this.place())
         },
         scheduleClose() {
             clearTimeout(this.closeTimer)
             this.closeTimer = setTimeout(() => this.open = false, 120)
         },
         cancelClose() {
             clearTimeout(this.closeTimer)
         },
         place() {
             const trigger = this.$refs.trigger?.getBoundingClientRect()
             const panel = this.$refs.panel
             if (!trigger || !panel) return
             const gap = 6
             const edge = 8
             let top = trigger.bottom + gap
             if (top + panel.offsetHeight > window.innerHeight - edge) {
                 top = Math.max(edge, trigger.top - panel.offsetHeight - gap)
             }
             const left = Math.min(
                 window.innerWidth - panel.offsetWidth - edge,
                 Math.max(edge, trigger.right - panel.offsetWidth)
             )
             this.menuStyle = `top:${top}px;left:${left}px`
         }
     }"
     x-on:click.outside="open = false" x-on:keydown.escape.stop="open = false"
     x-on:mouseenter="openMenu()" x-on:mouseleave="scheduleClose()"
     x-on:resize.window="open = false" x-on:scroll.window="open = false">
    <button type="button" class="admin-link-button admin-text-action admin-action-menu__trigger"
            x-ref="trigger" x-on:click="openMenu()"
            x-bind:class="{ 'is-open': open }"
            x-bind:aria-expanded="open.toString()"
            aria-label="{{ $label }}" aria-haspopup="menu"
            title="{{ $tooltip }}">
        <svg class="admin-action-menu__dots" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="5" r="1.75" />
            <circle cx="12" cy="12" r="1.75" />
            <circle cx="12" cy="19" r="1.75" />
        </svg>
    </button>
    <template x-teleport="body">
        <div class="admin-action-menu__panel" role="menu" x-ref="panel"
             x-bind:style="menuStyle" x-show="open" x-cloak
             x-on:mouseenter="cancelClose()" x-on:mouseleave="scheduleClose()"
             x-transition.opacity.duration.120ms>
            {{ $slot }}
        </div>
    </template>
</div>
