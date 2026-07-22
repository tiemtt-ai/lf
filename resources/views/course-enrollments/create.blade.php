@extends('layouts.backend')

@section('title', __('lf.LF_bulk_enrollment_title'))
@section('page_title', __('lf.LF_bulk_enrollment_title'))

@section('content')
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
            <form x-ref="form" class="admin-form-standard" method="POST"
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
                <strong id="bulk-selection-error-title">{{ __('lf.LF_bulk_enrollment_preflight_blocked') }}</strong>
                <button type="button" class="admin-text-action" x-on:click="removeInvalidProducts">{{ __('lf.LF_bulk_enrollment_remove_invalid_products') }}</button>
            </div>

            <section x-show="step === 1" class="admin-form-standard-section bulk-enrollment-wizard-section" aria-labelledby="bulk-selection-title">
                <header class="admin-form-section-header">
                    <h2 id="bulk-selection-title" class="admin-form-section-title">{{ __('lf.LF_bulk_enrollment_select_students_products') }}</h2>
                    <p class="admin-form-section-help">{{ __('lf.LF_bulk_enrollment_cartesian_help') }}</p>
                </header>

                <div x-show="selectedStudents.length === 0" class="admin-alert admin-alert-info" role="status" x-cloak>
                    <strong class="admin-alert-title">{{ __('lf.LF_bulk_enrollment_start_title') }}</strong>
                    <p class="admin-alert-guidance">{{ __('lf.LF_bulk_enrollment_start_content') }}</p>
                </div>
                <div x-show="selectedStudents.length > 0 && selectedProducts.length === 0" class="admin-alert admin-alert-info" role="status" x-cloak>
                    <strong class="admin-alert-title">{{ __('lf.LF_bulk_enrollment_select_products_title') }}</strong>
                    <p class="admin-alert-guidance">{{ __('lf.LF_bulk_enrollment_select_products_content') }}</p>
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

                    <section x-ref="productPanel" class="bulk-enrollment-selector" :class="{ 'is-onboarding-highlight': productGuidanceHighlighted }" aria-labelledby="bulk-products-title" :aria-busy="productLoading">
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
                        <div class="admin-table-wrap bulk-enrollment-selector__list"><table class="table"><tbody>
                            <template x-for="item in productResults" :key="item.id"><tr>
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
                                    </span>
                                    <span class="bulk-enrollment-eligibility-badge" :class="`is-${item.eligibility || 'unchecked'}`" x-text="eligibilityLabel(item)"></span>
                                    <span :id="`product-reason-${item.id}`" x-show="item.eligibility === 'ineligible'" class="course-cohort-index-meta" x-text="eligibilityReason(item)" x-cloak></span>
                                    <button x-show="hasProduct(item.id) && item.eligibility === 'ineligible'" type="button" class="admin-text-action" x-on:click="toggleProduct(item, false)" x-cloak>{{ __('lf.LF_bulk_enrollment_deselect') }}</button>
                                </td>
                            </tr></template>
                            <tr x-show="!productLoading && productResults.length === 0" x-cloak><td>{{ __('lf.LF_course_enrollment_search_empty') }}</td></tr>
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
                        <thead><tr><th>{{ __('lf.LF_course_enrollment_common_student') }}</th><th>{{ __('lf.LF_course_enrollment_common_product') }}</th><th>{{ __('lf.LF_bulk_enrollment_expected_result') }}</th></tr></thead>
                        <tbody><template x-for="pair in pairs" :key="`${pair.student_id}:${pair.product_id}`"><tr>
                            <td x-text="pair.student_name"></td><td x-text="pair.product_title"></td>
                            <td><span x-show="pair.status === 'creatable'" class="bulk-enrollment-pair-status is-new">{{ __('lf.LF_bulk_enrollment_new') }}</span>
                                <label x-show="pair.status === 'reenrollment_eligible'"><input type="checkbox" x-model="confirmedPairKeys" :value="`${pair.student_id}:${pair.product_id}`"> {{ __('lf.LF_bulk_enrollment_confirm_reenroll') }} #<span x-text="pair.previous_enrollment_id"></span></label>
                                <span x-show="pair.reason && pair.status !== 'reenrollment_eligible'" x-text="pair.reason"></span>
                            </td>
                        </tr></template></tbody>
                    </table></div>
                    <dl class="bulk-enrollment-confirmation__facts">
                        <div><dt>{{ __('lf.LF_bulk_enrollment_status_after_creation') }}</dt><dd><span class="badge badge-success">{{ __('lf.LF_course_enrollment_common_active') }}</span></dd></div>
                        <div><dt>{{ __('lf.LF_course_enrollment_common_source') }}</dt><dd>{{ __('lf.LF_course_enrollment_common_source_admin') }}</dd></div>
                    </dl>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="bulk-access-window">
                    <header class="admin-form-section-header"><h3 id="bulk-access-window" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_access_window') }}</h3><p class="admin-form-section-help">{{ __('lf.LF_course_enrollment_access_help') }}</p></header>
                    <div class="admin-form-field-grid">
                        @foreach (['access_starts_at', 'access_ends_at'] as $field)
                            <div class="lf-form-group admin-form-field"><label class="lf-form-label" for="bulk-{{ $field }}">{{ __('lf.LF_course_enrollment_common_'.$field) }}</label><input id="bulk-{{ $field }}" type="datetime-local" :name="`configuration[{{ $field }}]`" class="lf-form-control" x-model="configuration.{{ $field }}"></div>
                        @endforeach
                    </div>
                </section>
                <section class="admin-form-standard-section" aria-labelledby="bulk-review-window">
                    <header class="admin-form-section-header"><h3 id="bulk-review-window" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_review_window') }}</h3><p class="admin-form-section-help">{{ __('lf.LF_course_enrollment_review_help') }}</p></header>
                    <div class="admin-form-field-grid">
                        @foreach (['review_starts_at', 'review_ends_at'] as $field)
                            <div class="lf-form-group admin-form-field"><label class="lf-form-label" for="bulk-{{ $field }}">{{ __('lf.LF_course_enrollment_common_'.$field) }}</label><input id="bulk-{{ $field }}" type="datetime-local" :name="`configuration[{{ $field }}]`" class="lf-form-control" x-model="configuration.{{ $field }}"></div>
                        @endforeach
                        <div class="lf-form-group admin-form-field admin-form-field--full"><label class="lf-form-label" for="bulk-notes">{{ __('lf.LF_course_enrollment_internal_notes') }}</label><textarea id="bulk-notes" name="configuration[notes]" class="lf-form-control" rows="3" x-model="configuration.notes"></textarea></div>
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
    </div>

    <script>
        function bulkEnrollmentWizard(config) {
            return {
                step: 1, selectedStudents: [], selectedProducts: [], studentResults: [], productResults: [],
                studentQuery: '', productQuery: '', studentPage: 1, productPage: 1, studentLastPage: 1, productLastPage: 1,
                studentLoading: false, productLoading: false, loading: false, submitting: false, pairs: [],
                confirmedPairKeys: [], submissionToken: '', errorMessage: '', productEligibilityError: false,
                productEligibilityReady: false, productRequestVersion: 0, productEligibilityTimer: null, productAbortController: null,
                productOnboardingShown: false, productGuidanceHighlighted: false, productHighlightTimer: null, productSelectionPromptVisible: false, productPromptTrigger: null,
                configuration: { access_starts_at: null, access_ends_at: null, review_starts_at: null, review_ends_at: null, notes: null },
                init() { this.loadStudents(1); this.loadProducts(1) },
                get pairCount() { return this.selectedStudents.length * this.selectedProducts.length },
                get pairCountLabel() { return @js(__('lf.LF_bulk_enrollment_pair_count')).replace(':students', this.selectedStudents.length).replace(':products', this.selectedProducts.length).replace(':pairs', this.pairCount) },
                get selectedStudentsLabel() { return @js(__('lf.LF_bulk_enrollment_selected_students')).replace(':count', this.selectedStudents.length) },
                get selectedProductsLabel() { return @js(__('lf.LF_bulk_enrollment_selected_products')).replace(':count', this.selectedProducts.length) },
                get eligibleVisibleProducts() { return this.productResults.filter(item => item.eligibility === 'eligible') },
                get hasInvalidSelectedProducts() { return this.selectedProducts.some(item => item.eligibility === 'ineligible') },
                get productEligibilityAnnouncement() { if (this.productLoading && this.selectedStudents.length) return @js(__('lf.LF_bulk_enrollment_eligibility_loading')); if (!this.productEligibilityReady) return ''; const eligible = this.productResults.filter(item => item.eligibility === 'eligible').length; return @js(__('lf.LF_bulk_enrollment_eligibility_result')).replace(':eligible', eligible).replace(':total', this.productResults.length) },
                get reenrollmentPairs() { return this.pairs.filter(pair => pair.status === 'reenrollment_eligible') },
                get confirmedPairs() { return this.reenrollmentPairs.filter(pair => this.confirmedPairKeys.includes(`${pair.student_id}:${pair.product_id}`)).map(pair => ({ student_id: pair.student_id, product_id: pair.product_id, previous_enrollment_id: pair.previous_enrollment_id })) },
                hasStudent(id) { return this.selectedStudents.some(item => String(item.id) === String(id)) },
                hasProduct(id) { return this.selectedProducts.some(item => String(item.id) === String(id)) },
                canProject(students, products) { return students * products <= 100 },
                toggleStudent(item, checked) { if (checked && !this.canProject(this.selectedStudents.length + 1, Math.max(1, this.selectedProducts.length))) return this.limitError(); const shouldGuideProducts = checked && this.selectedStudents.length === 0 && !this.productOnboardingShown; this.selectedStudents = checked ? [...this.selectedStudents.filter(row => row.id !== item.id), item] : this.selectedStudents.filter(row => row.id !== item.id); if (this.selectedStudents.length > 0) this.productSelectionPromptVisible = false; this.resetPreflight(); this.scheduleProductEligibility(); if (shouldGuideProducts) this.guideToProductsOnce() },
                toggleProduct(item, checked) { if (checked && item.eligibility !== 'eligible') return; if (checked && !this.canProject(Math.max(1, this.selectedStudents.length), this.selectedProducts.length + 1)) return this.limitError(); this.selectedProducts = checked ? [...this.selectedProducts.filter(row => row.id !== item.id), item] : this.selectedProducts.filter(row => row.id !== item.id); this.resetPreflight() },
                toggleVisibleStudents(checked) { for (const item of this.studentResults) { if (checked && !this.hasStudent(item.id)) this.toggleStudent(item, true); if (!checked && this.hasStudent(item.id)) this.toggleStudent(item, false) } },
                toggleVisibleProducts(checked) { for (const item of this.eligibleVisibleProducts) { if (checked && !this.hasProduct(item.id)) this.toggleProduct(item, true); if (!checked && this.hasProduct(item.id)) this.toggleProduct(item, false) } },
                limitError() { this.errorMessage = @js(__('lf.LF_bulk_enrollment_validation_pair_limit')) },
                async promptForStudentSelection(trigger) { if (this.selectedStudents.length > 0 || this.productSelectionPromptVisible) return; this.productPromptTrigger = trigger; this.productSelectionPromptVisible = true; await this.$nextTick(); this.$refs.productPromptClose.focus() },
                async closeProductSelectionPrompt() { if (!this.productSelectionPromptVisible) return; this.productSelectionPromptVisible = false; await this.$nextTick(); this.productPromptTrigger?.focus(); this.productPromptTrigger = null },
                async guideToProductsOnce() { if (this.productOnboardingShown) return; this.productOnboardingShown = true; this.productGuidanceHighlighted = true; clearTimeout(this.productHighlightTimer); this.productHighlightTimer = setTimeout(() => { this.productGuidanceHighlighted = false }, 1800); await this.$nextTick(); const panel = this.$refs.productPanel; const heading = this.$refs.productHeading; if (!panel || !heading) return; const bounds = panel.getBoundingClientRect(); const panelIsVisible = bounds.top >= 0 && bounds.bottom <= window.innerHeight; const smallViewport = window.matchMedia('(max-width: 767px)').matches; if (smallViewport || !panelIsVisible) { panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); heading.focus({ preventScroll: true }) } },
                resetPreflight() { this.errorMessage = ''; this.pairs = []; this.confirmedPairKeys = []; this.invalidateToken() },
                scheduleProductEligibility() { clearTimeout(this.productEligibilityTimer); this.productRequestVersion++; this.productAbortController?.abort(); this.productEligibilityReady = false; this.productEligibilityError = false; this.productEligibilityTimer = setTimeout(() => this.loadProducts(1), 300) },
                removeInvalidProducts() { this.selectedProducts = this.selectedProducts.filter(item => item.eligibility !== 'ineligible'); this.resetPreflight() },
                eligibilityLabel(item) { if (!item.eligibility) return @js(__('lf.LF_bulk_enrollment_eligibility_unchecked')); return item.eligibility === 'eligible' ? @js(__('lf.LF_bulk_enrollment_eligible')) : @js(__('lf.LF_bulk_enrollment_ineligible')) },
                eligibilityReason(item) { if (!item.invalid_pairs?.length) return @js(__('lf.LF_bulk_enrollment_ineligible_generic')); return item.invalid_pairs.map(pair => `${pair.student_name}: ${pair.reason}`).join(' · ') },
                async loadStudents(page) { if (page < 1) return; this.studentLoading = true; try { const data = await this.fetchSearch(config.studentUrl, this.studentQuery, page); this.studentResults = data.data; this.studentPage = data.pagination.current_page; this.studentLastPage = data.pagination.last_page } finally { this.studentLoading = false } },
                async loadProducts(page) { if (page < 1) return; const requestVersion = ++this.productRequestVersion; this.productAbortController?.abort(); this.productAbortController = new AbortController(); this.productLoading = true; this.productEligibilityReady = false; this.productEligibilityError = false; try { const params = new URLSearchParams({ q: this.productQuery, page: String(page) }); [...this.selectedStudents].map(item => Number(item.id)).sort((a, b) => a - b).forEach(id => params.append('student_ids[]', String(id))); [...this.selectedProducts].map(item => Number(item.id)).sort((a, b) => a - b).forEach(id => params.append('selected_product_ids[]', String(id))); const response = await fetch(`${config.productUrl}?${params}`, { headers: { Accept: 'application/json' }, signal: this.productAbortController.signal }); if (!response.ok) throw new Error(); const data = await response.json(); if (requestVersion !== this.productRequestVersion) return; this.productResults = data.data; this.productPage = data.pagination.current_page; this.productLastPage = data.pagination.last_page; this.selectedProducts = this.selectedStudents.length === 0 ? this.selectedProducts.map(item => ({ ...item, eligibility: null, invalid_pairs: [] })) : this.selectedProducts.map(item => ({ ...item, ...(data.selected_eligibility?.[String(item.id)] || { eligibility: 'ineligible', invalid_pair_count: this.selectedStudents.length, invalid_pairs: [] }) })); this.productEligibilityReady = this.selectedStudents.length > 0 } catch (error) { if (error.name === 'AbortError' || requestVersion !== this.productRequestVersion) return; this.productEligibilityError = true; this.productEligibilityReady = false } finally { if (requestVersion === this.productRequestVersion) this.productLoading = false } },
                async fetchSearch(url, query, page) { const response = await fetch(`${url}?q=${encodeURIComponent(query)}&page=${page}`, { headers: { Accept: 'application/json' } }); if (!response.ok) throw new Error(); return response.json() },
                payload(finalize) { return { student_ids: this.selectedStudents.map(item => item.id), product_ids: this.selectedProducts.map(item => item.id), reenrollment_confirmations: this.confirmedPairs, configuration: this.configuration, finalize } },
                async runPreflight(finalize) { this.loading = true; this.errorMessage = ''; try { const response = await fetch(config.preflightUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf }, body: JSON.stringify(this.payload(finalize)) }); const data = await response.json(); if (!response.ok) { this.errorMessage = Object.values(data.errors || {}).flat().join(' '); return null } return data } catch (error) { this.errorMessage = @js(__('lf.LF_bulk_enrollment_search_error')); return null } finally { this.loading = false } },
                async continueToSetup() { if (this.hasInvalidSelectedProducts) { await this.$nextTick(); this.$refs.selectionError.focus(); return } const result = await this.runPreflight(false); if (!result) return; this.pairs = result.pairs; if (!result.can_continue) { this.errorMessage = @js(__('lf.LF_bulk_enrollment_preflight_blocked')); return } this.step = 2; window.scrollTo({ top: 0, behavior: 'smooth' }) },
                confirmAllReenrollments() { if (window.confirm(@js(__('lf.LF_bulk_enrollment_confirm_all_warning')))) this.confirmedPairKeys = this.reenrollmentPairs.map(pair => `${pair.student_id}:${pair.product_id}`) },
                async commit() { if (this.submitting) return; this.submitting = true; const result = await this.runPreflight(true); if (!result || !result.valid || !result.submission_token) { if (result) { this.pairs = result.pairs; this.errorMessage = @js(__('lf.LF_bulk_enrollment_confirmation_required')) } this.submitting = false; return } this.submissionToken = result.submission_token; await this.$nextTick(); this.$refs.form.submit() },
                async backToSelection() { await this.invalidateToken(); this.step = 1; this.submitting = false; window.scrollTo({ top: 0, behavior: 'smooth' }) },
                async invalidateToken() { if (!this.submissionToken) return; const token = this.submissionToken; this.submissionToken = ''; await fetch(config.invalidateUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf }, body: JSON.stringify({ submission_token: token }) }) },
            }
        }
    </script>
@endsection
