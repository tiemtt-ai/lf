{{-- Accessible help bubble: an "i" button that shows the text on hover, keyboard focus or tap.
     The text is also the button's description, so screen readers read it without opening. --}}
@props(['text', 'label' => null, 'align' => 'start'])
@php($tipId = 'lf-info-tip-'.\Illuminate\Support\Str::random(8))
<span {{ $attributes->merge(['class' => 'lf-info-tip lf-info-tip--'.$align]) }}
      x-data="{ open: false }"
      :class="{ 'is-open': open }"
      x-on:click.outside="open = false"
      x-on:keydown.escape="open = false">
    <button type="button" class="lf-info-tip__button"
            aria-label="{{ $label ?? __('lf.LF_common_info_tip_label') }}"
            aria-describedby="{{ $tipId }}"
            x-bind:aria-expanded="open.toString()"
            x-on:click.prevent="open = ! open">i</button>
    <span id="{{ $tipId }}" role="tooltip" class="lf-info-tip__bubble">{{ $text }}</span>
</span>
