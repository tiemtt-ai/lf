<section class="admin-form-section">
    <h2 class="admin-form-section-title">
        {{ __('lf.LF_course_cohort_group_basic') }}
    </h2>

    <div class="lf-form-group">
        <label class="lf-form-label" for="name">
            {{ __('lf.LF_course_cohort_common_name') }}
            <span class="text-danger">*</span>
        </label>
        <input id="name"
               type="text"
               name="name"
               class="lf-form-control"
               value="{{ old('name', $cohort->name ?? '') }}"
               required>
    </div>

    @if ($cohort?->code)
        <div class="lf-form-group">
            <span class="lf-form-label">
                {{ __('lf.LF_course_cohort_common_code') }}
            </span>
            <p class="lf-form-help">{{ $cohort->code }}</p>
        </div>
    @else
        <p class="lf-form-help">
            {{ __('lf.LF_course_cohort_common_code_auto_help') }}
        </p>
    @endif

    <div class="lf-form-group">
        <label class="lf-form-label" for="description">
            {{ __('lf.LF_course_cohort_common_description') }}
        </label>
        <textarea id="description"
                  name="description"
                  class="lf-form-control"
                  rows="3">{{ old('description', $cohort->description ?? '') }}</textarea>
    </div>

    <div class="lf-form-group">
        <label class="lf-form-label" for="status">
            {{ __('lf.LF_course_cohort_common_status') }}
            <span class="text-danger">*</span>
        </label>
        <select id="status" name="status" class="lf-form-control" required>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected(old('status', $cohort->status ?? 'draft') === $status)>
                    {{ __('lf.LF_course_cohort_common_'.$status) }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="lf-form-group">
        <label class="lf-form-label" for="capacity">
            {{ __('lf.LF_course_cohort_common_capacity') }}
        </label>
        <input id="capacity"
               type="number"
               min="0"
               name="capacity"
               class="lf-form-control"
               value="{{ old('capacity', $cohort->capacity ?? '') }}">
    </div>

    <div class="lf-form-group">
        <label class="lf-form-label" for="start_date">
            {{ __('lf.LF_course_cohort_common_start_date') }}
        </label>
        <input id="start_date"
               type="date"
               name="start_date"
               class="lf-form-control"
               value="{{ old('start_date', $cohort->start_date ?? '') }}">
    </div>

    <div class="lf-form-group">
        <label class="lf-form-label" for="end_date">
            {{ __('lf.LF_course_cohort_common_end_date') }}
        </label>
        <input id="end_date"
               type="date"
               name="end_date"
               class="lf-form-control"
               value="{{ old('end_date', $cohort->end_date ?? '') }}">
    </div>
</section>

<section class="admin-form-section">
    <h2 class="admin-form-section-title">
        Cohort media
    </h2>

    @if (($cohortMedia ?? collect())->isNotEmpty())
        <div class="admin-table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Type</th>
                    <th>Name</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($cohortMedia as $media)
                    <tr>
                        <td>{{ $media->usage_type }}</td>
                        <td>{{ $media->display_name }}</td>
                        <td>
                            <a href="{{ $media->signed_url }}" target="_blank" rel="noopener">
                                Open
                            </a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="lf-form-group">
        <label class="lf-form-label" for="cohort_document_file">
            Document
        </label>
        <input id="cohort_document_file"
               type="file"
               name="cohort_document_file"
               class="lf-form-control"
               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,application/pdf">
    </div>

    <div class="lf-form-group">
        <label class="lf-form-label" for="cohort_attachment_file">
            Attachment
        </label>
        <input id="cohort_attachment_file"
               type="file"
               name="cohort_attachment_file"
               class="lf-form-control"
               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,application/pdf">
    </div>
</section>

<section class="admin-form-section">
    <h2 class="admin-form-section-title">
        {{ __('lf.LF_course_cohort_group_context') }}
    </h2>

    <p>{{ __('lf.LF_course_cohort_common_operational_help') }}</p>

    <div class="lf-form-group">
        <label class="lf-form-label" for="product_id">
            {{ __('lf.LF_course_cohort_common_product') }}
        </label>
        <select id="product_id" name="product_id" class="lf-form-control">
            <option value="">{{ __('lf.LF_course_cohort_common_no_product') }}</option>
            @foreach ($products as $product)
                <option value="{{ $product->id }}" @selected((int) old('product_id', $cohort->product_id ?? 0) === $product->id)>
                    {{ $product->title }} · {{ $product->product_code }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="lf-form-group">
        <label class="lf-form-label" for="version_id">
            {{ __('lf.LF_course_cohort_common_version') }}
        </label>
        <select id="version_id" name="version_id" class="lf-form-control">
            <option value="">{{ __('lf.LF_course_cohort_common_no_version') }}</option>
            @foreach ($versions as $version)
                <option value="{{ $version->id }}" @selected((int) old('version_id', $cohort->version_id ?? 0) === $version->id)>
                    {{ $version->title_snapshot }}
                    · {{ __('lf.LF_course_product_item_common_version_number', ['number' => $version->version_number]) }}
                    · {{ $version->version_code }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="lf-form-group">
        <label class="lf-form-label" for="teacher_id">
            {{ __('lf.LF_course_cohort_common_teacher') }}
        </label>
        <select id="teacher_id" name="teacher_id" class="lf-form-control">
            <option value="">{{ __('lf.LF_course_cohort_common_no_teacher') }}</option>
            @foreach ($teachers as $teacher)
                <option value="{{ $teacher->id }}" @selected((int) old('teacher_id', $cohort->teacher_id ?? 0) === $teacher->id)>
                    {{ $teacher->name }} · {{ $teacher->email }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="lf-form-group">
        <label class="lf-form-label" for="notes">
            {{ __('lf.LF_course_cohort_common_notes') }}
        </label>
        <textarea id="notes"
                  name="notes"
                  class="lf-form-control"
                  rows="3">{{ old('notes', $cohort->notes ?? '') }}</textarea>
    </div>
</section>

<div class="admin-form-actions">
    <button type="submit" class="btn btn-primary">
        {{ $submitLabel }}
    </button>
    <a href="{{ route($routePrefix.'.index') }}">
        {{ __('lf.LF_common_button_cancel') }}
    </a>
</div>
