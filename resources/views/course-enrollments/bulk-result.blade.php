@extends('layouts.backend')
@section('title', __('lf.LF_bulk_enrollment_result_title'))
@section('page_title', __('lf.LF_bulk_enrollment_result_title'))
@section('content')
    @php
        $context = $result['context'] ?? null;
        $configuration = $context['configuration'] ?? [];
        $formatDateTime = static fn (?string $value): string => $value
            ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i')
            : '';
    @endphp
    <div class="admin-card admin-form-card bulk-enrollment-result">
        <div class="admin-form-standard">
            <div class="bulk-enrollment-result__success" role="status">
                <span class="bulk-enrollment-result__success-icon" aria-hidden="true">✓</span>
                <div>
                    <strong>{{ __('lf.LF_bulk_enrollment_result_success_title') }}</strong>
                    <p>{{ __('lf.LF_bulk_enrollment_result_success_content', ['count' => $result['summary']['total']]) }}</p>
                    @if ($context)
                        <dl class="bulk-enrollment-result__context" aria-label="{{ __('lf.LF_bulk_enrollment_completion_information') }}">
                            <div><dt>{{ __('lf.LF_bulk_enrollment_completed_at') }}</dt><dd>{{ $formatDateTime($context['completed_at']) }}</dd></div>
                            <div><dt>{{ __('lf.LF_bulk_enrollment_completed_by') }}</dt><dd>{{ $context['completed_by_name'] }}</dd></div>
                        </dl>
                    @endif
                </div>
            </div>
            <section class="admin-form-standard-section" aria-labelledby="bulk-summary-title">
                <h2 id="bulk-summary-title" class="admin-form-section-title">{{ __('lf.LF_bulk_enrollment_summary') }}</h2>
                <dl class="bulk-enrollment-result__summary">
                    @foreach (['total', 'created'] as $key)
                        <div><dt>{{ __('lf.LF_bulk_enrollment_summary_'.$key) }}</dt><dd>{{ $result['summary'][$key] }}</dd></div>
                    @endforeach
                    @if ($result['summary']['reenrolled'] > 0)
                        <div><dt>{{ __('lf.LF_bulk_enrollment_summary_reenrolled') }}</dt><dd>{{ $result['summary']['reenrolled'] }}</dd></div>
                    @endif
                </dl>
            </section>
            <section class="admin-form-standard-section" aria-labelledby="bulk-result-detail-title">
                <h2 id="bulk-result-detail-title" class="admin-form-section-title">{{ __('lf.LF_bulk_enrollment_result_details') }}</h2>
                <div class="admin-table-wrap bulk-enrollment-result__table"><table class="table"><thead><tr><th>{{ __('lf.LF_course_enrollment_common_student') }}</th><th>{{ __('lf.LF_course_enrollment_common_product') }}</th><th>{{ __('lf.LF_course_enrollment_common_version') }}</th><th>{{ __('lf.LF_bulk_enrollment_enrollment_id') }}</th><th>{{ __('lf.LF_course_enrollment_common_status') }}</th></tr></thead>
                    <tbody>@foreach ($result['items'] as $item)<tr><td><strong>{{ $item['student_name'] }}</strong></td><td>{{ $item['product_title'] }}</td><td>{{ $item['version_code'] ?? '—' }}</td><td><a class="admin-text-action" href="{{ route($routePrefix.'.show', $item['enrollment_id']) }}">#{{ $item['enrollment_id'] }}</a></td><td><span class="badge badge-success">{{ __('lf.LF_bulk_enrollment_status_'.$item['status']) }}</span></td></tr>@endforeach</tbody>
                </table></div>
            </section>
            @if ($context)
                <section class="admin-form-standard-section" aria-labelledby="bulk-applied-settings-title">
                    <h2 id="bulk-applied-settings-title" class="admin-form-section-title">{{ __('lf.LF_bulk_enrollment_applied_settings') }}</h2>
                    <dl class="bulk-enrollment-result__settings">
                        <div><dt>{{ __('lf.LF_course_enrollment_access_window') }}</dt><dd>{{ $configuration['access_starts_at'] ? $formatDateTime($configuration['access_starts_at']) : __('lf.LF_bulk_enrollment_access_immediate') }} <span aria-hidden="true">→</span> {{ $configuration['access_ends_at'] ? $formatDateTime($configuration['access_ends_at']) : __('lf.LF_bulk_enrollment_access_unlimited') }}</dd></div>
                        <div><dt>{{ __('lf.LF_course_enrollment_review_window') }}</dt><dd>{{ $configuration['review_starts_at'] ? $formatDateTime($configuration['review_starts_at']) : __('lf.LF_bulk_enrollment_not_configured') }} <span aria-hidden="true">→</span> {{ $configuration['review_ends_at'] ? $formatDateTime($configuration['review_ends_at']) : __('lf.LF_bulk_enrollment_not_configured') }}</dd></div>
                        <div><dt>{{ __('lf.LF_course_enrollment_common_status') }}</dt><dd><span class="badge badge-success">{{ __('lf.LF_course_enrollment_common_active') }}</span></dd></div>
                        <div><dt>{{ __('lf.LF_course_enrollment_common_source') }}</dt><dd>{{ __('lf.LF_course_enrollment_common_source_admin') }}</dd></div>
                        <div><dt>{{ __('lf.LF_course_enrollment_internal_notes') }}</dt><dd>{{ filled($configuration['notes'] ?? null) ? __('lf.LF_bulk_enrollment_has_notes') : __('lf.LF_bulk_enrollment_none') }}</dd></div>
                    </dl>
                </section>
            @endif
        </div>
        <footer class="admin-form-footer" data-actions-align="end"><div class="admin-form-footer-primary"><a class="btn btn-secondary" href="{{ route($routePrefix.'.create') }}">{{ __('lf.LF_bulk_enrollment_create_another') }}</a><a class="btn btn-primary" href="{{ route($routePrefix.'.index') }}">{{ __('lf.LF_course_enrollment_common_back_to_enrollments') }}</a></div></footer>
    </div>
@endsection
