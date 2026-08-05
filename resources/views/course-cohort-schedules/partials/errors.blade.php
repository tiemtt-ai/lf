@php
    $summaryErrors = collect($errors->messages())
        ->reject(fn ($messages, $key) => preg_match('/^slots\.\d+\.(weekday|start_time|end_time)$/', $key))
        ->flatten();
@endphp
@if ($summaryErrors->isNotEmpty())
    <div class="admin-alert admin-alert-danger admin-form-card" role="alert">
        <ul>@foreach ($summaryErrors as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
