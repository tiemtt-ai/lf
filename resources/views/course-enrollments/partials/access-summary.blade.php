{{-- What an enrollment is frozen to: shown on its detail and edit pages. --}}
<section class="mk-section" aria-labelledby="{{ $headingId }}">
    <div class="ui-summary-head"><h2 id="{{ $headingId }}">{{ __('lf.LF_course_enrollment_group_access') }}</h2></div>
    <dl class="ui-summary ui-enroll-summary">
        <div class="ui-summary__item">
            <dt>{{ __('lf.LF_course_enrollment_common_student') }}</dt>
            <dd>{{ $enrollment->student_name }}<span class="ui-caption ui-framework-description">{{ $enrollment->student_email }}</span></dd>
        </div>
        <div class="ui-summary__item">
            <dt>{{ __('lf.LF_course_enrollment_common_product') }}</dt>
            <dd>{{ $enrollment->product_title }}<span class="ui-caption ui-framework-description">{{ $enrollment->product_code }}</span></dd>
        </div>
        <div class="ui-summary__item">
            <dt>{{ __('lf.LF_course_enrollment_locked_version_label') }}</dt>
            <dd>{{ $enrollment->version_title }}<span class="ui-caption ui-framework-description">{{ __('lf.LF_course_product_item_common_version_number', ['number' => $enrollment->version_number]) }} · {{ $enrollment->version_code }}</span></dd>
        </div>
        @if ($showMeta ?? false)
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_course_enrollment_common_source') }}</dt>
                <dd>{{ __('lf.LF_course_enrollment_common_source_'.$enrollment->source) }}</dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_course_enrollment_common_status') }}</dt>
                <dd><span class="ui-status ui-status--{{ $statusTone }}">{{ __('lf.LF_course_enrollment_common_'.$enrollment->status) }}</span></dd>
            </div>
        @endif
    </dl>
    <p class="admin-form-field--full course-enrollment-binding-note ui-framework__note" role="note">{{ __('lf.LF_course_enrollment_common_frozen_help') }}</p>
</section>
