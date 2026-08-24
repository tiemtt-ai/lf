@php
    $selection = collect($learningMappingState['versions'])->first(fn ($version) => (int) $version->framework_id === (int) ($template->selected_learning_framework_id ?? 0) && (int) $version->framework_version_id === (int) ($template->selected_learning_framework_version_id ?? 0));
    $sources = collect($directLessons)->merge(collect($lessonsBySection)->flatten())->map(fn ($lesson) => (object) ['type' => 'course_template_lesson', 'id' => $lesson->id, 'label' => 'Bài học: '.$lesson->title])
        ->merge(collect($activitiesByLesson)->flatten()->map(fn ($activity) => (object) ['type' => 'course_template_activity', 'id' => $activity->id, 'label' => 'Hoạt động: '.$activity->title]));
@endphp
<div class="admin-card admin-form-card admin-form-surface">
    <h2 class="admin-form-section-title">Chuẩn đầu ra & năng lực</h2>
    <p class="admin-form-help">Chọn một phiên bản chuẩn đã xuất bản. Mapping chỉ là ý định trên bản nháp; khi xuất bản Template, hệ thống sẽ neo Mapping vào Lesson/Activity snapshot tương ứng.</p>
    <form class="admin-form-standard" method="POST" action="{{ route($routePrefix.'.learning-framework.select', $template->id) }}">
        @csrf @method('PUT')
        <div class="admin-form-group">
            <label for="learning-framework-version">Framework Version đã xuất bản</label>
            <select id="learning-framework-version" name="framework_version_id" required onchange="this.form.framework_id.value=this.options[this.selectedIndex].dataset.frameworkId">
                <option value="">Chọn bộ chuẩn</option>
                @foreach ($learningMappingState['versions'] as $version)
                    <option value="{{ $version->framework_version_id }}" data-framework-id="{{ $version->framework_id }}" @selected($selection && $selection->framework_version_id == $version->framework_version_id)>{{ $version->framework_name }} — {{ $version->version_code }}</option>
                @endforeach
            </select>
            <input type="hidden" name="framework_id" value="{{ $selection?->framework_id }}">
        </div>
        <button class="btn btn-secondary" type="submit">Lưu Framework Version</button>
    </form>

    @if ($selection)
        <hr>
        <h3 class="admin-form-section-title">Gắn Node thủ công</h3>
        <form class="admin-form-standard" method="POST" action="{{ route($routePrefix.'.learning-mappings.store', $template->id) }}">
            @csrf
            <div class="admin-form-group"><label for="mapping-source">Lesson hoặc Activity</label><select id="mapping-source" name="source_id" required>@foreach ($sources as $source)<option value="{{ $source->id }}" data-type="{{ $source->type }}">{{ $source->label }}</option>@endforeach</select><input type="hidden" name="source_type" value="course_template_lesson"></div>
            <div class="admin-form-group"><label for="mapping-node">Node</label><select id="mapping-node" name="learning_node_id" required>@foreach ($learningMappingState['nodes'] as $node)<option value="{{ $node->id }}">{{ $node->code_snapshot }} — {{ $node->name_snapshot }}</option>@endforeach</select></div>
            <div class="admin-form-group"><label for="mapping-role">Vai trò</label><select id="mapping-role" name="mapping_role"><option value="teaches">Giảng dạy</option><option value="practices">Luyện tập</option><option value="assesses">Đánh giá</option></select></div>
            <div class="admin-form-group"><label for="mapping-weight">Trọng số (0–1, tùy chọn)</label><input id="mapping-weight" type="number" name="weight" min="0" max="1" step="0.01"></div>
            <button class="btn btn-primary" type="submit">Thêm Mapping</button>
        </form>
        <script>document.getElementById('mapping-source')?.addEventListener('change', function () { this.form.source_type.value = this.options[this.selectedIndex].dataset.type; });</script>
    @endif

    <hr>
    <h3 class="admin-form-section-title">Mapping hiện có</h3>
    @forelse ($learningMappingState['intents'] as $intent)
        <div class="admin-list-row"><span>{{ $intent->name_snapshot }} · {{ $intent->mapping_role }} · {{ $intent->source_type }} #{{ $intent->source_id }}</span><form method="POST" action="{{ route($routePrefix.'.learning-mappings.destroy', [$template->id, $intent->id]) }}">@csrf @method('DELETE')<button class="btn btn-danger" type="submit">Gỡ</button></form></div>
    @empty
        <p>Chưa có Mapping. Bạn có thể gắn Node thủ công sau khi chọn Framework Version.</p>
    @endforelse
</div>
