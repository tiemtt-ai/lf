<section id="course-template-teachers"
         class="admin-card admin-form-card course-template-section-card"
         aria-labelledby="course-template-teachers-title">
    <header class="course-template-section-header">
        <h2 id="course-template-teachers-title" class="admin-form-section-title">
            {{ __('lf.LF_course_template_teacher_common_list_title') }}
        </h2>
        <p>{{ __('lf.LF_course_template_teacher_common_list_help') }}</p>
    </header>

    <div class="course-template-section-action-bar">
        <strong>
            {{ trans_choice(
                'lf.LF_course_template_teacher_common_count',
                $teacherAssignments->count(),
                ['count' => $teacherAssignments->count()]
            ) }}
        </strong>
        <a href="{{ route($teacherRoutePrefix.'.create', $template->id) }}"
           class="btn admin-primary-outline-action">
            {{ __('lf.LF_course_template_teacher_common_add_action') }}
        </a>
    </div>

    @if ($teacherAssignments->isEmpty())
        <p>{{ __('lf.LF_course_template_teacher_common_empty') }}</p>
    @else
        <div class="admin-table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                    <th>{{ __('lf.LF_course_template_teacher_common_name') }}</th>
                    <th>{{ __('lf.LF_course_template_teacher_common_email') }}</th>
                    <th>{{ __('lf.LF_course_template_teacher_common_role') }}</th>
                    <th>{{ __('lf.LF_course_template_teacher_common_status') }}</th>
                    <th>{{ __('lf.table_actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($teacherAssignments as $assignment)
                    <tr>
                        <td class="admin-table-sequence">{{ $loop->iteration }}</td>
                        <td>{{ $assignment->teacher_name }}</td>
                        <td>{{ $assignment->teacher_email }}</td>
                        <td>
                            {{ __('lf.LF_course_template_teacher_common_role_'.$assignment->role) }}
                        </td>
                        <td>
                            <span @class([
                                'badge',
                                'badge-success' => $assignment->status === 'active',
                                'badge-danger' => $assignment->status === 'inactive',
                            ])>
                                {{ __('lf.LF_course_template_teacher_common_status_'.$assignment->status) }}
                            </span>
                        </td>
                        <td>
                            <div class="admin-table-actions">
                                <a class="admin-table-action-link admin-text-action" href="{{ route(
                                    $teacherRoutePrefix.'.edit',
                                    [$template->id, $assignment->id]
                                ) }}">
                                    {{ __('lf.LF_course_template_teacher_common_edit') }}
                                </a>
                                @if ($assignment->status === 'active')
                                    <button type="button"
                                            class="admin-link-button admin-text-action"
                                            x-data
                                            x-on:click="$dispatch(
                                                'open-modal',
                                                'remove-course-template-teacher-{{ $assignment->id }}'
                                            )">
                                        {{ __('lf.LF_course_template_teacher_common_remove') }}
                                    </button>
                                @endif
                            </div>

                            @if ($assignment->status === 'active')
                                <x-modal name="remove-course-template-teacher-{{ $assignment->id }}"
                                         focusable>
                                    <div class="lf-modal-card">
                                        <h2>
                                            {{ __('lf.LF_course_template_teacher_common_remove_confirm') }}
                                        </h2>
                                        <div class="lf-modal-actions">
                                            <button type="button"
                                                    class="btn"
                                                    x-on:click="$dispatch(
                                                        'close-modal',
                                                        'remove-course-template-teacher-{{ $assignment->id }}'
                                                    )">
                                                {{ __('lf.LF_course_template_teacher_common_remove_no') }}
                                            </button>
                                            <form method="POST"
                                                  action="{{ route(
                                                      $teacherRoutePrefix.'.destroy',
                                                      [
                                                          $template->id,
                                                          $assignment->id,
                                                      ]
                                                  ) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="btn btn-primary">
                                                    {{ __('lf.LF_course_template_teacher_common_remove_yes') }}
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </x-modal>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
