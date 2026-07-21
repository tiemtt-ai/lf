@extends('layouts.backend')
@section('title', __('lf.LF_bulk_enrollment_result_title'))
@section('page_title', __('lf.LF_bulk_enrollment_result_title'))
@section('content')
    <div class="admin-card admin-form-card">
        <div class="admin-form-standard">
            <section class="admin-form-standard-section" aria-labelledby="bulk-summary-title">
                <h2 id="bulk-summary-title" class="admin-form-section-title">{{ __('lf.LF_bulk_enrollment_summary') }}</h2>
                <dl class="admin-form-field-grid">
                    @foreach (['total', 'created', 'reenrolled', 'skipped_existing', 're_enrollment_required', 'failed'] as $key)
                        <div><dt>{{ __('lf.LF_bulk_enrollment_summary_'.$key) }}</dt><dd>{{ $result['summary'][$key] }}</dd></div>
                    @endforeach
                </dl>
            </section>
            <section class="admin-form-standard-section" aria-labelledby="bulk-result-detail-title">
                <h2 id="bulk-result-detail-title" class="admin-form-section-title">{{ __('lf.LF_bulk_enrollment_result_details') }}</h2>
                <div class="admin-table-wrap"><table class="table"><thead><tr><th>{{ __('lf.LF_course_enrollment_common_student') }}</th><th>{{ __('lf.LF_course_enrollment_common_product') }}</th><th>{{ __('lf.LF_course_enrollment_common_status') }}</th><th>{{ __('lf.LF_bulk_enrollment_reason') }}</th></tr></thead>
                    <tbody>@foreach ($result['items'] as $item)<tr><td>{{ $item['student_name'] }}</td><td>{{ $item['product_title'] }}</td><td>{{ __('lf.LF_bulk_enrollment_status_'.$item['status']) }}</td><td>{{ $item['reason'] ?? '—' }}</td></tr>@endforeach</tbody>
                </table></div>
            </section>
        </div>
        <div class="admin-form-actions"><a class="btn btn-primary" href="{{ route($routePrefix.'.index') }}">{{ __('lf.LF_course_enrollment_common_back_to_enrollments') }}</a><a class="btn btn-secondary" href="{{ route($routePrefix.'.create') }}">{{ __('lf.LF_bulk_enrollment_create_another') }}</a></div>
    </div>
@endsection
