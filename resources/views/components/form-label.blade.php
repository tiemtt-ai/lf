@props([
    'value',
    'required' => false,
])

<label {{ $attributes->merge(['class' => 'lf-form-label']) }}>
    {{ $value ?? $slot }}
    @if ($required)
        <span class="lf-required-indicator" aria-hidden="true">*</span>
    @endif
</label>
