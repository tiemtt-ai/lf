@extends('layouts.backend')

@section('title', __('lf.LF_bulk_enrollment_title'))
@section('page_title', __('lf.LF_bulk_enrollment_title'))

@section('content')
    @if (isset($completedResult))
        @php
            $completedContext = $completedResult['context'] ?? [];
            $completedConfiguration = $completedContext['configuration'] ?? [];
            $formatCompletedDateTime = static fn (?string $value): string => $value
                ? \Illuminate\Support\Carbon::parse($value)->format('H:i d/m/Y')
                : '—';
        @endphp

        <div class="bulk-enrollment-result__success" role="status">
            <span class="bulk-enrollment-result__success-icon" aria-hidden="true">✓</span>
            <div>
                <strong>{{ __('lf.LF_bulk_enrollment_result_success_title') }}</strong>
                <p>{{ __('lf.LF_bulk_enrollment_result_success_content', ['count' => $completedResult['summary']['total']]) }}</p>
                <dl class="bulk-enrollment-result__context" aria-label="{{ __('lf.LF_bulk_enrollment_completion_information') }}">
                    <div><dt>{{ __('lf.LF_bulk_enrollment_completed_at') }}</dt><dd>{{ $formatCompletedDateTime($completedContext['completed_at'] ?? null) }}</dd></div>
                    <div><dt>{{ __('lf.LF_bulk_enrollment_completed_by') }}</dt><dd>{{ $completedContext['completed_by_name'] ?? '—' }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="admin-card admin-form-card admin-form-surface bulk-enrollment-result">
            <div class="admin-form-standard">
                <section class="admin-form-standard-section" aria-labelledby="bulk-completed-setup-title">
                    <header class="admin-form-section-header">
                        <h2 id="bulk-completed-setup-title" class="admin-form-section-title">{{ __('lf.LF_bulk_enrollment_result_details') }}</h2>
                    </header>

                    <div class="admin-table-wrap bulk-enrollment-review-table bulk-enrollment-result__table"><table class="table">
                        <thead><tr><th class="bulk-enrollment-result__number">{{ __('lf.table_no') }}</th><th>{{ __('lf.LF_course_enrollment_common_student') }}</th><th>{{ __('lf.LF_course_enrollment_common_product') }}</th><th class="bulk-enrollment-result__enrollment-id">{{ __('lf.LF_bulk_enrollment_enrollment_id') }}</th></tr></thead>
                        <tbody>@foreach ($itemsPaginator as $item)
                            @php($itemTimeWindows = $item['time_windows'] ?? null)
                            <tr>
                                <td class="bulk-enrollment-result__number">{{ $itemsPaginator->firstItem() + $loop->index }}</td>
                                <td>{{ $item['student_name'] }}</td>
                                <td>
                                    <strong class="bulk-enrollment-review-product">{{ $item['product_title'] }}</strong>
                                    @if ($itemTimeWindows)
                                        <dl class="bulk-enrollment-product-window">
                                            <div>
                                                <dt>{{ __('lf.LF_bulk_enrollment_access_time') }}</dt>
                                                <dd><span>{{ $itemTimeWindows['access_duration_days'] }} {{ __('lf.LF_bulk_enrollment_days') }}</span><small>{{ $formatCompletedDateTime($itemTimeWindows['access_starts_at']) }} → {{ $formatCompletedDateTime($itemTimeWindows['access_ends_at']) }}</small></dd>
                                            </div>
                                            <div>
                                                <dt>{{ __('lf.LF_bulk_enrollment_review_time') }}</dt>
                                                @if (($itemTimeWindows['review_duration_days'] ?? 0) > 0)
                                                    <dd><span>{{ $itemTimeWindows['review_duration_days'] }} {{ __('lf.LF_bulk_enrollment_days') }}</span><small>{{ $formatCompletedDateTime($itemTimeWindows['review_starts_at']) }} → {{ $formatCompletedDateTime($itemTimeWindows['review_ends_at']) }}</small></dd>
                                                @else
                                                    <dd class="is-empty">{{ __('lf.LF_bulk_enrollment_no_review_time') }}</dd>
                                                @endif
                                            </div>
                                        </dl>
                                    @endif
                                </td>
                                <td class="bulk-enrollment-result__enrollment-id"><a class="admin-text-action bulk-enrollment-result__enrollment-link" href="{{ route($routePrefix.'.show', $item['enrollment_id']) }}">#{{ $item['enrollment_id'] }}</a></td>
                            </tr>
                        @endforeach</tbody>
                    </table></div>

                    @if ($itemsPaginator->hasPages())
                        <nav class="bulk-enrollment-pagination" aria-label="{{ __('lf.LF_bulk_enrollment_page') }}">
                            <a @class(['bulk-enrollment-pagination__button', 'is-disabled' => $itemsPaginator->onFirstPage()]) href="{{ $itemsPaginator->previousPageUrl() ?? '#' }}" @if($itemsPaginator->onFirstPage()) aria-disabled="true" tabindex="-1" @endif><span aria-hidden="true">←</span><span>{{ __('lf.LF_bulk_enrollment_previous') }}</span></a>
                            <span class="bulk-enrollment-pagination__status">{{ $itemsPaginator->currentPage() }} / {{ $itemsPaginator->lastPage() }}</span>
                            <a @class(['bulk-enrollment-pagination__button', 'is-disabled' => ! $itemsPaginator->hasMorePages()]) href="{{ $itemsPaginator->nextPageUrl() ?? '#' }}" @if(! $itemsPaginator->hasMorePages()) aria-disabled="true" tabindex="-1" @endif><span>{{ __('lf.LF_bulk_enrollment_next') }}</span><span aria-hidden="true">→</span></a>
                        </nav>
                    @endif

                    <dl class="bulk-enrollment-confirmation__facts">
                        <div><dt>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</dt><dd>{{ $formatCompletedDateTime($completedConfiguration['enrolled_at'] ?? ($completedContext['completed_at'] ?? null)) }}</dd></div>
                        <div><dt>{{ __('lf.LF_bulk_enrollment_status_after_creation') }}</dt><dd><span class="badge badge-success">{{ __('lf.LF_course_enrollment_common_active') }}</span></dd></div>
                        <div><dt>{{ __('lf.LF_course_enrollment_common_source') }}</dt><dd>{{ __('lf.LF_course_enrollment_common_source_admin') }}</dd></div>
                    </dl>
                </section>

                @if (filled($completedConfiguration['notes'] ?? null))
                    <section class="admin-form-standard-section bulk-enrollment-result__notes" aria-labelledby="bulk-completed-notes-label">
                        <span id="bulk-completed-notes-label">{{ __('lf.LF_course_enrollment_common_notes') }}</span>
                        <p>{{ $completedConfiguration['notes'] }}</p>
                    </section>
                @endif
            </div>
            <footer class="admin-form-footer" data-actions-align="end"><div class="admin-form-footer-primary"><a class="btn btn-secondary" href="{{ route($routePrefix.'.create') }}">{{ __('lf.LF_bulk_enrollment_create_another') }}</a><a class="btn btn-primary" href="{{ route($routePrefix.'.index') }}">{{ __('lf.LF_course_enrollment_common_back_to_enrollments') }}</a></div></footer>
        </div>
    @else
    @if ($errors->any())
        <div class="admin-alert admin-alert-danger" role="alert">
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="bulk-enrollment-wizard"
         x-data="bulkEnrollmentWizard({
             studentUrl: @js(route($routePrefix.'.students.search')),
             productUrl: @js(route($routePrefix.'.products.search')),
             preflightUrl: @js(route($routePrefix.'.bulk-preflight')),
             invalidateUrl: @js(route($routePrefix.'.bulk-invalidate')),
             csrf: @js(csrf_token()),
         })">
        <nav class="bulk-enrollment-stepper" aria-label="{{ __('lf.LF_bulk_enrollment_progress') }}">
            <ol>
                @foreach ([
                    1 => ['LF_bulk_enrollment_step_selection', 'LF_bulk_enrollment_step_selection_help'],
                    2 => ['LF_bulk_enrollment_step_setup', 'LF_bulk_enrollment_step_setup_help'],
                ] as $number => [$titleKey, $helpKey])
                    <li :class="{ 'is-current': step === {{ $number }}, 'is-completed': step > {{ $number }}, 'is-upcoming': step < {{ $number }} }">
                        <button type="button" class="bulk-enrollment-stepper__button"
                                :disabled="step <= {{ $number }}" x-on:click="backToSelection"
                                :aria-current="step === {{ $number }} ? 'step' : null">
                            <span class="bulk-enrollment-stepper__marker">
                                <span x-show="step <= {{ $number }}">{{ $number }}</span>
                                <span x-show="step > {{ $number }}" aria-hidden="true">✓</span>
                            </span>
                            <span class="bulk-enrollment-stepper__copy"><strong>{{ __('lf.'.$titleKey) }}</strong><small>{{ __('lf.'.$helpKey) }}</small></span>
                            <span x-show="step > {{ $number }}" class="sr-only">{{ __('lf.LF_bulk_enrollment_completed') }}</span>
                        </button>
                    </li>
                @endforeach
            </ol>
        </nav>

        <div class="admin-card admin-form-card admin-form-surface">
            <form x-ref="form" class="admin-form-standard" method="POST" novalidate
              action="{{ route($routePrefix.'.bulk-store') }}" x-on:submit.prevent="commit">
            @csrf
            <input type="hidden" name="submission_token" :value="submissionToken">
            <template x-for="student in selectedStudents" :key="`student-${student.id}`">
                <input type="hidden" name="student_ids[]" :value="student.id">
            </template>
            <template x-for="product in selectedProducts" :key="`product-${product.id}`">
                <input type="hidden" name="product_ids[]" :value="product.id">
            </template>
            <template x-for="(pair, index) in confirmedPairs" :key="`confirmation-${pair.student_id}-${pair.product_id}`">
                <span>
                    <input type="hidden" :name="`reenrollment_confirmations[${index}][student_id]`" :value="pair.student_id">
                    <input type="hidden" :name="`reenrollment_confirmations[${index}][product_id]`" :value="pair.product_id">
                    <input type="hidden" :name="`reenrollment_confirmations[${index}][previous_enrollment_id]`" :value="pair.previous_enrollment_id">
                </span>
            </template>

            <p class="admin-alert admin-alert-danger" x-show="errorMessage" x-text="errorMessage" role="alert" x-cloak></p>
            <div x-ref="selectionError" class="admin-alert admin-alert-danger" x-show="hasInvalidSelectedProducts"
                 tabindex="-1" role="alert" aria-labelledby="bulk-selection-error-title" x-cloak>
                <strong id="bulk-selection-error-title">{{ __('lf.LF_bulk_enrollment_selected_invalid_title') }}</strong>
                <p>{{ __('lf.LF_bulk_enrollment_selected_invalid_help') }}</p>
                <ul><template x-for="item in selectedInvalidProducts" :key="`invalid-selected-${item.id}`">
                    <li><strong x-text="item.title"></strong>: <span x-text="eligibilityReason(item)"></span>
                        <button type="button" class="admin-text-action" x-on:click="toggleProduct(item, false)">{{ __('lf.LF_bulk_enrollment_deselect') }}</button>
                    </li>
                </template></ul>
                <button type="button" class="admin-text-action" x-on:click="removeInvalidProducts">{{ __('lf.LF_bulk_enrollment_remove_invalid_products') }}</button>
            </div>

            <section x-show="step === 1" class="admin-form-standard-section bulk-enrollment-wizard-section" aria-labelledby="bulk-selection-title">
                <header class="admin-form-section-header">
                    <h2 id="bulk-selection-title" class="admin-form-section-title">{{ __('lf.LF_bulk_enrollment_select_students_products') }}</h2>
                    <p class="admin-form-section-help">{{ __('lf.LF_bulk_enrollment_cartesian_help') }}</p>
                </header>

                <div class="bulk-enrollment-entry-row">
                    <div class="bulk-enrollment-date-setting">
                        <div class="lf-form-group admin-form-field">
                            <label class="lf-form-label" for="bulk-enrolled-at">
                                {{ __('lf.LF_course_enrollment_common_enrolled_at') }}
                                <span class="lf-required-indicator" aria-hidden="true">*</span>
                            </label>
                            <input id="bulk-enrolled-at" name="configuration[enrolled_at]" type="datetime-local"
                                   class="lf-form-control bulk-enrollment-date-input" x-model="configuration.enrolled_at"
                                   :class="{ 'has-value': configuration.enrolled_at }"
                                   aria-describedby="bulk-enrolled-at-help"
                                   x-on:change="enrollmentDateChanged" required>
                            <p id="bulk-enrolled-at-help" class="lf-form-help">{{ __('lf.LF_course_enrollment_enrolled_at_help') }}</p>
                        </div>
                    </div>

                    <div class="admin-alert admin-alert-info bulk-enrollment-start-guide" role="note">
                        <strong class="admin-alert-title">{{ __('lf.LF_bulk_enrollment_start_title') }}</strong>
                        <p class="admin-alert-guidance">{{ __('lf.LF_bulk_enrollment_start_content') }}</p>
                    </div>
                </div>

                <div class="bulk-enrollment-dual-selectors">
                    <section class="bulk-enrollment-selector" aria-labelledby="bulk-students-title" :aria-busy="studentLoading">
                        <div class="bulk-enrollment-transfer__panel-header">
                            <h3 id="bulk-students-title">{{ __('lf.LF_bulk_enrollment_students_panel') }}</h3>
                            <span x-text="selectedStudentsLabel" aria-live="polite"></span>
                        </div>
                        <label class="lf-form-label" for="bulk-student-search">{{ __('lf.LF_common_button_search') }}</label>
                        <div class="bulk-enrollment-search">
                            <svg class="bulk-enrollment-search__icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
                            <input id="bulk-student-search" type="search" class="lf-form-control"
                                   placeholder="{{ __('lf.LF_course_enrollment_student_search_placeholder') }}"
                                   x-model="studentQuery" x-on:input.debounce.350ms="loadStudents(1)">
                        </div>
                        <label class="bulk-enrollment-transfer__select-all">
                            <input type="checkbox" x-on:change="toggleVisibleStudents($event.target.checked)"
                                   :checked="studentResults.length > 0 && studentResults.every(item => hasStudent(item.id))">
                            {{ __('lf.LF_bulk_enrollment_select_visible') }}
                        </label>
                        <div class="admin-table-wrap bulk-enrollment-selector__list"><table class="table"><tbody>
                            <template x-for="item in studentResults" :key="item.id"><tr>
                                <td><input type="checkbox" :checked="hasStudent(item.id)" x-on:change="toggleStudent(item, $event.target.checked)" :aria-label="item.name"></td>
                                <td><strong x-text="item.name"></strong><span class="course-cohort-index-meta" x-text="item.email"></span></td>
                            </tr></template>
                            <tr x-show="!studentLoading && studentResults.length === 0" x-cloak><td>{{ __('lf.LF_course_enrollment_search_empty') }}</td></tr>
                        </tbody></table></div>
                        <nav class="bulk-enrollment-pagination" aria-label="{{ __('lf.LF_bulk_enrollment_students_pagination') }}">
                            <button type="button" class="bulk-enrollment-pagination__button" x-on:click="loadStudents(studentPage - 1)" :disabled="studentPage <= 1"><span aria-hidden="true">←</span><span>{{ __('lf.LF_bulk_enrollment_previous') }}</span></button>
                            <span class="bulk-enrollment-pagination__status"><span class="sr-only">{{ __('lf.LF_bulk_enrollment_page') }}</span><span x-text="`${studentPage} / ${studentLastPage}`"></span></span>
                            <button type="button" class="bulk-enrollment-pagination__button" x-on:click="loadStudents(studentPage + 1)" :disabled="studentPage >= studentLastPage"><span>{{ __('lf.LF_bulk_enrollment_next') }}</span><span aria-hidden="true">→</span></button>
                        </nav>
                    </section>

                    <section x-ref="productPanel" class="bulk-enrollment-selector bulk-enrollment-product-selector" :class="{ 'is-onboarding-highlight': productGuidanceHighlighted }" aria-labelledby="bulk-products-title" :aria-busy="productLoading">
                        <div class="bulk-enrollment-transfer__panel-header">
                            <h3 x-ref="productHeading" id="bulk-products-title" tabindex="-1">{{ __('lf.LF_bulk_enrollment_products_panel') }}</h3>
                            <span x-text="selectedProductsLabel" aria-live="polite"></span>
                        </div>
                        <p class="sr-only" aria-live="polite" x-text="productEligibilityAnnouncement"></p>
                        <label class="lf-form-label" for="bulk-product-search">{{ __('lf.LF_common_button_search') }}</label>
                        <div class="bulk-enrollment-search">
                            <svg class="bulk-enrollment-search__icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
                            <input id="bulk-product-search" type="search" class="lf-form-control"
                                   placeholder="{{ __('lf.LF_course_enrollment_product_search_placeholder') }}"
                                   x-model="productQuery" x-on:input.debounce.350ms="loadProducts(1)">
                        </div>
                        <label class="bulk-enrollment-transfer__select-all">
                            <input type="checkbox" x-on:change="toggleVisibleProducts($event.target.checked)"
                                   :checked="eligibleVisibleProducts.length > 0 && eligibleVisibleProducts.every(item => hasProduct(item.id))"
                                   :disabled="!productEligibilityReady || eligibleVisibleProducts.length === 0">
                            {{ __('lf.LF_bulk_enrollment_select_eligible_visible') }}
                        </label>
                        <div class="admin-table-wrap bulk-enrollment-selector__list" :class="{ 'is-empty': !productLoading && productResults.length === 0 }"><table class="table"><tbody>
                            <template x-for="item in productResults" :key="item.id"><tr :class="{ 'is-selected-invalid': productEligibilityReady && hasProduct(item.id) && item.eligibility === 'ineligible' }">
                                <td><input type="checkbox" :checked="hasProduct(item.id)"
                                           x-on:click="if (selectedStudents.length === 0) { $event.preventDefault(); promptForStudentSelection($event.currentTarget) }"
                                           x-on:change="toggleProduct(item, $event.target.checked)"
                                           :disabled="selectedStudents.length > 0 && (!productEligibilityReady || item.eligibility !== 'eligible')"
                                           :aria-disabled="selectedStudents.length === 0 || !productEligibilityReady || item.eligibility !== 'eligible'"
                                           :aria-label="`${item.title} · ${eligibilityLabel(item)}`"
                                           :aria-describedby="item.eligibility === 'ineligible' ? `product-reason-${item.id}` : null"></td>
                                <td><strong x-text="item.title"></strong>
                                    <span class="bulk-enrollment-product-meta">
                                        <span x-show="item.code"><span class="bulk-enrollment-product-meta__label">{{ __('lf.LF_course_product_common_product_code') }}</span><span x-text="item.code"></span></span>
                                        <span x-show="item.version?.code"><span class="bulk-enrollment-product-meta__label">{{ __('lf.LF_course_enrollment_common_version') }}</span><span x-text="item.version?.code"></span></span>
                                        <span x-show="item.access_duration_days"><span x-text="@js(__('lf.LF_course_enrollment_duration_source')).replace(':access_days', item.access_duration_days).replace(':review_days', item.review_duration_days || 0)"></span></span>
                                    </span>
                                    <div class="bulk-enrollment-product-eligibility">
                                        <span x-show="!productEligibilityReady && selectedStudents.length > 0" class="bulk-enrollment-eligibility-badge is-checking" x-cloak>{{ __('lf.LF_bulk_enrollment_eligibility_loading') }}</span>
                                        <span x-show="productEligibilityReady" class="bulk-enrollment-eligibility-badge" :class="`is-${item.eligibility || 'unchecked'}`" x-text="eligibilityLabel(item)" x-cloak></span>
                                        <div :id="`product-reason-${item.id}`" x-show="productEligibilityReady && item.eligibility === 'ineligible'"
                                             class="bulk-enrollment-invalid-reason"
                                             :class="{ 'is-selected': hasProduct(item.id) }" x-cloak>
                                            <span x-text="eligibilityReason(item)"></span>
                                            <button x-show="hasProduct(item.id)" type="button" class="admin-text-action"
                                                    x-on:click="toggleProduct(item, false)">{{ __('lf.LF_bulk_enrollment_deselect') }}</button>
                                        </div>
                                    </div>
                                </td>
                            </tr></template>
                            <tr x-show="!productLoading && productResults.length === 0" class="bulk-enrollment-product-empty" x-cloak>
                                <td colspan="2">
                                    <strong>{{ __('lf.LF_bulk_enrollment_no_eligible_products') }}</strong>
                                    <span>{{ __('lf.LF_bulk_enrollment_no_eligible_products_help') }}</span>
                                </td>
                            </tr>
                        </tbody></table></div>
                        <div x-show="productEligibilityError" class="admin-alert admin-alert-danger" role="alert" x-cloak>
                            <span>{{ __('lf.LF_bulk_enrollment_eligibility_error') }}</span>
                            <button type="button" class="admin-text-action" x-on:click="loadProducts(productPage)">{{ __('lf.LF_bulk_enrollment_retry') }}</button>
                        </div>
                        <nav class="bulk-enrollment-pagination" aria-label="{{ __('lf.LF_bulk_enrollment_products_pagination') }}">
                            <button type="button" class="bulk-enrollment-pagination__button" x-on:click="loadProducts(productPage - 1)" :disabled="productPage <= 1"><span aria-hidden="true">←</span><span>{{ __('lf.LF_bulk_enrollment_previous') }}</span></button>
                            <span class="bulk-enrollment-pagination__status"><span class="sr-only">{{ __('lf.LF_bulk_enrollment_page') }}</span><span x-text="`${productPage} / ${productLastPage}`"></span></span>
                            <button type="button" class="bulk-enrollment-pagination__button" x-on:click="loadProducts(productPage + 1)" :disabled="productPage >= productLastPage"><span>{{ __('lf.LF_bulk_enrollment_next') }}</span><span aria-hidden="true">→</span></button>
                        </nav>
                        <div x-show="productEligibilityReady && ineligibleProductCount > 0" class="bulk-enrollment-ineligible-group" x-cloak>
                            <button type="button" class="bulk-enrollment-ineligible-toggle"
                                    x-on:click="ineligibleProductsVisible = !ineligibleProductsVisible"
                                    :aria-expanded="ineligibleProductsVisible.toString()">
                                <span x-text="ineligibleProductsVisible ? @js(__('lf.LF_bulk_enrollment_hide_ineligible')) : @js(__('lf.LF_bulk_enrollment_show_ineligible')).replace(':count', ineligibleProductCount)"></span>
                                <span aria-hidden="true" x-text="ineligibleProductsVisible ? '−' : '+'"></span>
                            </button>
                            <div x-show="ineligibleProductsVisible" x-cloak>
                                <div class="admin-table-wrap bulk-enrollment-selector__list"><table class="table"><tbody>
                                    <template x-for="item in ineligibleProductResults" :key="`ineligible-${item.id}`"><tr :class="{ 'is-selected-invalid': hasProduct(item.id) }">
                                        <td><input type="checkbox" :checked="hasProduct(item.id)" disabled aria-disabled="true"
                                                   :aria-label="`${item.title} · ${eligibilityLabel(item)}`"></td>
                                        <td><strong x-text="item.title"></strong>
                                            <span class="bulk-enrollment-product-meta">
                                                <span x-show="item.code"><span class="bulk-enrollment-product-meta__label">{{ __('lf.LF_course_product_common_product_code') }}</span><span x-text="item.code"></span></span>
                                                <span x-show="item.version?.code"><span class="bulk-enrollment-product-meta__label">{{ __('lf.LF_course_enrollment_common_version') }}</span><span x-text="item.version?.code"></span></span>
                                            </span>
                                            <span class="bulk-enrollment-eligibility-badge is-ineligible">{{ __('lf.LF_bulk_enrollment_ineligible') }}</span>
                                            <div class="bulk-enrollment-invalid-reason" :class="{ 'is-selected': hasProduct(item.id) }">
                                                <span x-text="eligibilityReason(item)"></span>
                                                <button x-show="hasProduct(item.id)" type="button" class="admin-text-action" x-on:click="toggleProduct(item, false)">{{ __('lf.LF_bulk_enrollment_deselect') }}</button>
                                            </div>
                                        </td>
                                    </tr></template>
                                </tbody></table></div>
                                <nav x-show="ineligibleProductLastPage > 1" class="bulk-enrollment-pagination bulk-enrollment-pagination--secondary" aria-label="{{ __('lf.LF_bulk_enrollment_products_pagination') }}" x-cloak>
                                    <button type="button" class="bulk-enrollment-pagination__button" x-on:click="loadProducts(productPage, ineligibleProductPage - 1)" :disabled="ineligibleProductPage <= 1"><span aria-hidden="true">←</span><span>{{ __('lf.LF_bulk_enrollment_previous') }}</span></button>
                                    <span class="bulk-enrollment-pagination__status" x-text="`${ineligibleProductPage} / ${ineligibleProductLastPage}`"></span>
                                    <button type="button" class="bulk-enrollment-pagination__button" x-on:click="loadProducts(productPage, ineligibleProductPage + 1)" :disabled="ineligibleProductPage >= ineligibleProductLastPage"><span>{{ __('lf.LF_bulk_enrollment_next') }}</span><span aria-hidden="true">→</span></button>
                                </nav>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="bulk-enrollment-pair-summary"
                     :class="{ 'is-near-limit': pairCount >= 80 && pairCount <= 100, 'is-over-limit': pairCount > 100 }"
                     aria-live="polite">
                    <strong x-text="pairCountLabel"></strong>
                    <span>{{ __('lf.LF_bulk_enrollment_pair_limit_help') }}</span>
                </div>
            </section>

            <section x-show="step === 2" class="admin-form-standard-section bulk-enrollment-wizard-section" aria-labelledby="bulk-setup-title" x-cloak>
                <header class="admin-form-section-header">
                    <h2 id="bulk-setup-title" class="admin-form-section-title">{{ __('lf.LF_bulk_enrollment_setup_confirm') }}</h2>
                    <p class="admin-form-section-help">{{ __('lf.LF_bulk_enrollment_admin_override_help') }}</p>
                </header>

                <section class="admin-form-standard-section bulk-enrollment-confirmation" aria-label="{{ __('lf.LF_bulk_enrollment_information_section') }}">
                    <div class="bulk-enrollment-pair-summary bulk-enrollment-confirmation__summary"><strong x-text="pairCountLabel"></strong><button type="button" class="admin-text-action" x-on:click="backToSelection">{{ __('lf.LF_bulk_enrollment_change') }}</button></div>
                    <button x-show="reenrollmentPairs.length > 1" type="button" class="btn btn-secondary" x-on:click="confirmAllReenrollments">{{ __('lf.LF_bulk_enrollment_confirm_all_reenrollments') }}</button>
                    <div class="admin-table-wrap bulk-enrollment-review-table"><table class="table">
                        <thead><tr><th class="bulk-enrollment-review-table__number">{{ __('lf.table_no') }}</th><th>{{ __('lf.LF_course_enrollment_common_student') }}</th><th>{{ __('lf.LF_course_enrollment_common_product') }}</th><th class="bulk-enrollment-review-table__result">{{ __('lf.LF_bulk_enrollment_expected_result') }}</th></tr></thead>
                        <tbody><template x-for="(pair, pairIndex) in paginatedPairs" :key="`${pair.student_id}:${pair.product_id}`"><tr>
                            <td class="bulk-enrollment-review-table__number" x-text="(confirmationPage - 1) * confirmationPerPage + pairIndex + 1"></td>
                            <td x-text="pair.student_name"></td>
                            <td>
                                <strong class="bulk-enrollment-review-product" x-text="pair.product_title"></strong>
                                <dl x-show="pair.time_windows" class="bulk-enrollment-product-window">
                                    <div>
                                        <dt>{{ __('lf.LF_bulk_enrollment_access_time') }}</dt>
                                        <dd>
                                            <span x-text="`${pair.time_windows?.access_duration_days} {{ __('lf.LF_bulk_enrollment_days') }}`"></span>
                                            <small x-text="`${formatDateTime(pair.time_windows?.access_starts_at)} → ${formatDateTime(pair.time_windows?.access_ends_at)}`"></small>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>{{ __('lf.LF_bulk_enrollment_review_time') }}</dt>
                                        <dd x-show="pair.time_windows?.review_duration_days > 0">
                                            <span x-text="`${pair.time_windows?.review_duration_days} {{ __('lf.LF_bulk_enrollment_days') }}`"></span>
                                            <small x-text="`${formatDateTime(pair.time_windows?.review_starts_at)} → ${formatDateTime(pair.time_windows?.review_ends_at)}`"></small>
                                        </dd>
                                        <dd x-show="!pair.time_windows?.review_duration_days" class="is-empty">{{ __('lf.LF_bulk_enrollment_no_review_time') }}</dd>
                                    </div>
                                </dl>
                            </td>
                            <td class="bulk-enrollment-review-table__result"><span x-show="pair.status === 'creatable'" class="bulk-enrollment-pair-status is-new">{{ __('lf.LF_bulk_enrollment_new') }}</span>
                                <div x-show="pair.status === 'reenrollment_eligible'" class="bulk-enrollment-reenrollment-confirmation">
                                    <label><input type="checkbox" x-model="confirmedPairKeys" :value="`${pair.student_id}:${pair.product_id}`"> {{ __('lf.LF_bulk_enrollment_confirm_reenroll') }}</label>
                                    <p x-text="@js(__('lf.LF_bulk_enrollment_confirm_reenroll_help')).replace(':id', pair.previous_enrollment_id)"></p>
                                </div>
                                <span x-show="pair.reason && pair.status !== 'reenrollment_eligible'" x-text="pair.reason"></span>
                            </td>
                        </tr></template></tbody>
                    </table></div>
                    <nav x-show="confirmationLastPage > 1" class="bulk-enrollment-pagination" aria-label="{{ __('lf.LF_bulk_enrollment_page') }}" x-cloak>
                        <button type="button" class="bulk-enrollment-pagination__button" x-on:click="goToConfirmationPage(confirmationPage - 1)" :disabled="confirmationPage <= 1"><span aria-hidden="true">←</span><span>{{ __('lf.LF_bulk_enrollment_previous') }}</span></button>
                        <span class="bulk-enrollment-pagination__status"><span class="sr-only">{{ __('lf.LF_bulk_enrollment_page') }}</span><span x-text="`${confirmationPage} / ${confirmationLastPage}`"></span></span>
                        <button type="button" class="bulk-enrollment-pagination__button" x-on:click="goToConfirmationPage(confirmationPage + 1)" :disabled="confirmationPage >= confirmationLastPage"><span>{{ __('lf.LF_bulk_enrollment_next') }}</span><span aria-hidden="true">→</span></button>
                    </nav>
                    <dl class="bulk-enrollment-confirmation__facts">
                        <div><dt>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</dt><dd x-text="formatDateTime(configuration.enrolled_at)"></dd></div>
                        <div><dt>{{ __('lf.LF_bulk_enrollment_status_after_creation') }}</dt><dd><span class="badge badge-success">{{ __('lf.LF_course_enrollment_common_active') }}</span></dd></div>
                        <div><dt>{{ __('lf.LF_course_enrollment_common_source') }}</dt><dd>{{ __('lf.LF_course_enrollment_common_source_admin') }}</dd></div>
                    </dl>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="bulk-notes-label">
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field admin-form-field--full"><label id="bulk-notes-label" class="lf-form-label" for="bulk-notes">{{ __('lf.LF_course_enrollment_internal_notes') }}</label><textarea id="bulk-notes" name="configuration[notes]" class="lf-form-control" rows="3" x-model="configuration.notes"></textarea></div>
                    </div>
                </section>
            </section>

            <footer class="admin-form-footer" data-actions-align="end">
                <div class="admin-form-footer-primary">
                    <a href="{{ route($routePrefix.'.index') }}" class="btn btn-secondary">{{ __('lf.LF_common_button_cancel') }}</a>
                    <button x-show="step === 2" type="button" class="btn btn-secondary" x-on:click="backToSelection" x-cloak>{{ __('lf.LF_bulk_enrollment_back') }}</button>
                    <button x-show="step === 1" type="button" class="btn btn-primary" x-on:click="continueToSetup" :disabled="loading || !productEligibilityReady || hasInvalidSelectedProducts || pairCount < 1 || pairCount > 100">{{ __('lf.LF_bulk_enrollment_continue') }}</button>
                    <button x-show="step === 2" type="submit" class="btn btn-primary" :disabled="loading || submitting" x-cloak><span x-show="!submitting">{{ __('lf.LF_bulk_enrollment_submit') }}</span><span x-show="submitting">{{ __('lf.LF_course_enrollment_update_saving') }}</span></button>
                </div>
            </footer>
            </form>
        </div>

        <div x-show="productSelectionPromptVisible" x-cloak class="admin-modal-backdrop"
             x-on:keydown.escape.window="closeProductSelectionPrompt" x-on:click.self="closeProductSelectionPrompt">
            <section class="admin-modal bulk-enrollment-guidance-modal" role="dialog" aria-modal="true" aria-labelledby="bulk-product-guidance-title" aria-describedby="bulk-product-guidance-content">
                <header class="admin-modal-header">
                    <h2 id="bulk-product-guidance-title">{{ __('lf.LF_bulk_enrollment_select_student_popup_title') }}</h2>
                </header>
                <div class="bulk-enrollment-guidance-modal__body">
                    <p id="bulk-product-guidance-content">{{ __('lf.LF_bulk_enrollment_select_student_first') }}</p>
                </div>
                <footer class="bulk-enrollment-guidance-modal__footer">
                    <button x-ref="productPromptClose" type="button" class="btn btn-primary" x-on:click="closeProductSelectionPrompt">{{ __('lf.LF_bulk_enrollment_acknowledge') }}</button>
                </footer>
            </section>
        </div>

        <div x-show="enrollmentDatePromptVisible" x-cloak class="admin-modal-backdrop"
             x-on:keydown.escape.window="closeEnrollmentDatePrompt" x-on:click.self="closeEnrollmentDatePrompt">
            <section class="admin-modal bulk-enrollment-guidance-modal" role="dialog" aria-modal="true" aria-labelledby="bulk-enrollment-date-guidance-title" aria-describedby="bulk-enrollment-date-guidance-content">
                <header class="admin-modal-header">
                    <h2 id="bulk-enrollment-date-guidance-title">{{ __('lf.LF_course_enrollment_enrolled_at_popup_title') }}</h2>
                </header>
                <div class="bulk-enrollment-guidance-modal__body">
                    <p id="bulk-enrollment-date-guidance-content">{{ __('lf.LF_course_enrollment_enrolled_at_required') }}</p>
                </div>
                <footer class="bulk-enrollment-guidance-modal__footer">
                    <button x-ref="enrollmentDatePromptClose" type="button" class="btn btn-primary" x-on:click="closeEnrollmentDatePrompt">{{ __('lf.LF_bulk_enrollment_acknowledge') }}</button>
                </footer>
            </section>
        </div>
    </div>

    <script>
        function bulkEnrollmentWizard(config) {
            return {
                step: 1, selectedStudents: [], selectedProducts: [], studentResults: [], productResults: [], ineligibleProductResults: [],
                studentQuery: '', productQuery: '', studentPage: 1, productPage: 1, studentLastPage: 1, productLastPage: 1, ineligibleProductPage: 1, ineligibleProductLastPage: 1,
                studentLoading: false, productLoading: false, loading: false, submitting: false, pairs: [], confirmationPage: 1, confirmationPerPage: 10,
                confirmedPairKeys: [], submissionToken: '', errorMessage: '', productEligibilityError: false,
                productEligibilityReady: false, productRequestVersion: 0, productEligibilityTimer: null, productAbortController: null,
                productOnboardingShown: false, productGuidanceHighlighted: false, productHighlightTimer: null, productSelectionPromptVisible: false, productPromptTrigger: null,
                enrollmentDatePromptVisible: false, enrollmentDatePromptTrigger: null,
                eligibleProductCount: 0, ineligibleProductCount: 0, ineligibleProductsVisible: false,
                configuration: { enrolled_at: @js(now()->format('Y-m-d\TH:i')), notes: null },
                init() { this.loadStudents(1); this.loadProducts(1) },
                get pairCount() { return this.selectedStudents.length * this.selectedProducts.length },
                get pairCountLabel() { return @js(__('lf.LF_bulk_enrollment_pair_count')).replace(':students', this.selectedStudents.length).replace(':products', this.selectedProducts.length).replace(':pairs', this.pairCount) },
                get selectedStudentsLabel() { return @js(__('lf.LF_bulk_enrollment_selected_students')).replace(':count', this.selectedStudents.length) },
                get selectedProductsLabel() { return @js(__('lf.LF_bulk_enrollment_selected_products')).replace(':count', this.selectedProducts.filter(item => item.eligibility === 'eligible').length).replace(':eligible', this.eligibleProductCount) },
                get eligibleVisibleProducts() { return this.productResults.filter(item => item.eligibility === 'eligible') },
                get selectedInvalidProducts() { return this.selectedProducts.filter(item => item.eligibility === 'ineligible') },
                get hasInvalidSelectedProducts() { return this.selectedInvalidProducts.length > 0 },
                get productEligibilityAnnouncement() { if (this.productLoading && this.selectedStudents.length) return @js(__('lf.LF_bulk_enrollment_eligibility_loading')); if (!this.productEligibilityReady) return ''; return @js(__('lf.LF_bulk_enrollment_eligibility_result')).replace(':eligible', this.eligibleProductCount).replace(':total', this.eligibleProductCount + this.ineligibleProductCount) },
                get reenrollmentPairs() { return this.pairs.filter(pair => pair.status === 'reenrollment_eligible') },
                get confirmationLastPage() { return Math.max(1, Math.ceil(this.pairs.length / this.confirmationPerPage)) },
                get paginatedPairs() { const offset = (this.confirmationPage - 1) * this.confirmationPerPage; return this.pairs.slice(offset, offset + this.confirmationPerPage) },
                get confirmedPairs() { return this.reenrollmentPairs.filter(pair => this.confirmedPairKeys.includes(`${pair.student_id}:${pair.product_id}`)).map(pair => ({ student_id: pair.student_id, product_id: pair.product_id, previous_enrollment_id: pair.previous_enrollment_id })) },
                hasStudent(id) { return this.selectedStudents.some(item => String(item.id) === String(id)) },
                hasProduct(id) { return this.selectedProducts.some(item => String(item.id) === String(id)) },
                canProject(students, products) { return students * products <= 100 },
                toggleStudent(item, checked) { if (checked && !this.canProject(this.selectedStudents.length + 1, Math.max(1, this.selectedProducts.length))) return this.limitError(); const shouldGuideProducts = checked && this.selectedStudents.length === 0 && !this.productOnboardingShown; this.selectedStudents = checked ? [...this.selectedStudents.filter(row => row.id !== item.id), item] : this.selectedStudents.filter(row => row.id !== item.id); if (this.selectedStudents.length > 0) this.productSelectionPromptVisible = false; this.resetPreflight(); this.scheduleProductEligibility(); if (shouldGuideProducts) this.guideToProductsOnce() },
                toggleProduct(item, checked) { if (checked && item.eligibility !== 'eligible') return; if (checked && !this.canProject(Math.max(1, this.selectedStudents.length), this.selectedProducts.length + 1)) return this.limitError(); this.selectedProducts = checked ? [...this.selectedProducts.filter(row => row.id !== item.id), item] : this.selectedProducts.filter(row => row.id !== item.id); this.resetPreflight() },
                toggleVisibleStudents(checked) { for (const item of this.studentResults) { if (checked && !this.hasStudent(item.id)) this.toggleStudent(item, true); if (!checked && this.hasStudent(item.id)) this.toggleStudent(item, false) } },
                toggleVisibleProducts(checked) { for (const item of this.eligibleVisibleProducts) { if (checked && !this.hasProduct(item.id)) this.toggleProduct(item, true); if (!checked && this.hasProduct(item.id)) this.toggleProduct(item, false) } },
                enrollmentDateChanged() { this.resetPreflight(); this.scheduleProductEligibility() },
                async validateEnrollmentDate(trigger = null) { if (this.configuration.enrolled_at) return true; this.enrollmentDatePromptTrigger = trigger || document.activeElement; this.enrollmentDatePromptVisible = true; await this.$nextTick(); this.$refs.enrollmentDatePromptClose.focus(); return false },
                async closeEnrollmentDatePrompt() { if (!this.enrollmentDatePromptVisible) return; this.enrollmentDatePromptVisible = false; await this.$nextTick(); document.getElementById('bulk-enrolled-at')?.focus(); this.enrollmentDatePromptTrigger = null },
                formatDateTime(value) { if (!value) return '—'; return new Intl.DateTimeFormat(document.documentElement.lang || 'vi', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) },
                goToConfirmationPage(page) { if (page < 1 || page > this.confirmationLastPage) return; this.confirmationPage = page },
                limitError() { this.errorMessage = @js(__('lf.LF_bulk_enrollment_validation_pair_limit')) },
                async promptForStudentSelection(trigger) { if (this.selectedStudents.length > 0 || this.productSelectionPromptVisible) return; this.productPromptTrigger = trigger; this.productSelectionPromptVisible = true; await this.$nextTick(); this.$refs.productPromptClose.focus() },
                async closeProductSelectionPrompt() { if (!this.productSelectionPromptVisible) return; this.productSelectionPromptVisible = false; await this.$nextTick(); this.productPromptTrigger?.focus(); this.productPromptTrigger = null },
                async guideToProductsOnce() { if (this.productOnboardingShown) return; this.productOnboardingShown = true; this.productGuidanceHighlighted = true; clearTimeout(this.productHighlightTimer); this.productHighlightTimer = setTimeout(() => { this.productGuidanceHighlighted = false }, 1800); await this.$nextTick(); const panel = this.$refs.productPanel; const heading = this.$refs.productHeading; if (!panel || !heading) return; const bounds = panel.getBoundingClientRect(); const panelIsVisible = bounds.top >= 0 && bounds.bottom <= window.innerHeight; const smallViewport = window.matchMedia('(max-width: 767px)').matches; if (smallViewport || !panelIsVisible) { panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); heading.focus({ preventScroll: true }) } },
                resetPreflight() { this.errorMessage = ''; this.pairs = []; this.confirmedPairKeys = []; this.invalidateToken() },
                scheduleProductEligibility() { clearTimeout(this.productEligibilityTimer); this.productRequestVersion++; this.productAbortController?.abort(); this.productEligibilityReady = false; this.productEligibilityError = false; this.productEligibilityTimer = setTimeout(() => this.loadProducts(1), 300) },
                removeInvalidProducts() { this.selectedProducts = this.selectedProducts.filter(item => item.eligibility !== 'ineligible'); this.resetPreflight() },
                eligibilityLabel(item) { if (!item.eligibility) return @js(__('lf.LF_bulk_enrollment_eligibility_unchecked')); return item.eligibility === 'eligible' ? @js(__('lf.LF_bulk_enrollment_eligible')) : @js(__('lf.LF_bulk_enrollment_ineligible')) },
                eligibilityReason(item) { if (!item.invalid_pairs?.length) return @js(__('lf.LF_bulk_enrollment_ineligible_generic')); const groups = item.invalid_pairs.reduce((result, pair) => { const reason = pair.reason || @js(__('lf.LF_bulk_enrollment_ineligible_generic')); if (!result[reason]) result[reason] = []; result[reason].push(pair.student_name); return result }, {}); return Object.entries(groups).map(([reason, students]) => students.length === this.selectedStudents.length ? reason : `${students.join(', ')}: ${reason}`).join(' · ') },
                async loadStudents(page) { if (page < 1) return; this.studentLoading = true; try { const data = await this.fetchSearch(config.studentUrl, this.studentQuery, page); this.studentResults = data.data; this.studentPage = data.pagination.current_page; this.studentLastPage = data.pagination.last_page } finally { this.studentLoading = false } },
                async loadProducts(page, ineligiblePage = 1) { if (page < 1 || ineligiblePage < 1) return; const requestVersion = ++this.productRequestVersion; this.productAbortController?.abort(); this.productAbortController = new AbortController(); this.productLoading = true; this.productEligibilityReady = false; this.productEligibilityError = false; try { const params = new URLSearchParams({ q: this.productQuery, page: String(page), ineligible_page: String(ineligiblePage), enrolled_at: this.configuration.enrolled_at }); [...this.selectedStudents].map(item => Number(item.id)).sort((a, b) => a - b).forEach(id => params.append('student_ids[]', String(id))); [...this.selectedProducts].map(item => Number(item.id)).sort((a, b) => a - b).forEach(id => params.append('selected_product_ids[]', String(id))); const response = await fetch(`${config.productUrl}?${params}`, { headers: { Accept: 'application/json' }, signal: this.productAbortController.signal }); if (!response.ok) throw new Error(); const data = await response.json(); if (requestVersion !== this.productRequestVersion) return; this.productResults = data.data; this.ineligibleProductResults = data.ineligible?.data || []; this.productPage = data.pagination.current_page; this.productLastPage = data.pagination.last_page; this.ineligibleProductPage = data.ineligible?.pagination?.current_page || 1; this.ineligibleProductLastPage = data.ineligible?.pagination?.last_page || 1; this.eligibleProductCount = data.counts?.eligible || 0; this.ineligibleProductCount = data.counts?.ineligible || 0; this.selectedProducts = this.selectedStudents.length === 0 ? this.selectedProducts.map(item => ({ ...item, eligibility: null, invalid_pairs: [] })) : this.selectedProducts.map(item => ({ ...item, ...(data.selected_eligibility?.[String(item.id)] || { eligibility: 'ineligible', invalid_pair_count: this.selectedStudents.length, invalid_pairs: [] }) })); this.productEligibilityReady = this.selectedStudents.length > 0 } catch (error) { if (error.name === 'AbortError' || requestVersion !== this.productRequestVersion) return; this.productEligibilityError = true; this.productEligibilityReady = false } finally { if (requestVersion === this.productRequestVersion) this.productLoading = false } },
                async fetchSearch(url, query, page) { const response = await fetch(`${url}?q=${encodeURIComponent(query)}&page=${page}`, { headers: { Accept: 'application/json' } }); if (!response.ok) throw new Error(); return response.json() },
                payload(finalize) { return { student_ids: this.selectedStudents.map(item => item.id), product_ids: this.selectedProducts.map(item => item.id), reenrollment_confirmations: this.confirmedPairs, configuration: this.configuration, finalize } },
                async runPreflight(finalize, trigger = null) { if (!await this.validateEnrollmentDate(trigger)) return null; this.loading = true; this.errorMessage = ''; try { const response = await fetch(config.preflightUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf }, body: JSON.stringify(this.payload(finalize)) }); const data = await response.json(); if (!response.ok) { this.errorMessage = Object.values(data.errors || {}).flat().join(' '); return null } return data } catch (error) { this.errorMessage = @js(__('lf.LF_bulk_enrollment_search_error')); return null } finally { this.loading = false } },
                async continueToSetup() { if (this.hasInvalidSelectedProducts) { await this.$nextTick(); this.$refs.selectionError.focus(); return } const result = await this.runPreflight(false, document.activeElement); if (!result) return; this.pairs = result.pairs; this.confirmationPage = 1; if (!result.can_continue) { this.errorMessage = @js(__('lf.LF_bulk_enrollment_preflight_blocked')); return } this.step = 2; window.scrollTo({ top: 0, behavior: 'smooth' }) },
                confirmAllReenrollments() { if (window.confirm(@js(__('lf.LF_bulk_enrollment_confirm_all_warning')))) this.confirmedPairKeys = this.reenrollmentPairs.map(pair => `${pair.student_id}:${pair.product_id}`) },
                async commit() { if (this.submitting) return; this.submitting = true; const result = await this.runPreflight(true, document.activeElement); if (!result || !result.valid || !result.submission_token) { if (result) { this.pairs = result.pairs; this.errorMessage = @js(__('lf.LF_bulk_enrollment_confirmation_required')) } this.submitting = false; return } this.submissionToken = result.submission_token; await this.$nextTick(); this.$refs.form.submit() },
                async backToSelection() { await this.invalidateToken(); this.step = 1; this.submitting = false; window.scrollTo({ top: 0, behavior: 'smooth' }) },
                async invalidateToken() { if (!this.submissionToken) return; const token = this.submissionToken; this.submissionToken = ''; await fetch(config.invalidateUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf }, body: JSON.stringify({ submission_token: token }) }) },
            }
        }
    </script>
    @endif
@endsection
