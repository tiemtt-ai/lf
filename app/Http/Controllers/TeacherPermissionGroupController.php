<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCourseCohortAdmin;
use App\Services\TeacherPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "Phân quyền" (ADR-0033 D4–D5): the teachers' permission groups and which teachers belong to each. Every
 * `customer_admin` manages them; what is configurable is only a group's permissions and a teacher's group.
 */
final class TeacherPermissionGroupController extends Controller
{
    use AuthorizesCourseCohortAdmin;

    public function __construct(private readonly TeacherPermissions $permissions) {}

    public function index(Request $request): View
    {
        $customerId = $this->authorizeAdmin($request);
        $groups = $this->permissions->groups($customerId);

        $form = in_array($request->query('group_form'), ['edit', 'teachers'], true) ? $request->query('group_form') : null;
        $selected = $form ? $groups->firstWhere('id', (int) $request->query('group_id')) : null;
        abort_if($form && ! $selected, 404);

        return view('teacher-permissions.index', [
            'groups' => $groups,
            'formMode' => $form,
            'formGroup' => $selected,
            'teachers' => $form === 'teachers' ? $this->teachersFor($customerId, $groups) : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        [$name, $keys] = $this->validated($request, $customerId, null);
        $this->permissions->create($request, $customerId, $name, $keys);

        return redirect()->route('admin.teacher-permissions.index')->with('success', __('lf.LF_teacher_permission_group_created'));
    }

    public function update(Request $request, int $group): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $row = $this->permissions->find($customerId, $group) ?? abort(404);
        [$name, $keys] = $this->validated($request, $customerId, $row);
        $this->permissions->update($request, $customerId, $row, $name, $keys);

        return redirect()->route('admin.teacher-permissions.index')->with('success', __('lf.LF_teacher_permission_group_updated'));
    }

    public function destroy(Request $request, int $group): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $row = $this->permissions->find($customerId, $group) ?? abort(404);
        $back = redirect()->route('admin.teacher-permissions.index');

        return $this->permissions->delete($request, $customerId, $row)
            ? $back->with('success', __('lf.LF_teacher_permission_group_deleted'))
            : $back->withErrors(['group' => __($row->is_default ? 'lf.LF_teacher_permission_group_delete_default' : 'lf.LF_teacher_permission_group_delete_used')]);
    }

    /** Puts the ticked teachers in the group; on a group other than the default, an unticked member goes back to the default. */
    public function assign(Request $request, int $group): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $row = $this->permissions->find($customerId, $group) ?? abort(404);
        $validated = $request->validate([
            'shown_teacher_ids' => ['present', 'array'],
            'shown_teacher_ids.*' => ['integer'],
            'teacher_ids' => ['nullable', 'array'],
            'teacher_ids.*' => ['integer'],
        ]);
        $shown = array_map('intval', $validated['shown_teacher_ids']);
        $ticked = array_values(array_intersect(array_map('intval', $validated['teacher_ids'] ?? []), $shown));
        $changed = $this->permissions->assign($request, $customerId, $row, $shown, $ticked);

        return redirect()->route('admin.teacher-permissions.index')
            ->with('success', trans_choice('lf.LF_teacher_permission_group_assigned', $changed, ['count' => $changed]));
    }

    /** @return array{0: string, 1: list<string>} */
    private function validated(Request $request, int $customerId, ?object $existing): array
    {
        $validator = validator($request->only(['name', 'keys']), [
            'name' => ['required', 'string', 'max:150', Rule::unique(TeacherPermissions::GROUPS_TABLE, 'name')
                ->where('customer_id', $customerId)->ignore($existing?->id)],
            'keys' => ['nullable', 'array'],
            'keys.*' => ['string', Rule::in(TeacherPermissions::AVAILABLE)],
        ], [], ['name' => __('lf.LF_teacher_permission_group_field_name')]);
        if ($validator->fails()) {
            throw (new ValidationException($validator))->errorBag($existing ? 'groupEdit' : 'groupCreate');
        }
        $data = $validator->validated();

        return [trim($data['name']), array_values(array_unique($data['keys'] ?? []))];
    }

    /** The teachers offered in "Giáo viên của nhóm", each with the group they are in now. */
    private function teachersFor(int $customerId, $groups)
    {
        $names = $groups->pluck('name', 'id');
        $default = $groups->firstWhere('is_default', 1);

        return $this->permissions->teachers($customerId)->each(function (object $teacher) use ($names, $default): void {
            $teacher->group_id = $teacher->teacher_permission_group_id === null ? (int) $default->id : (int) $teacher->teacher_permission_group_id;
            $teacher->group_name = $names->get($teacher->group_id, $default->name);
        });
    }
}
