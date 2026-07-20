@extends('layouts.backend')

@section('title', __('lf.LF_course_enrollment_common_create'))
@section('page_title', __('lf.LF_course_enrollment_common_create'))

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

    <div class="admin-card admin-form-card">
        <form method="POST" action="{{ route($routePrefix.'.store') }}">
            @csrf

            <section class="admin-form-section">
                <h2 class="admin-form-section-title">
                    {{ __('lf.LF_course_enrollment_group_access') }}
                </h2>

                <p>{{ __('lf.LF_course_enrollment_common_version_resolution_help') }}</p>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="student_id">
                        {{ __('lf.LF_course_enrollment_common_student') }}
                    </label>
                    <select id="student_id" name="student_id" class="lf-form-control" required>
                        <option value="">{{ __('lf.LF_course_enrollment_common_select_student') }}</option>
                        @foreach ($students as $student)
                            <option value="{{ $student->id }}" @selected((int) old('student_id') === $student->id)>
                                {{ $student->name }} · {{ $student->email }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="product_id">
                        {{ __('lf.LF_course_enrollment_common_product') }}
                    </label>
                    <select id="product_id" name="product_id" class="lf-form-control" required>
                        <option value="">{{ __('lf.LF_course_enrollment_common_select_product') }}</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((int) old('product_id') === $product->id)>
                                {{ $product->title }} · {{ $product->product_code }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </section>

            <section class="admin-form-section">
                <h2 class="admin-form-section-title">
                    {{ __('lf.LF_course_enrollment_group_lifecycle') }}
                </h2>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="source">
                        {{ __('lf.LF_course_enrollment_common_source') }}
                    </label>
                    <select id="source" name="source" class="lf-form-control" required>
                        @foreach ($sources as $source)
                            <option value="{{ $source }}" @selected(old('source', 'admin') === $source)>
                                {{ __('lf.LF_course_enrollment_common_source_'.$source) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="source_id">
                        {{ __('lf.LF_course_enrollment_common_source_id') }}
                    </label>
                    <input id="source_id"
                           type="number"
                           name="source_id"
                           class="lf-form-control"
                           value="{{ old('source_id') }}">
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="status">
                        {{ __('lf.LF_course_enrollment_common_status') }}
                    </label>
                    <select id="status" name="status" class="lf-form-control" required>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(old('status', 'active') === $status)>
                                {{ __('lf.LF_course_enrollment_common_'.$status) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="enrolled_at">
                        {{ __('lf.LF_course_enrollment_common_enrolled_at') }}
                    </label>
                    <input id="enrolled_at"
                           type="datetime-local"
                           name="enrolled_at"
                           class="lf-form-control"
                           value="{{ old('enrolled_at') }}">
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="access_starts_at">
                        {{ __('lf.LF_course_enrollment_common_access_starts_at') }}
                    </label>
                    <input id="access_starts_at"
                           type="datetime-local"
                           name="access_starts_at"
                           class="lf-form-control"
                           value="{{ old('access_starts_at') }}">
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="access_ends_at">
                        {{ __('lf.LF_course_enrollment_common_access_ends_at') }}
                    </label>
                    <input id="access_ends_at"
                           type="datetime-local"
                           name="access_ends_at"
                           class="lf-form-control"
                           value="{{ old('access_ends_at') }}">
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="review_starts_at">
                        {{ __('lf.LF_course_enrollment_common_review_starts_at') }}
                    </label>
                    <input id="review_starts_at"
                           type="datetime-local"
                           name="review_starts_at"
                           class="lf-form-control"
                           value="{{ old('review_starts_at') }}">
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="review_ends_at">
                        {{ __('lf.LF_course_enrollment_common_review_ends_at') }}
                    </label>
                    <input id="review_ends_at"
                           type="datetime-local"
                           name="review_ends_at"
                           class="lf-form-control"
                           value="{{ old('review_ends_at') }}">
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="notes">
                        {{ __('lf.LF_course_enrollment_common_notes') }}
                    </label>
                    <textarea id="notes"
                              name="notes"
                              class="lf-form-control"
                              rows="3">{{ old('notes') }}</textarea>
                </div>
            </section>

            <footer class="admin-form-actions admin-form-actions--footer">
                <a href="{{ route($routePrefix.'.index') }}" class="btn btn-secondary">
                    {{ __('lf.LF_common_button_cancel') }}
                </a>
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_course_enrollment_common_create') }}
                </button>
            </footer>
        </form>
    </div>
@endsection
