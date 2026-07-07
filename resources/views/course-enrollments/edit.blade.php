@extends('layouts.backend')

@section('title', __('lf.LF_course_enrollment_common_edit'))
@section('page_title', __('lf.LF_course_enrollment_common_edit'))

@section('content')
    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-form-actions">
        <a href="{{ route($routePrefix.'.show', $enrollment->id) }}">
            {{ __('lf.LF_course_enrollment_common_back_to_detail') }}
        </a>
    </div>

    <div class="admin-card admin-form-card">
        <section class="admin-form-section">
            <h2 class="admin-form-section-title">
                {{ __('lf.LF_course_enrollment_group_frozen_context') }}
            </h2>

            <p>{{ __('lf.LF_course_enrollment_common_frozen_help') }}</p>

            <table class="table">
                <tbody>
                <tr>
                    <th>{{ __('lf.LF_course_enrollment_common_student') }}</th>
                    <td>{{ $enrollment->student_name }} · {{ $enrollment->student_email }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_enrollment_common_product') }}</th>
                    <td>{{ $enrollment->product_title }} · {{ $enrollment->product_code }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_enrollment_common_version') }}</th>
                    <td>
                        {{ $enrollment->version_title }}
                        · {{ __('lf.LF_course_product_item_common_version_number', ['number' => $enrollment->version_number]) }}
                        · {{ $enrollment->version_code }}
                    </td>
                </tr>
                </tbody>
            </table>
        </section>

        <form method="POST" action="{{ route($routePrefix.'.update', $enrollment->id) }}">
            @csrf
            @method('PUT')

            <section class="admin-form-section">
                <h2 class="admin-form-section-title">
                    {{ __('lf.LF_course_enrollment_group_lifecycle') }}
                </h2>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="status">
                        {{ __('lf.LF_course_enrollment_common_status') }}
                    </label>
                    <select id="status" name="status" class="lf-form-control" required>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(old('status', $enrollment->status) === $status)>
                                {{ __('lf.LF_course_enrollment_common_'.$status) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="access_starts_at">
                        {{ __('lf.LF_course_enrollment_common_access_starts_at') }}
                    </label>
                    <input id="access_starts_at"
                           type="datetime-local"
                           name="access_starts_at"
                           class="lf-form-control"
                           value="{{ old('access_starts_at', optional($enrollment->access_starts_at ? \Illuminate\Support\Carbon::parse($enrollment->access_starts_at) : null)->format('Y-m-d\\TH:i')) }}">
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="access_ends_at">
                        {{ __('lf.LF_course_enrollment_common_access_ends_at') }}
                    </label>
                    <input id="access_ends_at"
                           type="datetime-local"
                           name="access_ends_at"
                           class="lf-form-control"
                           value="{{ old('access_ends_at', optional($enrollment->access_ends_at ? \Illuminate\Support\Carbon::parse($enrollment->access_ends_at) : null)->format('Y-m-d\\TH:i')) }}">
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="notes">
                        {{ __('lf.LF_course_enrollment_common_notes') }}
                    </label>
                    <textarea id="notes"
                              name="notes"
                              class="lf-form-control"
                              rows="3">{{ old('notes', $enrollment->notes) }}</textarea>
                </div>
            </section>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_common_button_save_changes') }}
                </button>
                <a href="{{ route($routePrefix.'.show', $enrollment->id) }}">
                    {{ __('lf.LF_common_button_cancel') }}
                </a>
            </div>
        </form>
    </div>
@endsection
