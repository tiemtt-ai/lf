@extends('layouts.backend')
@section('title', $framework->name)
@section('content')
<div class="admin-page-header"><div><h1>{{ $framework->name }}</h1><p>{{ $framework->code }}</p></div><a href="{{ route('admin.learning-frameworks.index') }}">{{ __('lf.LF_learning_back') }}</a></div>
@include('learning-frameworks.partials.errors')

<section class="admin-card"><h2>{{ __('lf.LF_learning_framework_details') }}</h2><form method="POST" action="{{ route('admin.learning-frameworks.update', $framework->id) }}">@csrf @method('PUT')
@include('learning-frameworks.partials.framework-fields', ['framework' => $framework, 'masteryScale' => $masteryScale])
<button class="admin-btn admin-btn-primary">{{ __('lf.LF_common_button_save') }}</button></form></section>

<section class="admin-card"><h2>{{ __('lf.LF_learning_new_version') }}</h2><form method="POST" action="{{ route('admin.learning-frameworks.versions.store', $framework->id) }}">@csrf<input type="hidden" name="framework_id" value="{{ $framework->id }}">
<div class="admin-form-grid"><label>{{ __('lf.LF_learning_version_code') }}<input name="version_code" required></label><label>{{ __('lf.LF_learning_title') }}<input name="title" required></label></div><label>{{ __('lf.LF_learning_description') }}<textarea name="description"></textarea></label><button class="admin-btn admin-btn-primary">{{ __('lf.LF_learning_create_draft') }}</button></form></section>

<section class="admin-card"><h2>{{ __('lf.LF_learning_definitions') }}</h2>
<form method="POST" action="{{ route('admin.learning-frameworks.definitions.store', $framework->id) }}">@csrf<input type="hidden" name="framework_id" value="{{ $framework->id }}">@include('learning-frameworks.partials.definition-fields', ['definition' => null, 'identityLocked' => false])<button class="admin-btn admin-btn-primary">{{ __('lf.LF_learning_add_definition') }}</button></form>
@foreach($definitions as $definition)<hr><form method="POST" action="{{ route('admin.learning-frameworks.definitions.update', [$framework->id, $definition->id]) }}">@csrf @method('PUT')<input type="hidden" name="framework_id" value="{{ $framework->id }}">@include('learning-frameworks.partials.definition-fields', ['definition' => $definition, 'identityLocked' => (bool) $definition->identity_locked])<button class="admin-btn">{{ __('lf.LF_common_button_save') }}</button></form>@endforeach
</section>

@foreach($versions as $version)<section class="admin-card"><h2>{{ __('lf.LF_learning_version') }} {{ $version->version_number }} — {{ $version->title_snapshot }} <small>({{ $version->status }})</small></h2>
@if($version->status === 'draft_snapshot')
<form method="POST" action="{{ route('admin.learning-frameworks.versions.update', [$framework->id, $version->id]) }}">@csrf @method('PUT')<input type="hidden" name="framework_id" value="{{ $framework->id }}"><input type="hidden" name="version_code" value="{{ $version->version_code }}"><div class="admin-form-grid"><label>{{ __('lf.LF_learning_version_code') }}<input disabled value="{{ $version->version_code }}"></label><label>{{ __('lf.LF_learning_title') }}<input name="title" required value="{{ $version->title_snapshot }}"></label></div><label>{{ __('lf.LF_learning_description') }}<textarea name="description">{{ $version->description_snapshot }}</textarea></label><button class="admin-btn">{{ __('lf.LF_common_button_save') }}</button></form>
<form method="POST" action="{{ route('admin.learning-frameworks.nodes.store', [$framework->id, $version->id]) }}">@csrf<input type="hidden" name="framework_version_id" value="{{ $version->id }}">@include('learning-frameworks.partials.node-fields', ['node' => null, 'editing' => false])<button class="admin-btn admin-btn-primary">{{ __('lf.LF_learning_add_node') }}</button></form>
<form method="POST" action="{{ route('admin.learning-frameworks.versions.publish', [$framework->id, $version->id]) }}" onsubmit="return confirm('{{ __('lf.LF_learning_publish_confirm') }}')">@csrf<button class="admin-btn">{{ __('lf.LF_learning_publish') }}</button></form>
@endif
@foreach($nodesByVersion->get($version->id, collect()) as $node)<hr>@if($version->status === 'draft_snapshot')<form method="POST" action="{{ route('admin.learning-frameworks.nodes.update', [$framework->id, $version->id, $node->id]) }}">@csrf @method('PUT')<input type="hidden" name="framework_version_id" value="{{ $version->id }}">@include('learning-frameworks.partials.node-fields', ['node' => $node, 'editing' => true])<button class="admin-btn">{{ __('lf.LF_common_button_save') }}</button></form>@else<strong>{{ $node->code_snapshot }} — {{ $node->name_snapshot }}</strong><pre>{{ $node->criteria_snapshot }}</pre>@endif @endforeach
</section>@endforeach
@endsection
