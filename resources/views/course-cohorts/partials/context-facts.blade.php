            <div id="cohort-edit-product" class="ui-summary__item">
                <dt>{{ __('lf.LF_course_cohort_common_product') }}</dt>
                <dd>{{ $cohort->product_title ?: '—' }}
                    @if ($cohort->product_code)<span class="ui-caption ui-framework-description">{{ $cohort->product_code }} · {{ __('lf.LF_course_cohort_common_locked') }}</span>@endif
                </dd>
            </div>
            <div id="cohort-edit-version" class="ui-summary__item">
                <dt>{{ __('lf.LF_course_cohort_create_content_version') }}</dt>
                <dd>
                    @if ($cohort->version_id)
                        {{ str_replace(':code', $cohort->version_code, __('lf.LF_course_cohort_create_version_prefix')) }}
                        <span class="ui-caption ui-framework-description">{{ __('lf.LF_course_cohort_common_published') }} · {{ __('lf.LF_course_cohort_create_lesson_count', ['count' => (int) $cohort->lesson_count]) }} · {{ __('lf.LF_course_cohort_create_activity_count', ['count' => (int) $cohort->activity_count]) }}</span>
                        @if ($cohort->version_template_id)
                            <a href="{{ route('admin.course-templates.versions.show', [$cohort->version_template_id, $cohort->version_id]) }}" class="ui-enroll-link ui-cohort-version-link" target="_blank" rel="noopener" aria-label="{{ __('lf.LF_product_v2_view_version_new_tab') }}">
                                <x-backend-icon name="eye" class="ui-icon" />{{ __('lf.LF_course_product_item_common_view_version') }}
                            </a>
                        @endif
                    @else
                        —
                    @endif
                </dd>
            </div>
