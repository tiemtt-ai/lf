<div x-cloak x-show="detail" class="admin-modal-backdrop" x-on:click.self="closeDetail()">
    <section class="admin-modal cohort-enrollment-detail-modal"
             role="dialog"
             aria-modal="true"
             aria-labelledby="cohort-enrollment-detail-title">
        <header class="admin-modal-header">
            <div>
                <span class="cohort-enrollment-detail-modal__eyebrow" x-text="detail?.code"></span>
                <h2 id="cohort-enrollment-detail-title">{{ __('lf.LF_course_cohort_student_enrollment_quick_view') }}</h2>
            </div>
            <button x-ref="detailClose"
                    type="button"
                    class="course-enrollment-lifecycle-modal__close"
                    x-on:click="closeDetail()"
                    aria-label="{{ __('lf.LF_common_button_close') }}">
                <span aria-hidden="true">×</span>
            </button>
        </header>

        <div class="cohort-enrollment-detail-modal__body">
            <div class="cohort-enrollment-detail-modal__student">
                <div>
                    <strong x-text="detail?.name"></strong>
                    <span x-text="detail?.email"></span>
                </div>
                <span class="badge"
                      :class="{ 'badge-success': detail?.status === 'active' }"
                      x-text="detail?.status_label"></span>
            </div>

            <section class="cohort-enrollment-context" aria-labelledby="cohort-enrollment-context-title">
                <h3 id="cohort-enrollment-context-title">{{ __('lf.LF_course_cohort_student_enrolled_for') }}</h3>
                <dl>
                    <div>
                        <dt>{{ __('lf.LF_course_enrollment_common_product') }}</dt>
                        <dd><strong>{{ $cohort->product_title }}</strong><span>{{ $cohort->product_code }}</span></dd>
                    </div>
                    <div>
                        <dt>{{ __('lf.LF_course_enrollment_common_version') }}</dt>
                        <dd><strong>{{ $cohort->version_title }}</strong><span>{{ $cohort->version_code }}</span></dd>
                    </div>
                    <div x-show="detail?.current">
                        <dt>{{ __('lf.LF_course_cohort_student_current_class') }}</dt>
                        <dd><strong>{{ $cohort->name }}</strong><span>{{ $cohort->code }}</span></dd>
                    </div>
                </dl>
            </section>

            <dl class="cohort-enrollment-detail-grid">
                <div><dt>{{ __('lf.LF_course_enrollment_common_source') }}</dt><dd x-text="detail?.source_label"></dd></div>
                <div><dt>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</dt><dd x-text="detail?.enrolled_at"></dd></div>
                <div><dt>{{ __('lf.LF_course_enrollment_common_access_starts_at') }}</dt><dd x-text="detail?.access_starts_at"></dd></div>
                <div><dt>{{ __('lf.LF_course_enrollment_common_access_ends_at') }}</dt><dd x-text="detail?.access_ends_at"></dd></div>
                <div><dt>{{ __('lf.LF_course_enrollment_common_review_starts_at') }}</dt><dd x-text="detail?.review_starts_at"></dd></div>
                <div><dt>{{ __('lf.LF_course_enrollment_common_review_ends_at') }}</dt><dd x-text="detail?.review_ends_at"></dd></div>
            </dl>
        </div>

        <footer class="admin-form-footer cohort-enrollment-detail-modal__footer" data-actions-align="end">
            <div class="admin-form-footer-primary">
                <button type="button" class="btn btn-secondary" x-on:click="closeDetail()">{{ __('lf.LF_common_button_close') }}</button>
                <a class="btn btn-primary" :href="detail?.detail_url">{{ __('lf.LF_course_cohort_student_view_full_enrollment') }}</a>
            </div>
        </footer>
    </section>
</div>
