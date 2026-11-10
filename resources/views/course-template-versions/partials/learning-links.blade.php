{{-- ADR-0029: the competency links this Version published. An admin marks a wrong one with a reason; nothing is deleted or undone. --}}
@if ($learningLinks->isNotEmpty())
    <section class="mk-section lf-admin-ui lf-admin-ui--list ui-version-links" aria-labelledby="course-version-links-title"
             x-data="{ action: '', label: '', submitting: false,
                       ask(action, label) { this.action = action; this.label = label; this.$refs.dialog.showModal(); this.$nextTick(() => this.$refs.reason.focus()) } }">
        <div class="ui-framework__section-head">
            <h2 id="course-version-links-title">{{ __('lf.LF_course_template_version_links_title') }}</h2>
            <p class="ui-caption">{{ __('lf.LF_course_template_version_links_help') }}</p>
        </div>
        @error('invalidation_reason')
            <p class="ui-field__error" role="alert">{{ $message }}</p>
        @enderror
        <div class="mk-table-scroll" role="region" aria-labelledby="course-version-links-title" tabindex="0">
            <table aria-labelledby="course-version-links-title">
                <thead>
                <tr>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_template_version_links_source') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_template_version_links_competency') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_template_version_links_role') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_template_version_links_state') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($learningLinks as $link)
                    <tr data-course-version-link="{{ $link->id }}">
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_template_version_links_source') }}">
                            {{ $link->source_label }}
                            <span class="ui-caption">{{ __('lf.LF_course_template_version_links_source_'.($link->source_type === 'course_version_lesson' ? 'lesson' : 'activity')) }}</span>
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_template_version_links_competency') }}">
                            {{ $link->name_snapshot }} <span class="ui-caption ui-framework-code">{{ $link->code_snapshot }}</span>
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.LF_course_template_version_links_role') }}">
                            <span class="ui-status ui-status--draft">{{ __('lf.LF_ai_authoring_role_'.$link->mapping_role) }}</span>
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_template_version_links_state') }}">
                            @if ($link->invalidated_at)
                                <span class="ui-status ui-status--inactive">{{ __('lf.LF_course_template_version_links_invalid') }}</span>
                                <span class="ui-caption">{{ __('lf.LF_course_template_version_links_invalid_by', ['name' => $link->invalidated_by_name ?? '—', 'time' => \Illuminate\Support\Carbon::parse($link->invalidated_at)->timezone(config('app.timezone'))->format('d/m/Y H:i')]) }}</span>
                                <span class="ui-caption">{{ $link->invalidation_reason }}</span>
                            @else
                                <span class="ui-status ui-status--active">{{ __('lf.LF_course_template_version_links_valid') }}</span>
                            @endif
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                            @unless ($link->invalidated_at)
                                <button type="button" class="ux-row-delete cursor-interaction" aria-haspopup="dialog"
                                        data-course-version-link-invalidate="{{ $link->id }}"
                                        aria-label="{{ __('lf.LF_course_template_version_links_mark_named', ['name' => $link->name_snapshot, 'source' => $link->source_label]) }}"
                                        data-tooltip="{{ __('lf.LF_course_template_version_links_mark') }}"
                                        x-on:click="ask(@js(route('admin.course-templates.versions.links.invalidate', [$template->id, $version->id, $link->id])), @js($link->name_snapshot.' — '.$link->source_label))">
                                    <x-backend-icon name="circle" class="ui-icon" />
                                </button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <dialog x-ref="dialog" class="ui-modal" data-columns="1" aria-labelledby="course-version-link-dialog-title"
                x-on:cancel="if (submitting) $event.preventDefault()">
            <form method="POST" x-bind:action="action" class="ui-modal__form" x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
                @csrf
                <header class="ui-modal__header">
                    <div><h3 id="course-version-link-dialog-title">{{ __('lf.LF_course_template_version_links_mark') }}</h3></div>
                    <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.dialog.close()" x-bind:disabled="submitting"
                            aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
                </header>
                <div class="ui-modal__body">
                    <p class="ui-enroll-dialog-text" x-text="label"></p>
                    <p class="ui-banner">{{ __('lf.LF_course_template_version_links_mark_body') }}</p>
                    <div class="ui-field ui-field--full">
                        <label class="ui-field__label" for="course-version-link-reason"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_template_version_links_reason') }}</label>
                        <textarea id="course-version-link-reason" x-ref="reason" name="invalidation_reason" rows="3" maxlength="1000" required></textarea>
                    </div>
                </div>
                <footer class="ui-modal__footer">
                    <span></span>
                    <button type="button" class="ui-button cursor-interaction" x-on:click="$refs.dialog.close()" x-bind:disabled="submitting">{{ __('lf.LF_course_cohort_lifecycle_cancel') }}</button>
                    <button type="submit" class="ui-button ui-button--danger-outline cursor-interaction" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                        <span x-show="!submitting">{{ __('lf.LF_course_template_version_links_mark') }}</span>
                        <span x-cloak x-show="submitting">{{ __('lf.LF_course_cohort_lifecycle_processing') }}</span>
                    </button>
                </footer>
            </form>
        </dialog>
    </section>
@endif
