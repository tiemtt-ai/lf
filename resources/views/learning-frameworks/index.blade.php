@extends('layouts.backend')

@section('title', __('lf.LF_learning_frameworks'))

@section('content')
<div class="admin-page-header">
    <div><h1>{{ __('lf.LF_learning_frameworks') }}</h1><p>{{ __('lf.LF_learning_manual_help') }}</p></div>
    <a class="admin-btn admin-btn-primary" href="{{ route('admin.learning-frameworks.create') }}">{{ __('lf.LF_learning_create_framework') }}</a>
</div>
<div class="admin-card">
    <table class="admin-table"><thead><tr><th>{{ __('lf.LF_learning_code') }}</th><th>{{ __('lf.LF_learning_name') }}</th><th>{{ __('lf.LF_learning_versions') }}</th><th>{{ __('lf.LF_learning_status') }}</th></tr></thead>
        <tbody>@forelse($frameworks as $framework)<tr><td>{{ $framework->code }}</td><td><a href="{{ route('admin.learning-frameworks.show', $framework->id) }}">{{ $framework->name }}</a></td><td>{{ $framework->version_count }} ({{ $framework->draft_count }} {{ __('lf.LF_learning_draft') }}, {{ $framework->published_count }} {{ __('lf.LF_learning_published') }})</td><td>{{ $framework->status }}</td></tr>@empty<tr><td colspan="4">{{ __('lf.LF_learning_empty') }}</td></tr>@endforelse</tbody>
    </table>
</div>
@endsection
