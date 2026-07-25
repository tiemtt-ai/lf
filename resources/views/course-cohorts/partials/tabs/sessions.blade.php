<div class="admin-card admin-form-card admin-form-surface"
     x-data="{
         lessonId: '',
         operational: false,
         mode: 'online',
         activities: @js($versionActivities),
         availableActivities() {
             return this.activities.filter(item => String(item.version_lesson_id) === String(this.lessonId))
         }
     }">
    <div class="admin-form-standard">
        <section class="admin-form-standard-section">
            <header class="admin-form-section-header">
                <h2 class="admin-form-section-title">{{ __('lf.LF_course_cohort_session_title') }}</h2>
                <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_session_help') }}</p>
            </header>
            @if (in_array($cohort->status, ['draft', 'active'], true))
                <form method="POST" action="{{ route('admin.course-cohorts.sessions.store', $cohort->id) }}" class="admin-form-field-grid">
                    @csrf
                    <div class="lf-form-group admin-form-field--full">
                        <x-form-label for="session_title" :value="__('lf.LF_course_cohort_session_name')" :required="true" />
                        <input id="session_title" name="title" class="lf-form-control" required maxlength="255">
                    </div>
                    <div class="lf-form-group">
                        <x-form-label for="version_lesson_id" :value="__('lf.LF_course_cohort_session_lesson')" :required="true" />
                        <select id="version_lesson_id" name="version_lesson_id" class="lf-form-control" x-model="lessonId" required>
                            <option value="">{{ __('lf.LF_course_cohort_session_select_lesson') }}</option>
                            @foreach ($versionLessons as $lesson)<option value="{{ $lesson->id }}">{{ $lesson->title_snapshot }}</option>@endforeach
                        </select>
                    </div>
                    <div class="lf-form-group">
                        <label class="admin-form-option-panel admin-form-option-panel--compact">
                            <input type="checkbox" x-model="operational">
                            <span><strong>{{ __('lf.LF_course_cohort_session_operational') }}</strong><small>{{ __('lf.LF_course_cohort_session_operational_help') }}</small></span>
                        </label>
                    </div>
                    <div class="lf-form-group admin-form-field--full" x-show="!operational" x-cloak>
                        <x-form-label for="version_activity_id" :value="__('lf.LF_course_cohort_session_activity')" :required="true" />
                        <select id="version_activity_id" name="version_activity_id" class="lf-form-control" x-bind:required="!operational" x-bind:disabled="operational">
                            <option value="">{{ __('lf.LF_course_cohort_session_select_activity') }}</option>
                            <template x-for="activity in availableActivities()" :key="activity.id">
                                <option :value="activity.id" x-text="activity.title_snapshot"></option>
                            </template>
                        </select>
                    </div>
                    <div class="lf-form-group">
                        <x-form-label for="scheduled_start_at" :value="__('lf.LF_course_cohort_session_start')" :required="true" />
                        <input id="scheduled_start_at" type="datetime-local" name="scheduled_start_at" class="lf-form-control" required>
                    </div>
                    <div class="lf-form-group">
                        <x-form-label for="scheduled_end_at" :value="__('lf.LF_course_cohort_session_end')" :required="true" />
                        <input id="scheduled_end_at" type="datetime-local" name="scheduled_end_at" class="lf-form-control" required>
                    </div>
                    <div class="lf-form-group">
                        <x-form-label for="delivery_mode" :value="__('lf.LF_course_cohort_session_mode')" :required="true" />
                        <select id="delivery_mode" name="delivery_mode" class="lf-form-control" x-model="mode" required>
                            @foreach (['online', 'offline', 'hybrid'] as $mode)<option value="{{ $mode }}">{{ __('lf.LF_course_cohort_session_mode_'.$mode) }}</option>@endforeach
                        </select>
                    </div>
                    <div class="lf-form-group">
                        <x-form-label for="primary_teacher_id" :value="__('lf.LF_course_cohort_session_primary_teacher')" />
                        <select id="primary_teacher_id" name="primary_teacher_id" class="lf-form-control">
                            <option value="">{{ __('lf.LF_course_cohort_session_select_teacher') }}</option>
                            @foreach ($availableTeachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="lf-form-group" x-show="mode === 'online' || mode === 'hybrid'" x-cloak>
                        <x-form-label for="online_provider" :value="__('lf.LF_course_cohort_session_provider')" :required="true" />
                        <input id="online_provider" name="online_provider" class="lf-form-control" x-bind:required="mode === 'online' || mode === 'hybrid'">
                    </div>
                    <div class="lf-form-group" x-show="mode === 'online' || mode === 'hybrid'" x-cloak>
                        <x-form-label for="meeting_url" :value="__('lf.LF_course_cohort_session_meeting_url')" :required="true" />
                        <input id="meeting_url" type="url" name="meeting_url" class="lf-form-control" x-bind:required="mode === 'online' || mode === 'hybrid'">
                    </div>
                    <div class="lf-form-group" x-show="mode === 'offline' || mode === 'hybrid'" x-cloak>
                        <x-form-label for="room_name" :value="__('lf.LF_course_cohort_session_room')" :required="true" />
                        <input id="room_name" name="room_name" class="lf-form-control" x-bind:required="mode === 'offline' || mode === 'hybrid'">
                    </div>
                    <div class="lf-form-group" x-show="mode === 'offline' || mode === 'hybrid'" x-cloak>
                        <x-form-label for="address" :value="__('lf.LF_course_cohort_session_address')" :required="true" />
                        <input id="address" name="address" class="lf-form-control" x-bind:required="mode === 'offline' || mode === 'hybrid'">
                    </div>
                    <div class="admin-form-actions admin-form-field--full"><button type="submit" class="btn btn-primary">{{ __('lf.LF_course_cohort_session_create') }}</button></div>
                </form>
            @endif
        </section>
        <section class="admin-form-standard-section">
            <div class="admin-table-wrap"><table class="table">
                <thead><tr><th>#</th><th>{{ __('lf.LF_course_cohort_session_name') }}</th><th>{{ __('lf.LF_course_cohort_session_schedule') }}</th><th>{{ __('lf.LF_course_cohort_session_mode') }}</th><th>{{ __('lf.LF_course_cohort_session_primary_teacher') }}</th><th>{{ __('lf.LF_course_cohort_common_status') }}</th><th>{{ __('lf.table_actions') }}</th></tr></thead>
                <tbody>
                @forelse ($sessions as $session)
                    <tr>
                        <td>{{ $session->session_no }}</td>
                        <td><strong>{{ $session->title }}</strong><br><span class="lf-secondary-text">{{ $session->lesson_title }}@if($session->activity_title) · {{ $session->activity_title }}@endif</span></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($session->scheduled_start_at)->format('d/m/Y H:i') }} → {{ \Illuminate\Support\Carbon::parse($session->scheduled_end_at)->format('H:i') }}</td>
                        <td>{{ __('lf.LF_course_cohort_session_mode_'.$session->delivery_mode) }}</td>
                        <td>{{ $session->primary_teacher_name ?: '—' }}</td>
                        <td>{{ __('lf.LF_course_cohort_session_status_'.$session->status) }}</td>
                        <td>
                            @if (in_array($cohort->status, ['draft', 'active'], true))
                                <details>
                                    <summary class="btn btn-outline-primary btn-sm">{{ __('lf.LF_course_cohort_session_change_schedule') }}</summary>
                                    <form method="POST" action="{{ route('admin.course-cohorts.sessions.schedule', [$cohort->id, $session->id]) }}" class="mt-3">
                                        @csrf
                                        @method('PUT')
                                        <label class="lf-form-label">{{ __('lf.LF_course_cohort_session_start') }}</label>
                                        <input type="datetime-local" name="scheduled_start_at" class="lf-form-control mb-2" value="{{ \Illuminate\Support\Carbon::parse($session->scheduled_start_at)->format('Y-m-d\TH:i') }}" required>
                                        <label class="lf-form-label">{{ __('lf.LF_course_cohort_session_end') }}</label>
                                        <input type="datetime-local" name="scheduled_end_at" class="lf-form-control mb-2" value="{{ \Illuminate\Support\Carbon::parse($session->scheduled_end_at)->format('Y-m-d\TH:i') }}" required>
                                        <label class="lf-form-label">{{ __('lf.LF_course_cohort_session_change_reason') }}</label>
                                        <textarea name="reason" class="lf-form-control mb-2" rows="2" maxlength="1000"></textarea>
                                        <button type="submit" class="btn btn-primary btn-sm">{{ __('lf.LF_course_cohort_session_save_schedule') }}</button>
                                    </form>
                                </details>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="course-cohort-empty-state">{{ __('lf.LF_course_cohort_session_empty') }}</div></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    </div>
</div>
