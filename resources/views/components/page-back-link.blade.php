@props(['href', 'label'])

{{-- Small "back to the list" link above the page title. Fill it through @section('page_back') in a backend page. --}}
<a class="page-back__link" href="{{ $href }}">
    <x-backend-icon name="chevron-down" class="page-back__icon" />
    <span>{{ $label }}</span>
</a>
