<dialog x-ref="detailDialog" class="ui-modal cohort-enrollment-detail-modal" data-columns="2" aria-labelledby="cohort-enrollment-detail-title"
        x-on:close="detailClosed()">
    <div class="ui-modal__form">
        <header class="ui-modal__header">
            <div>
                <h3 id="cohort-enrollment-detail-title">{{ __('lf.LF_course_cohort_student_enrollment_quick_view') }}</h3>
                <p class="ui-caption" x-text="detail?.code"></p>
            </div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="closeDetail()" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            <dl class="ui-summary ui-cohort-detail-summary">
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_cohort_student_common_student') }}</dt>
                    <dd><span x-text="detail?.name"></span><span class="ui-caption ui-framework-description" x-text="detail?.email"></span></dd>
                </div>
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_cohort_student_common_status') }}</dt>
                    <dd><span class="ui-status" :class="detail?.status === 'active' ? 'ui-status--active' : 'ui-status--danger'" x-text="detail?.status_label"></span></dd>
                </div>
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_enrollment_common_product') }}</dt>
                    <dd>{{ $cohort->product_title }}<span class="ui-caption ui-framework-description">{{ $cohort->product_code }}</span></dd>
                </div>
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_enrollment_common_version') }}</dt>
                    <dd>{{ $cohort->version_title }}<span class="ui-caption ui-framework-description">{{ $cohort->version_code }}</span></dd>
                </div>
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_enrollment_common_source') }}</dt>
                    <dd x-text="detail?.source_label"></dd>
                </div>
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</dt>
                    <dd x-text="detail?.enrolled_at"></dd>
                </div>
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_enrollment_access_window') }}</dt>
                    <dd><span x-text="detail?.access_starts_at"></span> <span aria-hidden="true">→</span> <span x-text="detail?.access_ends_at"></span></dd>
                </div>
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_enrollment_review_window') }}</dt>
                    <dd><span x-text="detail?.review_starts_at"></span> <span aria-hidden="true">→</span> <span x-text="detail?.review_ends_at"></span></dd>
                </div>
            </dl>
        </div>
        <footer class="ui-modal__footer">
            <span></span>
            <button type="button" class="ui-button cursor-interaction" x-on:click="closeDetail()">{{ __('lf.LF_common_button_close') }}</button>
            <a class="ui-button ui-button--primary cursor-interaction" :href="detail?.detail_url">{{ __('lf.LF_course_cohort_student_view_full_enrollment') }}</a>
        </footer>
    </div>
</dialog>
