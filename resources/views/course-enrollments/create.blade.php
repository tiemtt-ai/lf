@extends('layouts.backend')

@section('title', __('lf.LF_bulk_enrollment_title'))
@section('page_title', __('lf.LF_bulk_enrollment_title'))

@section('page_title_hidden', '1')

@section('page_back')
    <x-page-back-link :href="route($routePrefix.'.index')" :label="__('lf.LF_course_enrollment_common_back_to_enrollments')" />
@endsection

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
<div class="lf-admin-ui lf-admin-ui--list enr">
    @php
        $enrollmentSteps = [
            1 => __('lf.LF_bulk_enrollment_design_step_select'),
            2 => __('lf.LF_bulk_enrollment_design_step_review'),
            3 => __('lf.LF_bulk_enrollment_design_step_done'),
        ];
        $completedStep = isset($completedResult) ? 3 : null;
    @endphp

    <div class="enr-head">
        <div>
            <h2>{{ __('lf.LF_bulk_enrollment_title') }}</h2>
            <span class="ui-caption">{{ __('lf.LF_bulk_enrollment_page_description') }}</span>
        </div>
        @unless ($completedStep)
            <span id="enr-stepper-slot"></span>
        @else
            <nav class="enr-stepper" aria-label="{{ __('lf.LF_bulk_enrollment_progress') }}"><ol>
                @foreach ($enrollmentSteps as $number => $label)
                    <li @class(['enr-step', 'is-done' => $number < 3]) @if ($number === 3) aria-current="step" @endif>
                        <span class="enr-step__dot">
                            @if ($number < 3)<x-backend-icon name="check" class="ui-icon" />@else{{ $number }}@endif
                        </span>
                        <span class="enr-step__label">{{ $label }}</span>
                    </li>
                @endforeach
            </ol></nav>
        @endunless
    </div>

    @if (isset($completedResult))
        @php
            $completedContext = $completedResult['context'] ?? [];
            $completedConfiguration = $completedContext['configuration'] ?? [];
            $formatCompletedDateTime = static fn (?string $value): string => $value
                ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i')
                : '—';
            $formatDay = static fn (?string $value): string => $value
                ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y')
                : '—';
            $allItems = collect($completedResult['items'] ?? []);
            $rowNumber = $itemsPaginator->firstItem() ?? 1;
        @endphp

        <div class="enr-success" role="status">
            <span class="enr-success__icon"><x-backend-icon name="check" class="ui-icon" /></span>
            <div class="enr-success__body">
                <h3>{{ __('lf.LF_bulk_enrollment_result_success_title') }}</h3>
                <p>{{ __('lf.LF_bulk_enrollment_result_success_content', ['count' => $completedResult['summary']['total']]) }}</p>
                <dl class="enr-success__facts" aria-label="{{ __('lf.LF_bulk_enrollment_completion_information') }}">
                    <div><dt>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</dt><dd>{{ $formatCompletedDateTime($completedConfiguration['enrolled_at'] ?? ($completedContext['completed_at'] ?? null)) }}</dd></div>
                    <div><dt>{{ __('lf.LF_bulk_enrollment_completed_by') }}</dt><dd>{{ $completedContext['completed_by_name'] ?? '—' }} <span class="enr-success__muted">· {{ __('lf.LF_course_enrollment_common_source_admin') }}</span></dd></div>
                    <div><dt>{{ __('lf.LF_bulk_enrollment_design_status') }}</dt><dd><span class="enr-success__status">{{ __('lf.LF_course_enrollment_common_active') }}</span></dd></div>
                </dl>
            </div>
        </div>

        <section class="mk-section enr-pairs" aria-labelledby="enr-done-title">
            <div class="mk-section-head">
                <div>
                    <h3 id="enr-done-title">{{ __('lf.LF_bulk_enrollment_result_details') }}</h3>
                    <p>{{ __('lf.LF_bulk_enrollment_design_done_summary', ['created' => $completedResult['summary']['created'] ?? 0, 'reenrolled' => $completedResult['summary']['reenrolled'] ?? 0]) }}</p>
                </div>
            </div>
            <div class="mk-table-scroll"><table class="enr-table enr-table--grouped" aria-labelledby="enr-done-title">
                <thead><tr>
                    <th scope="col" class="enr-col-no">{{ __('lf.table_no') }}</th>
                    <th scope="col">{{ __('lf.LF_course_enrollment_common_student') }}</th>
                    <th scope="col">{{ __('lf.LF_bulk_enrollment_design_result') }}</th>
                    <th scope="col" class="enr-col-id">{{ __('lf.LF_bulk_enrollment_enrollment_id') }}</th>
                </tr></thead>
                @foreach ($itemsPaginator->groupBy('product_id') as $productId => $groupItems)
                    @php
                        $first = $groupItems->first();
                        $windows = $first['time_windows'] ?? null;
                    @endphp
                    <tbody>
                        <tr class="enr-prod-row"><td colspan="4"><div class="enr-prod">
                            <span class="enr-prod__name">{{ $first['product_title'] }}</span>
                            <span class="enr-prod__code">{{ collect([$first['product_code'] ?? null, isset($first['version_number']) ? __('lf.LF_bulk_enrollment_design_version', ['number' => $first['version_number']]) : null, $first['version_code'] ?? null])->filter()->implode(' · ') }}</span>
                            <span class="enr-prod__time">
                                <x-backend-icon name="clock" class="ui-icon" />
                                @if (($windows['access_duration_days'] ?? null) === null)
                                    <span>{{ __('lf.LF_bulk_enrollment_design_no_access_limit') }}</span>
                                @else
                                    <span>{{ __('lf.LF_bulk_enrollment_design_access_inline', ['days' => $windows['access_duration_days'], 'from' => $formatDay($windows['access_starts_at'] ?? null), 'to' => $formatDay($windows['access_ends_at'] ?? null)]) }}</span>
                                    <span>{{ ($windows['review_duration_days'] ?? 0) > 0
                                        ? __('lf.LF_bulk_enrollment_design_review_inline', ['days' => $windows['review_duration_days'], 'to' => $formatDay($windows['review_ends_at'] ?? null)])
                                        : __('lf.LF_bulk_enrollment_design_no_review') }}</span>
                                @endif
                            </span>
                            <span class="enr-prod__count">{{ __('lf.LF_bulk_enrollment_design_group_students', ['count' => $allItems->where('product_id', $productId)->count()]) }}</span>
                        </div></td></tr>
                        @foreach ($groupItems as $item)
                            <tr>
                                <td class="enr-col-no">{{ $rowNumber++ }}</td>
                                <td data-label="{{ __('lf.LF_course_enrollment_common_student') }}">
                                    <span class="enr-cell-title">{{ $item['student_name'] }}</span>
                                    @if ($studentEmails[$item['student_id']] ?? null)
                                        <span class="enr-cell-sub">{{ $studentEmails[$item['student_id']] }}</span>
                                    @endif
                                </td>
                                <td data-label="{{ __('lf.LF_bulk_enrollment_design_result') }}">
                                    @if (($item['status'] ?? null) === 'reenrolled')
                                        <span class="enr-badge enr-badge--warn"><x-backend-icon name="rotate-ccw" class="ui-icon" />{{ ($item['previous_enrollment_id'] ?? null) ? __('lf.LF_bulk_enrollment_design_reenroll_from', ['id' => '#'.$item['previous_enrollment_id']]) : __('lf.LF_bulk_enrollment_design_reenroll') }}</span>
                                    @else
                                        <span class="enr-new"><x-backend-icon name="plus" class="ui-icon" />{{ __('lf.LF_bulk_enrollment_design_new') }}</span>
                                    @endif
                                </td>
                                <td class="enr-col-id" data-label="{{ __('lf.LF_bulk_enrollment_enrollment_id') }}"><a class="enr-link" href="{{ route($routePrefix.'.show', $item['enrollment_id']) }}">#{{ $item['enrollment_id'] }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table></div>
            @if ($itemsPaginator->hasPages())
                <x-ui-list-foot :paginator="$itemsPaginator"
                    :label="__('lf.LF_bulk_enrollment_page')"
                    :page-size-label="__('lf.LF_course_template_index_page_size')"
                    :page-size-option="__('lf.LF_course_template_index_page_size_option', ['count' => $itemsPaginator->perPage()])" />
            @endif
            @if (filled($completedConfiguration['notes'] ?? null))
                <p class="ui-caption enr-done-notes">{{ __('lf.LF_course_enrollment_common_notes') }}: {{ $completedConfiguration['notes'] }}</p>
            @endif
        </section>

        <div class="enr-result-actions">
            <a class="ui-button cursor-interaction" href="{{ route($routePrefix.'.create') }}"><x-backend-icon name="plus" class="ui-icon" />{{ __('lf.LF_bulk_enrollment_create_another') }}</a>
            <a class="ui-button ui-button--primary cursor-interaction" href="{{ route($routePrefix.'.index') }}">{{ __('lf.LF_course_enrollment_common_back_to_enrollments') }}</a>
        </div>
    @else
    @if ($errors->any())
        <div class="enr-alert enr-alert--danger" role="alert">
            <x-backend-icon name="circle-alert" class="ui-icon" />
            <div><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        </div>
    @endif

    <div x-data="bulkEnrollmentWizard({
             studentUrl: @js(route($routePrefix.'.students.search')),
             productUrl: @js(route($routePrefix.'.products.search')),
             preflightUrl: @js(route($routePrefix.'.bulk-preflight')),
             invalidateUrl: @js(route($routePrefix.'.bulk-invalidate')),
             csrf: @js(csrf_token()),
         })">
        <template x-teleport="#enr-stepper-slot">
            <nav class="enr-stepper" aria-label="{{ __('lf.LF_bulk_enrollment_progress') }}"><ol>
                @foreach ($enrollmentSteps as $number => $label)
                    <li class="enr-step" :class="{ 'is-done': step > {{ $number }} }" :aria-current="step === {{ $number }} ? 'step' : null">
                        <span class="enr-step__dot">
                            <span x-show="step <= {{ $number }}">{{ $number }}</span>
                            <span x-show="step > {{ $number }}" x-cloak><x-backend-icon name="check" class="ui-icon" /></span>
                        </span>
                        <span class="enr-step__label">{{ $label }}</span>
                    </li>
                @endforeach
            </ol></nav>
        </template>

        <form id="bulk-enrollment-form" x-ref="form" method="POST" novalidate
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

            <div class="enr-alert enr-alert--danger" x-show="errorMessage" role="alert" x-cloak>
                <x-backend-icon name="circle-alert" class="ui-icon" />
                <div><span x-text="errorMessage"></span></div>
            </div>

            {{-- Step 1: students and products --}}
            <div x-show="step === 1">
                <section class="ui-section enr-setup-card" aria-label="{{ __('lf.LF_bulk_enrollment_information_section') }}">
                    <div class="enr-setup">
                        <label class="ui-field__label" for="bulk-enrolled-at"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</label>
                        <input id="bulk-enrolled-at" name="configuration[enrolled_at]" type="datetime-local" x-model="configuration.enrolled_at"
                               aria-describedby="bulk-enrolled-at-help" x-on:change="enrollmentDateChanged" required>
                        <span id="bulk-enrolled-at-help" class="ui-caption"><x-backend-icon name="info" class="ui-icon enr-inline-icon" /> {{ __('lf.LF_bulk_enrollment_design_date_hint') }}</span>
                    </div>
                </section>

                <div class="enr-alert enr-alert--danger" x-ref="selectionError" x-show="invalidProducts.length > 0" role="alert" tabindex="-1" x-cloak>
                    <x-backend-icon name="circle-alert" class="ui-icon" />
                    <div>
                        <strong x-text="invalidTitle"></strong>
                        <span x-text="invalidText"></span>
                        <ul class="enr-alert__list" x-show="invalidProducts.length > 1">
                            <template x-for="item in invalidProducts" :key="`invalid-${item.id}`">
                                <li><b x-text="item.title"></b> — <span x-text="eligibilityReason(item, true)"></span></li>
                            </template>
                        </ul>
                        <div class="enr-alert__actions">
                            <button type="button" class="ui-button ui-button--sm cursor-interaction" x-show="invalidProducts.some(item => windowState(item).blocks)" x-on:click="focusEnrollmentDate"><x-backend-icon name="calendar" class="ui-icon" />{{ __('lf.LF_bulk_enrollment_design_change_date') }}</button>
                            <button type="button" class="ui-button ui-button--sm cursor-interaction" x-on:click="removeInvalidProducts" x-text="invalidRemoveLabel"></button>
                        </div>
                    </div>
                </div>

                <div class="enr-panels">
                    <section class="enr-panel" aria-labelledby="enr-students-title" :aria-busy="studentLoading">
                        <div class="enr-panel__head">
                            <h3 class="enr-panel__title" id="enr-students-title"><span class="enr-panel__icon"><x-backend-icon name="users" class="ui-icon" /></span>{{ __('lf.LF_bulk_enrollment_students_panel') }}</h3>
                            <span class="ui-caption" aria-live="polite" x-text="@js(__('lf.LF_bulk_enrollment_design_selected')).replace(':count', selectedStudents.length)"></span>
                        </div>
                        <div class="enr-panel__tools">
                            <div class="ui-search">
                                <input id="bulk-student-search" type="search" placeholder="{{ __('lf.LF_bulk_enrollment_design_search_students') }}" aria-label="{{ __('lf.LF_bulk_enrollment_design_search_students') }}"
                                       x-model="studentQuery" x-on:input.debounce.350ms="loadStudents(1)" x-on:keydown.enter.prevent>
                                <span class="ui-button" aria-hidden="true"><x-backend-icon name="search" class="ui-icon" /></span>
                            </div>
                            <div class="enr-panel__bar">
                                <label class="ui-check"><input type="checkbox" x-on:change="toggleVisibleStudents($event.target.checked)"
                                       :checked="studentResults.length > 0 && studentResults.every(item => hasStudent(item.id))">{{ __('lf.LF_bulk_enrollment_design_select_all_page') }}</label>
                                <span class="ui-caption" x-text="@js(__('lf.LF_bulk_enrollment_design_results')).replace(':count', studentTotal)"></span>
                            </div>
                            <div class="enr-selected" x-show="selectedStudents.length > 0" x-cloak aria-label="{{ __('lf.LF_bulk_enrollment_design_formula_students') }}">
                                <template x-for="student in selectedStudents" :key="`chip-${student.id}`">
                                    <span class="enr-chip"><span x-text="student.name"></span><button type="button" class="cursor-interaction" x-on:click="toggleStudent(student, false)" :aria-label="@js(__('lf.LF_bulk_enrollment_deselect')).concat(': ', student.name)"><x-backend-icon name="x" class="ui-icon" /></button></span>
                                </template>
                            </div>
                        </div>
                        <ul class="enr-list">
                            <template x-for="item in studentResults" :key="item.id">
                                <li><label class="enr-row">
                                    <input type="checkbox" :checked="hasStudent(item.id)" x-on:change="toggleStudent(item, $event.target.checked); $event.target.checked = hasStudent(item.id)" :aria-label="item.name">
                                    <span class="enr-avatar" aria-hidden="true" x-text="initials(item.name)"></span>
                                    <span class="enr-row__body"><span class="enr-row__name" x-text="item.name"></span><span class="enr-row__sub" x-text="item.email"></span></span>
                                </label></li>
                            </template>
                            <li class="enr-empty" x-show="!studentLoading && studentResults.length === 0" x-cloak>{{ __('lf.LF_bulk_enrollment_design_no_students') }}</li>
                        </ul>
                        <div class="enr-panel__foot">
                            <nav class="mk-pages" aria-label="{{ __('lf.LF_bulk_enrollment_students_pagination') }}">
                                <button type="button" class="mk-page cursor-interaction" x-on:click="loadStudents(studentPage - 1)" :disabled="studentPage <= 1" aria-label="{{ __('lf.LF_bulk_enrollment_previous') }}">‹</button>
                                <span x-text="@js(__('lf.LF_bulk_enrollment_design_page')).replace(':page', studentPage).replace(':last', studentLastPage)"></span>
                                <button type="button" class="mk-page cursor-interaction" x-on:click="loadStudents(studentPage + 1)" :disabled="studentPage >= studentLastPage" aria-label="{{ __('lf.LF_bulk_enrollment_next') }}">›</button>
                            </nav>
                            <span>{{ __('lf.LF_bulk_enrollment_design_per_page', ['count' => 10]) }}</span>
                        </div>
                    </section>

                    <section class="enr-panel" :class="{ 'is-locked': selectedStudents.length === 0 }" aria-labelledby="enr-products-title" :aria-busy="productLoading">
                        <div class="enr-panel__head">
                            <h3 class="enr-panel__title" id="enr-products-title"><span class="enr-panel__icon"><x-backend-icon name="package" class="ui-icon" /></span>{{ __('lf.LF_bulk_enrollment_products_panel') }}</h3>
                            <span class="ui-caption" aria-live="polite">
                                <span x-text="productsCountLabel"></span>
                                <span class="enr-need-fix" x-show="invalidProducts.length > 0" x-cloak x-text="`· ${@js(__('lf.LF_bulk_enrollment_design_need_fix')).replace(':count', invalidProducts.length)}`"></span>
                            </span>
                        </div>
                        <p class="ui-sr-only" aria-live="polite" x-text="productEligibilityAnnouncement"></p>
                        <div class="enr-panel__tools">
                            <div class="ui-search">
                                <input id="bulk-product-search" type="search" placeholder="{{ __('lf.LF_bulk_enrollment_design_search_products') }}" aria-label="{{ __('lf.LF_bulk_enrollment_design_search_products') }}"
                                       x-model="productQuery" x-on:input.debounce.350ms="loadProducts(1)" x-on:keydown.enter.prevent>
                                <span class="ui-button" aria-hidden="true"><x-backend-icon name="search" class="ui-icon" /></span>
                            </div>
                            <div class="enr-panel__bar">
                                <label class="ui-check"><input type="checkbox" x-on:change="toggleVisibleProducts($event.target.checked)"
                                       :checked="eligibleVisibleProducts.length > 0 && eligibleVisibleProducts.every(item => hasProduct(item.id))"
                                       :disabled="!productEligibilityReady || eligibleVisibleProducts.length === 0">{{ __('lf.LF_bulk_enrollment_design_select_all_eligible') }}</label>
                                <span class="ui-caption" title="{{ __('lf.LF_bulk_enrollment_design_version_locked_help') }}"><x-backend-icon name="lock" class="ui-icon enr-inline-icon" /> {{ __('lf.LF_bulk_enrollment_design_version_locked') }}</span>
                            </div>
                        </div>
                        <div class="enr-checking enr-locked-note" role="note" x-show="selectedStudents.length === 0"><x-backend-icon name="lock" class="ui-icon" />{{ __('lf.LF_bulk_enrollment_design_locked_note') }}</div>
                        <div class="enr-checking" role="status" x-show="selectedStudents.length > 0 && productLoading" x-cloak><span class="enr-spinner" aria-hidden="true"></span>{{ __('lf.LF_bulk_enrollment_design_checking') }}</div>
                        <div class="enr-alert enr-alert--danger enr-alert--inline" role="alert" x-show="productEligibilityError" x-cloak>
                            <x-backend-icon name="circle-alert" class="ui-icon" />
                            <div><span>{{ __('lf.LF_bulk_enrollment_eligibility_error') }}</span> <button type="button" class="enr-link" x-on:click="loadProducts(productPage)">{{ __('lf.LF_bulk_enrollment_retry') }}</button></div>
                        </div>
                        <ul class="enr-list">
                            <li class="enr-group-label enr-group-label--danger" x-show="invalidProducts.length > 0" x-cloak x-text="@js(__('lf.LF_bulk_enrollment_design_group_invalid')).replace(':count', invalidProducts.length)"></li>
                            <template x-for="item in invalidProducts" :key="`selected-invalid-${item.id}`">
                                @include('course-enrollments.partials.product-row')
                            </template>
                            <li class="enr-group-label" x-show="invalidProducts.length > 0 && eligibleVisibleProducts.length > 0" x-cloak x-text="@js(__('lf.LF_bulk_enrollment_design_group_eligible')).replace(':count', eligibleProductCount)"></li>
                            <li class="enr-empty" x-show="productEligibilityReady && eligibleProductCount === 0" x-cloak>
                                <strong>{{ __('lf.LF_bulk_enrollment_design_no_eligible') }}</strong>{{ __('lf.LF_bulk_enrollment_design_no_eligible_help') }}
                            </li>
                            <li class="enr-empty" x-show="!productLoading && selectedStudents.length === 0 && productResults.length === 0" x-cloak>{{ __('lf.LF_bulk_enrollment_design_no_products') }}</li>
                            <template x-for="item in productResults" :key="`product-row-${item.id}`">
                                @include('course-enrollments.partials.product-row')
                            </template>
                            <li x-show="productEligibilityReady && otherIneligibleCount > 0" x-cloak>
                                <button type="button" class="enr-group-toggle cursor-interaction" x-on:click="ineligibleProductsVisible = !ineligibleProductsVisible" :aria-expanded="ineligibleProductsVisible.toString()">
                                    <span x-text="ineligibleProductsVisible ? @js(__('lf.LF_bulk_enrollment_design_hide_ineligible')) : @js(__('lf.LF_bulk_enrollment_design_show_ineligible')).replace(':count', otherIneligibleCount)"></span>
                                    <x-backend-icon name="chevron-down" class="ui-icon" />
                                </button>
                            </li>
                            <template x-if="productEligibilityReady && ineligibleProductsVisible">
                                <li class="enr-sublist"><ul>
                                    <template x-for="item in otherIneligibleProducts" :key="`ineligible-${item.id}`">
                                        @include('course-enrollments.partials.product-row')
                                    </template>
                                </ul></li>
                            </template>
                            <li class="enr-group-pager" x-show="productEligibilityReady && ineligibleProductsVisible && ineligibleProductLastPage > 1" x-cloak>
                                <nav class="mk-pages" aria-label="{{ __('lf.LF_bulk_enrollment_products_pagination') }}">
                                    <button type="button" class="mk-page cursor-interaction" x-on:click="loadProducts(productPage, ineligibleProductPage - 1)" :disabled="ineligibleProductPage <= 1" aria-label="{{ __('lf.LF_bulk_enrollment_previous') }}">‹</button>
                                    <span x-text="@js(__('lf.LF_bulk_enrollment_design_page')).replace(':page', ineligibleProductPage).replace(':last', ineligibleProductLastPage)"></span>
                                    <button type="button" class="mk-page cursor-interaction" x-on:click="loadProducts(productPage, ineligibleProductPage + 1)" :disabled="ineligibleProductPage >= ineligibleProductLastPage" aria-label="{{ __('lf.LF_bulk_enrollment_next') }}">›</button>
                                </nav>
                            </li>
                        </ul>
                        <div class="enr-panel__foot">
                            <nav class="mk-pages" aria-label="{{ __('lf.LF_bulk_enrollment_products_pagination') }}">
                                <button type="button" class="mk-page cursor-interaction" x-on:click="loadProducts(productPage - 1)" :disabled="productPage <= 1" aria-label="{{ __('lf.LF_bulk_enrollment_previous') }}">‹</button>
                                <span x-text="@js(__('lf.LF_bulk_enrollment_design_page')).replace(':page', productPage).replace(':last', productLastPage)"></span>
                                <button type="button" class="mk-page cursor-interaction" x-on:click="loadProducts(productPage + 1)" :disabled="productPage >= productLastPage" aria-label="{{ __('lf.LF_bulk_enrollment_next') }}">›</button>
                            </nav>
                            <span x-text="productsFootLabel"></span>
                        </div>
                    </section>
                </div>
            </div>

            {{-- Step 2: review and confirm --}}
            <div x-show="step === 2" x-cloak>
                <section class="ui-section enr-review-head" aria-label="{{ __('lf.LF_bulk_enrollment_information_section') }}">
                    <dl class="enr-facts">
                        <div><dt>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</dt><dd x-text="formatDateTime(configuration.enrolled_at)"></dd></div>
                        <div><dt>{{ __('lf.LF_bulk_enrollment_status_after_creation') }}</dt><dd><span class="enr-badge enr-badge--success">{{ __('lf.LF_course_enrollment_common_active') }}</span></dd></div>
                        <div><dt>{{ __('lf.LF_course_enrollment_common_source') }}</dt><dd>{{ __('lf.LF_course_enrollment_common_source_admin') }}</dd></div>
                    </dl>
                    <button type="button" class="ui-button ui-button--sm cursor-interaction" x-on:click="backToSelection"><x-backend-icon name="square-pen" class="ui-icon" />{{ __('lf.LF_bulk_enrollment_design_change_selection') }}</button>
                </section>

                <section class="mk-section enr-pairs" aria-labelledby="enr-pairs-title">
                    <div class="mk-section-head">
                        <div>
                            <h3 id="enr-pairs-title">{{ __('lf.LF_bulk_enrollment_design_pairs_title') }}</h3>
                            <p>
                                <span x-text="@js(__('lf.LF_bulk_enrollment_design_summary_new')).replace(':count', pairs.length - reenrollmentPairs.length)"></span>
                                <span class="enr-summary-warn" x-show="reenrollmentPairs.length > 0" x-cloak x-text="`· ${reenrollmentSummary}`"></span>
                            </p>
                        </div>
                        <button type="button" class="ui-button ui-button--outline ui-button--sm cursor-interaction" x-show="reenrollmentPairs.length > 1 && pendingPairs.length > 0" x-cloak x-on:click="$refs.confirmAll.showModal()"><x-backend-icon name="check-check" class="ui-icon" />{{ __('lf.LF_bulk_enrollment_design_confirm_all') }}</button>
                    </div>
                    <div class="mk-tabs" role="tablist" aria-label="{{ __('lf.LF_bulk_enrollment_design_pairs_title') }}" x-show="reenrollmentPairs.length > 0" x-cloak>
                        <button type="button" role="tab" class="mk-tab cursor-interaction" :aria-selected="(pairFilter === 'all').toString()" x-on:click="setPairFilter('all')">{{ __('lf.LF_bulk_enrollment_design_tab_all') }}<span class="enr-count" x-text="pairs.length"></span></button>
                        <button type="button" role="tab" class="mk-tab cursor-interaction" :aria-selected="(pairFilter === 'pending').toString()" x-on:click="setPairFilter('pending')">{{ __('lf.LF_bulk_enrollment_design_tab_pending') }}<span class="enr-count enr-count--warn" x-text="pendingPairs.length"></span></button>
                    </div>
                    <div class="mk-table-scroll"><table class="enr-table enr-table--grouped" aria-labelledby="enr-pairs-title">
                        <thead><tr>
                            <th scope="col" class="enr-col-no">{{ __('lf.table_no') }}</th>
                            <th scope="col">{{ __('lf.LF_course_enrollment_common_student') }}</th>
                            <th scope="col" class="enr-col-result">{{ __('lf.LF_bulk_enrollment_design_expected') }}</th>
                        </tr></thead>
                        <template x-for="group in reviewGroups" :key="`group-${group.productId}`">
                            <tbody>
                                <tr class="enr-prod-row"><td colspan="3"><div class="enr-prod">
                                    <span class="enr-prod__name" x-text="group.title"></span>
                                    <span class="enr-prod__code" x-text="group.meta"></span>
                                    <span class="enr-prod__time">
                                        <x-backend-icon name="clock" class="ui-icon" />
                                        <template x-for="line in group.timeLines" :key="line"><span x-text="line"></span></template>
                                    </span>
                                    <span class="enr-prod__count" x-text="@js(__('lf.LF_bulk_enrollment_design_group_students')).replace(':count', group.count)"></span>
                                </div></td></tr>
                                <template x-for="row in group.rows" :key="`${row.student_id}:${row.product_id}`">
                                    <tr :class="{ 'is-attention': row.status === 'reenrollment_eligible' && !isConfirmed(row) }">
                                        <td class="enr-col-no" x-text="row.number"></td>
                                        <td data-label="{{ __('lf.LF_course_enrollment_common_student') }}">
                                            <span class="enr-cell-title" x-text="row.student_name"></span>
                                            <span class="enr-cell-sub" x-text="studentEmail(row.student_id)"></span>
                                        </td>
                                        <td class="enr-col-result" data-label="{{ __('lf.LF_bulk_enrollment_design_expected') }}">
                                            <span class="enr-new" x-show="row.status === 'creatable'"><x-backend-icon name="plus" class="ui-icon" />{{ __('lf.LF_bulk_enrollment_design_new') }}</span>
                                            <div class="enr-reenroll" x-show="row.status === 'reenrollment_eligible'">
                                                <span class="enr-badge enr-badge--warn enr-badge--start"><x-backend-icon name="rotate-ccw" class="ui-icon" />{{ __('lf.LF_bulk_enrollment_design_reenroll') }}</span>
                                                <label class="ui-check"><input type="checkbox" x-model="confirmedPairKeys" :value="`${row.student_id}:${row.product_id}`">{{ __('lf.LF_bulk_enrollment_confirm_reenroll') }}</label>
                                                <p x-text="@js(__('lf.LF_bulk_enrollment_design_reenroll_help')).replace(':id', `#${row.previous_enrollment_id}`)"></p>
                                            </div>
                                            <span class="enr-reason" x-show="row.reason && row.status !== 'reenrollment_eligible'" x-text="row.reason"></span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </template>
                        <tbody x-show="filteredPairs.length === 0" x-cloak><tr><td colspan="3" class="enr-empty">{{ __('lf.LF_bulk_enrollment_design_all_confirmed') }}</td></tr></tbody>
                    </table></div>
                    <div class="enr-pager" x-show="filteredPairs.length > confirmationPerPage" x-cloak>
                        <span x-text="`${(confirmationPage - 1) * confirmationPerPage + 1}–${Math.min(confirmationPage * confirmationPerPage, filteredPairs.length)} / ${filteredPairs.length}`"></span>
                        <nav class="mk-pages" aria-label="{{ __('lf.LF_bulk_enrollment_page') }}">
                            <button type="button" class="mk-page cursor-interaction" x-on:click="goToConfirmationPage(confirmationPage - 1)" :disabled="confirmationPage <= 1" aria-label="{{ __('lf.LF_bulk_enrollment_previous') }}">‹</button>
                            <span class="mk-page" aria-current="page" x-text="confirmationPage"></span>
                            <button type="button" class="mk-page cursor-interaction" x-on:click="goToConfirmationPage(confirmationPage + 1)" :disabled="confirmationPage >= confirmationLastPage" aria-label="{{ __('lf.LF_bulk_enrollment_next') }}">›</button>
                        </nav>
                    </div>
                </section>

                <section class="ui-section enr-notes" aria-label="{{ __('lf.LF_course_enrollment_internal_notes') }}">
                    <button type="button" class="enr-link" x-show="!notesOpen" x-on:click="notesOpen = true; $nextTick(() => $refs.notes.focus())" aria-controls="enr-notes-field" :aria-expanded="notesOpen.toString()"><x-backend-icon name="plus" class="ui-icon enr-inline-icon" /> {{ __('lf.LF_bulk_enrollment_design_add_notes') }} <span class="ui-caption">{{ __('lf.LF_bulk_enrollment_design_notes_hint') }}</span></button>
                    <div class="ui-field" id="enr-notes-field" x-show="notesOpen" x-cloak>
                        <label class="ui-field__label" for="bulk-notes">{{ __('lf.LF_course_enrollment_internal_notes') }}</label>
                        <textarea id="bulk-notes" x-ref="notes" name="configuration[notes]" rows="3" placeholder="{{ __('lf.LF_bulk_enrollment_design_notes_placeholder') }}" x-model="configuration.notes"></textarea>
                    </div>
                </section>
            </div>

            <div class="enr-actionbar">
                <div class="enr-formula" aria-live="polite">
                    <span><b x-text="selectedStudents.length"></b> {{ __('lf.LF_bulk_enrollment_design_formula_students') }}</span><span class="enr-op">×</span>
                    <span><b x-text="selectedProducts.length"></b> {{ __('lf.LF_bulk_enrollment_design_formula_products') }}</span><span class="enr-op">=</span>
                    <span class="enr-total"><b x-text="pairCount"></b> {{ __('lf.LF_bulk_enrollment_design_formula_pairs') }}</span>
                </div>
                <div class="enr-limit" x-show="step === 1" :class="{ 'is-near': pairCount >= 80 && pairCount <= 100, 'is-over': pairCount > 100 }">
                    <span class="enr-meter" aria-hidden="true"><span :style="`width: ${Math.min(100, pairCount)}%`"></span></span>
                    <span x-text="@js(__('lf.LF_bulk_enrollment_design_limit')).replace(':count', pairCount)"></span>
                </div>
                <span class="enr-actionbar__msg" :class="{ 'is-error': actionMessageIsError }" role="status" x-text="actionMessage"></span>
                <div class="enr-actionbar__buttons">
                    <a href="{{ route($routePrefix.'.index') }}" class="ui-button cursor-interaction">{{ __('lf.LF_common_button_cancel') }}</a>
                    <button x-show="step === 2" type="button" class="ui-button cursor-interaction" x-on:click="backToSelection" x-cloak><x-backend-icon name="arrow-left" class="ui-icon" />{{ __('lf.LF_bulk_enrollment_back') }}</button>
                    <button x-show="step === 1" type="button" class="ui-button ui-button--primary cursor-interaction" x-on:click="continueToSetup" :disabled="!canContinue">{{ __('lf.LF_bulk_enrollment_continue') }}<x-backend-icon name="arrow-right" class="ui-icon" /></button>
                    <button x-show="step === 2" type="submit" class="ui-button ui-button--primary cursor-interaction" :disabled="loading || submitting || pendingPairs.length > 0" x-cloak>
                        <x-backend-icon name="check" class="ui-icon" />
                        <span x-show="!submitting" x-text="@js(__('lf.LF_bulk_enrollment_design_create_count')).replace(':count', pairs.length)"></span>
                        <span x-show="submitting">{{ __('lf.LF_course_enrollment_update_saving') }}</span>
                    </button>
                </div>
            </div>
        </form>

        <dialog x-ref="confirmAll" class="ui-modal" data-columns="1" aria-labelledby="enr-confirm-title">
            <form class="ui-modal__form" x-on:submit.prevent="confirmAllReenrollments">
                <header class="ui-modal__header">
                    <div><h3 id="enr-confirm-title">{{ __('lf.LF_bulk_enrollment_design_confirm_all_title') }}</h3></div>
                    <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.confirmAll.close()" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
                </header>
                <div class="ui-modal__body"><p class="enr-modal-lead" x-text="@js(__('lf.LF_bulk_enrollment_design_confirm_all_text')).replace(':count', pendingPairs.length)"></p></div>
                <footer class="ui-modal__footer">
                    <button type="button" class="ui-button cursor-interaction" x-on:click="$refs.confirmAll.close()">{{ __('lf.LF_common_button_cancel') }}</button>
                    <button type="submit" class="ui-button ui-button--primary cursor-interaction">{{ __('lf.LF_bulk_enrollment_design_confirm_all_action') }}</button>
                </footer>
            </form>
        </dialog>
    </div>

    <script>
        function bulkEnrollmentWizard(config) {
            return {
                step: 1, selectedStudents: [], selectedProducts: [], studentResults: [], productResults: [], ineligibleProductResults: [],
                studentQuery: '', productQuery: '', studentPage: 1, productPage: 1, studentLastPage: 1, productLastPage: 1, ineligibleProductPage: 1, ineligibleProductLastPage: 1, studentTotal: 0,
                studentLoading: false, productLoading: false, loading: false, submitting: false, pairs: [], confirmationPage: 1, confirmationPerPage: 10, pairFilter: 'all', notesOpen: false,
                confirmedPairKeys: [], submissionToken: '', errorMessage: '', productEligibilityError: false,
                productEligibilityReady: false, productRequestVersion: 0, productEligibilityTimer: null, productAbortController: null,
                eligibleProductCount: 0, ineligibleProductCount: 0, ineligibleProductsVisible: false,
                configuration: { enrolled_at: @js(now()->format('Y-m-d\TH:i')), notes: null },
                init() { this.loadStudents(1); this.loadProducts(1) },
                get pairCount() { return this.selectedStudents.length * this.selectedProducts.length },
                get eligibleVisibleProducts() { return this.productResults.filter(item => item.eligibility === 'eligible') },
                get invalidProducts() { return this.productEligibilityReady ? this.selectedProducts.filter(item => item.eligibility === 'ineligible') : [] },
                get otherIneligibleProducts() { return this.ineligibleProductResults.filter(item => !this.hasProduct(item.id)) },
                get otherIneligibleCount() { return Math.max(0, this.ineligibleProductCount - this.invalidProducts.length) },
                get invalidTitle() { return this.invalidProducts.length === 1 ? @js(__('lf.LF_bulk_enrollment_design_invalid_one')).replace(':name', this.invalidProducts[0].title) : @js(__('lf.LF_bulk_enrollment_design_invalid_many')).replace(':count', this.invalidProducts.length) },
                get invalidText() { return this.invalidProducts.length === 1 ? @js(__('lf.LF_bulk_enrollment_design_invalid_one_text')).replace(':reason', this.eligibilityReason(this.invalidProducts[0], true)) : @js(__('lf.LF_bulk_enrollment_design_invalid_many_text')) },
                get invalidRemoveLabel() { return this.invalidProducts.length === 1 ? @js(__('lf.LF_bulk_enrollment_design_remove_one')) : @js(__('lf.LF_bulk_enrollment_design_remove_many')).replace(':count', this.invalidProducts.length) },
                get productsCountLabel() { return this.selectedStudents.length === 0 ? @js(__('lf.LF_bulk_enrollment_design_unchecked')) : @js(__('lf.LF_bulk_enrollment_design_selected_eligible')).replace(':selected', this.selectedProducts.filter(item => item.eligibility === 'eligible').length).replace(':eligible', this.eligibleProductCount) },
                get productsFootLabel() { return this.productEligibilityReady ? @js(__('lf.LF_bulk_enrollment_design_products_summary')).replace(':eligible', this.eligibleProductCount).replace(':ineligible', this.ineligibleProductCount) : @js(__('lf.LF_bulk_enrollment_design_products_total')).replace(':count', this.productResults.length) },
                get productEligibilityAnnouncement() { if (this.productLoading && this.selectedStudents.length) return @js(__('lf.LF_bulk_enrollment_eligibility_loading')); if (!this.productEligibilityReady) return ''; return @js(__('lf.LF_bulk_enrollment_eligibility_result')).replace(':eligible', this.eligibleProductCount).replace(':total', this.eligibleProductCount + this.ineligibleProductCount) },
                get canContinue() { return !this.loading && Boolean(this.configuration.enrolled_at) && this.productEligibilityReady && this.invalidProducts.length === 0 && this.pairCount >= 1 && this.pairCount <= 100 },
                get actionMessage() { if (this.step === 2) return this.pendingPairs.length ? @js(__('lf.LF_bulk_enrollment_design_msg_pending')).replace(':count', this.pendingPairs.length) : @js(__('lf.LF_bulk_enrollment_design_msg_ready')).replace(':date', this.formatDateTime(this.configuration.enrolled_at)); if (!this.configuration.enrolled_at) return @js(__('lf.LF_bulk_enrollment_design_msg_date')); if (this.selectedStudents.length === 0) return @js(__('lf.LF_bulk_enrollment_design_msg_student')); if (this.productLoading) return @js(__('lf.LF_bulk_enrollment_design_msg_checking')); if (this.selectedProducts.length === 0) return @js(__('lf.LF_bulk_enrollment_design_msg_product')); if (this.invalidProducts.length) return @js(__('lf.LF_bulk_enrollment_design_msg_invalid')); return this.pairCount > 100 ? @js(__('lf.LF_bulk_enrollment_design_msg_limit')) : '' },
                get actionMessageIsError() { return this.step === 2 ? this.pendingPairs.length > 0 : (!this.configuration.enrolled_at || this.invalidProducts.length > 0 || this.pairCount > 100) },
                get reenrollmentPairs() { return this.pairs.filter(pair => pair.status === 'reenrollment_eligible') },
                get pendingPairs() { return this.reenrollmentPairs.filter(pair => !this.isConfirmed(pair)) },
                get reenrollmentSummary() { return this.pendingPairs.length ? @js(__('lf.LF_bulk_enrollment_design_summary_re_pending')).replace(':count', this.reenrollmentPairs.length).replace(':pending', this.pendingPairs.length) : @js(__('lf.LF_bulk_enrollment_design_summary_re_done')).replace(':count', this.reenrollmentPairs.length) },
                get filteredPairs() { return this.pairFilter === 'pending' ? this.pendingPairs : this.pairs },
                get confirmationLastPage() { return Math.max(1, Math.ceil(this.filteredPairs.length / this.confirmationPerPage)) },
                get reviewGroups() { const offset = (Math.min(this.confirmationPage, this.confirmationLastPage) - 1) * this.confirmationPerPage; const groups = []; this.filteredPairs.slice(offset, offset + this.confirmationPerPage).forEach((pair, index) => { let group = groups.find(item => item.productId === pair.product_id); if (!group) { const product = this.selectedProducts.find(item => String(item.id) === String(pair.product_id)) || {}; group = { productId: pair.product_id, title: pair.product_title, meta: this.productCode({ code: product.code, version: { number: pair.version_number, code: pair.version_code } }), timeLines: this.timeLines(pair.time_windows), count: this.pairs.filter(item => item.product_id === pair.product_id).length, rows: [] }; groups.push(group) } group.rows.push({ ...pair, number: offset + index + 1 }) }); return groups },
                get confirmedPairs() { return this.reenrollmentPairs.filter(pair => this.isConfirmed(pair)).map(pair => ({ student_id: pair.student_id, product_id: pair.product_id, previous_enrollment_id: pair.previous_enrollment_id })) },
                isConfirmed(pair) { return this.confirmedPairKeys.includes(`${pair.student_id}:${pair.product_id}`) },
                hasStudent(id) { return this.selectedStudents.some(item => String(item.id) === String(id)) },
                hasProduct(id) { return this.selectedProducts.some(item => String(item.id) === String(id)) },
                studentEmail(id) { return this.selectedStudents.find(item => String(item.id) === String(id))?.email || '' },
                initials(name) { return String(name || '').trim().split(/\s+/).slice(-2).map(word => word[0] || '').join('').toUpperCase() },
                canProject(students, products) { return students * products <= 100 },
                isInvalidSelected(item) { return this.productEligibilityReady && this.hasProduct(item.id) && item.eligibility === 'ineligible' },
                productDisabled(item) { return !this.hasProduct(item.id) && (this.selectedStudents.length === 0 || !this.productEligibilityReady || item.eligibility !== 'eligible') },
                productCode(item) { const parts = []; if (item.code) parts.push(item.code); if (item.version?.number) parts.push(@js(__('lf.LF_bulk_enrollment_design_version')).replace(':number', item.version.number)); if (item.version?.code) parts.push(item.version.code); return parts.join(' · ') },
                productMeta(item) { const parts = [this.productCode(item)]; if (item.access_duration_days) parts.push(@js(__('lf.LF_bulk_enrollment_design_access_days')).replace(':days', item.access_duration_days)); if (item.review_duration_days) parts.push(@js(__('lf.LF_bulk_enrollment_design_review_days')).replace(':days', item.review_duration_days)); return parts.filter(Boolean).join(' · ') },
                // The window line compares with the chosen date, so a date change is reflected without a stale label.
                windowState(item) { const window = item.registration_window || {}; if (window.status === 'unlimited') return { label: @js(__('lf.LF_bulk_enrollment_design_no_limit')), note: @js(__('lf.LF_bulk_enrollment_design_any_date')), tone: 'ok', blocks: false }; if (!window.starts_at || !window.ends_at || window.status === 'invalid') return { label: @js(__('lf.LF_bulk_enrollment_design_window_invalid')), note: '', tone: 'bad', blocks: true, reason: @js(__('lf.LF_bulk_enrollment_design_reason_invalid')) }; const label = `${this.formatDateTime(window.starts_at)} → ${this.formatDateTime(window.ends_at)}`; const date = this.configuration.enrolled_at; if (!date) return { label, note: '', tone: '', blocks: false }; const chosen = this.formatDateTime(date); if (new Date(date) < new Date(window.starts_at)) return { label, note: @js(__('lf.LF_bulk_enrollment_design_window_not_open')).replace(':date', chosen), tone: 'wait', blocks: true, reason: @js(__('lf.LF_bulk_enrollment_design_reason_not_open')).replace(':date', chosen).replace(':start', this.formatDateTime(window.starts_at)) }; if (new Date(date) > new Date(window.ends_at)) return { label, note: @js(__('lf.LF_bulk_enrollment_design_window_ended')).replace(':date', chosen), tone: 'bad', blocks: true, reason: @js(__('lf.LF_bulk_enrollment_design_reason_ended')).replace(':end', this.formatDateTime(window.ends_at)).replace(':date', chosen) }; return { label, note: @js(__('lf.LF_bulk_enrollment_design_window_ok')), tone: 'ok', blocks: false } },
                timeLines(windows) { if (windows?.access_duration_days == null) return [@js(__('lf.LF_bulk_enrollment_design_no_access_limit'))]; const lines = [@js(__('lf.LF_bulk_enrollment_design_access_inline')).replace(':days', windows.access_duration_days).replace(':from', this.formatDay(windows.access_starts_at)).replace(':to', this.formatDay(windows.access_ends_at))]; lines.push(windows.review_duration_days > 0 ? @js(__('lf.LF_bulk_enrollment_design_review_inline')).replace(':days', windows.review_duration_days).replace(':to', this.formatDay(windows.review_ends_at)) : @js(__('lf.LF_bulk_enrollment_design_no_review'))); return lines },
                toggleStudent(item, checked) { if (checked && !this.canProject(this.selectedStudents.length + 1, Math.max(1, this.selectedProducts.length))) return this.limitError(); this.selectedStudents = checked ? [...this.selectedStudents.filter(row => row.id !== item.id), item] : this.selectedStudents.filter(row => row.id !== item.id); this.resetPreflight(); this.scheduleProductEligibility() },
                toggleProduct(item, checked) { if (checked && item.eligibility !== 'eligible') return; if (checked && !this.canProject(Math.max(1, this.selectedStudents.length), this.selectedProducts.length + 1)) return this.limitError(); this.selectedProducts = checked ? [...this.selectedProducts.filter(row => row.id !== item.id), item] : this.selectedProducts.filter(row => row.id !== item.id); this.resetPreflight() },
                toggleVisibleStudents(checked) { for (const item of this.studentResults) { if (checked && !this.hasStudent(item.id)) this.toggleStudent(item, true); if (!checked && this.hasStudent(item.id)) this.toggleStudent(item, false) } },
                toggleVisibleProducts(checked) { for (const item of this.eligibleVisibleProducts) { if (checked && !this.hasProduct(item.id)) this.toggleProduct(item, true); if (!checked && this.hasProduct(item.id)) this.toggleProduct(item, false) } },
                enrollmentDateChanged() { this.resetPreflight(); this.scheduleProductEligibility() },
                focusEnrollmentDate() { const field = document.getElementById('bulk-enrolled-at'); field?.scrollIntoView({ block: 'center', behavior: 'smooth' }); field?.focus() },
                formatDateTime(value) { if (!value) return '—'; const date = new Date(value); const pad = number => String(number).padStart(2, '0'); return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}` },
                formatDay(value) { return value ? this.formatDateTime(value).slice(0, 10) : '—' },
                setPairFilter(filter) { this.pairFilter = filter; this.confirmationPage = 1 },
                goToConfirmationPage(page) { if (page < 1 || page > this.confirmationLastPage) return; this.confirmationPage = page },
                limitError() { this.errorMessage = @js(__('lf.LF_bulk_enrollment_validation_pair_limit')) },
                resetPreflight() { this.errorMessage = ''; this.pairs = []; this.confirmedPairKeys = []; this.invalidateToken() },
                scheduleProductEligibility() { clearTimeout(this.productEligibilityTimer); this.productRequestVersion++; this.productAbortController?.abort(); this.productEligibilityReady = false; this.productEligibilityError = false; this.productLoading = this.selectedStudents.length > 0; this.productEligibilityTimer = setTimeout(() => this.loadProducts(1), 300) },
                removeInvalidProducts() { this.selectedProducts = this.selectedProducts.filter(item => item.eligibility !== 'ineligible'); this.resetPreflight() },
                eligibilityReason(item, withWindow = false) { const window = this.windowState(item); if (withWindow && window.blocks) return window.reason; if (!item.invalid_pairs?.length) return @js(__('lf.LF_bulk_enrollment_ineligible_generic')); const enrolled = @js(__('lf.LF_bulk_enrollment_already_enrolled')); const active = item.invalid_pairs.filter(pair => pair.reason === enrolled); const lines = []; if (active.length) lines.push(@js(__('lf.LF_bulk_enrollment_design_active_enrollment')).replace(':students', active.map(pair => pair.student_name).join(', ')).replace(':id', active.length === 1 && active[0].previous_enrollment_id ? ` (#${active[0].previous_enrollment_id})` : '')); const groups = item.invalid_pairs.filter(pair => pair.reason !== enrolled).reduce((result, pair) => { const reason = pair.reason || @js(__('lf.LF_bulk_enrollment_ineligible_generic')); if (!result[reason]) result[reason] = []; result[reason].push(pair.student_name); return result }, {}); Object.entries(groups).forEach(([reason, students]) => lines.push(students.length === this.selectedStudents.length ? reason : `${students.join(', ')}: ${reason}`)); return lines.join(' · ') },
                async loadStudents(page) { if (page < 1) return; this.studentLoading = true; try { const data = await this.fetchSearch(config.studentUrl, this.studentQuery, page); this.studentResults = data.data; this.studentPage = data.pagination.current_page; this.studentLastPage = data.pagination.last_page; this.studentTotal = data.pagination.total } finally { this.studentLoading = false } },
                async loadProducts(page, ineligiblePage = 1) { if (page < 1 || ineligiblePage < 1) return; const requestVersion = ++this.productRequestVersion; this.productAbortController?.abort(); this.productAbortController = new AbortController(); this.productLoading = true; this.productEligibilityReady = false; this.productEligibilityError = false; try { const params = new URLSearchParams({ q: this.productQuery, page: String(page), ineligible_page: String(ineligiblePage), enrolled_at: this.configuration.enrolled_at }); [...this.selectedStudents].map(item => Number(item.id)).sort((a, b) => a - b).forEach(id => params.append('student_ids[]', String(id))); [...this.selectedProducts].map(item => Number(item.id)).sort((a, b) => a - b).forEach(id => params.append('selected_product_ids[]', String(id))); const response = await fetch(`${config.productUrl}?${params}`, { headers: { Accept: 'application/json' }, signal: this.productAbortController.signal }); if (!response.ok) throw new Error(); const data = await response.json(); if (requestVersion !== this.productRequestVersion) return; this.productResults = data.data; this.ineligibleProductResults = data.ineligible?.data || []; this.productPage = data.pagination.current_page; this.productLastPage = data.pagination.last_page; this.ineligibleProductPage = data.ineligible?.pagination?.current_page || 1; this.ineligibleProductLastPage = data.ineligible?.pagination?.last_page || 1; this.eligibleProductCount = data.counts?.eligible || 0; this.ineligibleProductCount = data.counts?.ineligible || 0; const refreshedProducts = [...this.productResults, ...this.ineligibleProductResults]; this.selectedProducts = this.selectedProducts.map(item => ({ ...item, ...(refreshedProducts.find(product => String(product.id) === String(item.id)) || {}), ...(this.selectedStudents.length === 0 ? { eligibility: null, invalid_pairs: [] } : (data.selected_eligibility?.[String(item.id)] || { eligibility: 'ineligible', invalid_pair_count: this.selectedStudents.length, invalid_pairs: [] })) })); this.productEligibilityReady = this.selectedStudents.length > 0 } catch (error) { if (error.name === 'AbortError' || requestVersion !== this.productRequestVersion) return; this.productEligibilityError = true; this.productEligibilityReady = false } finally { if (requestVersion === this.productRequestVersion) this.productLoading = false } },
                async fetchSearch(url, query, page) { const response = await fetch(`${url}?q=${encodeURIComponent(query)}&page=${page}`, { headers: { Accept: 'application/json' } }); if (!response.ok) throw new Error(); return response.json() },
                payload(finalize) { return { student_ids: this.selectedStudents.map(item => item.id), product_ids: this.selectedProducts.map(item => item.id), reenrollment_confirmations: this.confirmedPairs, configuration: this.configuration, finalize } },
                async runPreflight(finalize) { if (!this.configuration.enrolled_at) { this.focusEnrollmentDate(); return null } this.loading = true; this.errorMessage = ''; try { const response = await fetch(config.preflightUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf }, body: JSON.stringify(this.payload(finalize)) }); const data = await response.json(); if (!response.ok) { this.errorMessage = Object.values(data.errors || {}).flat().join(' '); return null } return data } catch (error) { this.errorMessage = @js(__('lf.LF_bulk_enrollment_search_error')); return null } finally { this.loading = false } },
                sortedByProduct(pairs) { const order = this.selectedProducts.map(item => String(item.id)); return [...pairs].sort((a, b) => order.indexOf(String(a.product_id)) - order.indexOf(String(b.product_id))) },
                async continueToSetup() { if (this.invalidProducts.length) { await this.$nextTick(); this.$refs.selectionError.focus(); return } const result = await this.runPreflight(false); if (!result) return; this.pairs = this.sortedByProduct(result.pairs); this.confirmationPage = 1; this.pairFilter = 'all'; if (!result.can_continue) { this.errorMessage = @js(__('lf.LF_bulk_enrollment_preflight_blocked')); return } this.step = 2; window.scrollTo({ top: 0, behavior: 'smooth' }) },
                confirmAllReenrollments() { this.confirmedPairKeys = this.reenrollmentPairs.map(pair => `${pair.student_id}:${pair.product_id}`); this.$refs.confirmAll.close() },
                async commit() { if (this.step !== 2 || this.submitting || this.pendingPairs.length) return; this.submitting = true; const result = await this.runPreflight(true); if (!result || !result.valid || !result.submission_token) { if (result) { this.pairs = this.sortedByProduct(result.pairs); this.errorMessage = @js(__('lf.LF_bulk_enrollment_confirmation_required')) } this.submitting = false; return } this.submissionToken = result.submission_token; await this.$nextTick(); this.$refs.form.submit() },
                async backToSelection() { await this.invalidateToken(); this.step = 1; this.submitting = false; window.scrollTo({ top: 0, behavior: 'smooth' }) },
                async invalidateToken() { if (!this.submissionToken) return; const token = this.submissionToken; this.submissionToken = ''; await fetch(config.invalidateUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf }, body: JSON.stringify({ submission_token: token }) }) },
            }
        }
    </script>
    @endif
</div>
@endsection
