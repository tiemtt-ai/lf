<section id="course-template-sections"
         class="admin-card admin-form-card course-template-section-card"
         aria-labelledby="course-template-sections-title">
    <header class="course-template-section-header">
        <h2 id="course-template-sections-title" class="admin-form-section-title">
            {{ __('lf.LF_course_template_section_common_structure_title') }}
        </h2>
        <p>{{ __('lf.LF_course_template_section_common_structure_help') }}</p>
    </header>

    <div class="course-template-section-action-bar"
         aria-label="{{ __('lf.LF_course_template_section_common_actions') }}">
        <strong>{{ __('lf.LF_course_template_section_common_actions') }}</strong>
        <a href="{{ route($sectionRoutePrefix.'.create', $template->id) }}"
           class="btn btn-primary">
            {{ __('lf.LF_course_template_section_common_add_action') }}
        </a>
    </div>

    <div class="course-template-section-list">
        <h3 class="course-template-section-list-title">
            {{ __('lf.LF_course_template_section_common_list_title') }}
        </h3>

        <div class="admin-table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>{{ __('lf.LF_course_template_section_common_sort_order') }}</th>
                    <th>{{ __('lf.LF_course_template_section_common_name') }}</th>
                    <th>{{ __('lf.LF_course_template_section_common_parent') }}</th>
                    <th>{{ __('lf.LF_course_template_section_common_total_lessons') }}</th>
                    <th>{{ __('lf.LF_course_template_section_common_status') }}</th>
                    <th>{{ __('lf.LF_common_label_common_action') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($sections as $section)
                    <tr>
                        <td>{{ $section->sort_order }}</td>
                        <td>{{ $section->title }}</td>
                        <td>{{ $section->parent_title ?? '—' }}</td>
                        <td>{{ $section->total_lessons }}</td>
                        <td>
                            <span @class([
                                'badge',
                                'badge-success' => $section->status === 'active',
                                'badge-danger' => $section->status === 'archived',
                            ])>
                                {{ __('lf.LF_course_template_section_common_'.$section->status) }}
                            </span>
                        </td>
                        <td>
                            <div class="admin-table-actions">
                                <a href="{{ route(
                                    $sectionRoutePrefix.'.edit',
                                    [$template->id, $section->id]
                                ) }}">
                                    {{ __('lf.LF_course_template_section_common_edit') }}
                                </a>
                                <button type="button"
                                        class="admin-link-button"
                                        x-data
                                        x-on:click="$dispatch(
                                            'open-modal',
                                            'delete-course-template-section-{{ $section->id }}'
                                        )">
                                    {{ __('lf.LF_common_button_delete') }}
                                </button>
                            </div>

                            <x-modal name="delete-course-template-section-{{ $section->id }}"
                                     focusable>
                                <div class="lf-modal-card">
                                    <h2>
                                        {{ __('lf.LF_course_template_section_common_delete_confirm') }}
                                    </h2>
                                    <div class="lf-modal-actions">
                                        <button type="button"
                                                class="btn"
                                                x-on:click="$dispatch(
                                                    'close-modal',
                                                    'delete-course-template-section-{{ $section->id }}'
                                                )">
                                            {{ __('lf.LF_course_template_section_common_delete_no') }}
                                        </button>
                                        <form method="POST"
                                              action="{{ route(
                                                  $sectionRoutePrefix.'.destroy',
                                                  [$template->id, $section->id]
                                              ) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-primary">
                                                {{ __('lf.LF_course_template_section_common_delete_yes') }}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </x-modal>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            {{ __('lf.LF_course_template_section_common_empty') }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
