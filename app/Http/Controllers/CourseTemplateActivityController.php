<?php

namespace App\Http\Controllers;

use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseTemplateActivityController extends Controller
{
    private const ACTIVITY_TYPES = [
        'text',
        'video',
        'audio',
        'document',
        'quiz',
        'assignment',
        'liveclass',
        'external_link',
    ];

    private const MANUAL_DURATION_TYPES = [
        'text',
        'external_link',
        'assignment',
    ];

    private const REFERENCE_ACTIVITY_TYPES = [
        'video',
        'audio',
        'document',
        'quiz',
        'assignment',
        'liveclass',
    ];

    public function __construct(private readonly MediaService $mediaService) {}

    public function index(
        Request $request,
        int $templateId,
        int $sectionId,
        int $lessonId
    ) {
        return $this->indexForLesson(
            $request,
            $templateId,
            $lessonId,
            $sectionId
        );
    }

    public function indexDirect(
        Request $request,
        int $templateId,
        int $lessonId
    ) {
        return $this->indexForLesson($request, $templateId, $lessonId, null);
    }

    public function create(
        Request $request,
        int $templateId,
        int $sectionId,
        int $lessonId
    ): View {
        return $this->createView(
            $request,
            $templateId,
            $lessonId,
            $sectionId
        );
    }

    public function createDirect(
        Request $request,
        int $templateId,
        int $lessonId
    ): View {
        return $this->createView($request, $templateId, $lessonId, null);
    }

    public function store(
        Request $request,
        int $templateId,
        int $sectionId,
        int $lessonId
    ) {
        return $this->storeActivity(
            $request,
            $templateId,
            $lessonId,
            $sectionId
        );
    }

    public function storeDirect(
        Request $request,
        int $templateId,
        int $lessonId
    ) {
        return $this->storeActivity($request, $templateId, $lessonId, null);
    }

    public function edit(
        Request $request,
        int $templateId,
        int $sectionId,
        int $lessonId,
        int $activityId
    ): View {
        return $this->editView(
            $request,
            $templateId,
            $lessonId,
            $activityId,
            $sectionId
        );
    }

    public function editDirect(
        Request $request,
        int $templateId,
        int $lessonId,
        int $activityId
    ): View {
        return $this->editView(
            $request,
            $templateId,
            $lessonId,
            $activityId,
            null
        );
    }

    public function update(
        Request $request,
        int $templateId,
        int $sectionId,
        int $lessonId,
        int $activityId
    ) {
        return $this->updateActivity(
            $request,
            $templateId,
            $lessonId,
            $activityId,
            $sectionId
        );
    }

    public function updateDirect(
        Request $request,
        int $templateId,
        int $lessonId,
        int $activityId
    ) {
        return $this->updateActivity(
            $request,
            $templateId,
            $lessonId,
            $activityId,
            null
        );
    }

    public function destroy(
        Request $request,
        int $templateId,
        int $sectionId,
        int $lessonId,
        int $activityId
    ) {
        return $this->destroyActivity(
            $request,
            $templateId,
            $lessonId,
            $activityId,
            $sectionId
        );
    }

    public function destroyDirect(
        Request $request,
        int $templateId,
        int $lessonId,
        int $activityId
    ) {
        return $this->destroyActivity(
            $request,
            $templateId,
            $lessonId,
            $activityId,
            null
        );
    }

    private function indexForLesson(
        Request $request,
        int $templateId,
        int $lessonId,
        ?int $sectionId
    ) {
        $customerId = $this->customerId();
        $this->findHierarchy(
            $customerId,
            $templateId,
            $sectionId,
            $lessonId
        );

        return redirect()->to(
            route($this->templateRoutePrefix($request).'.edit', $templateId)
            ."?tab=structure#course-template-lesson-{$lessonId}-activities"
        );
    }

    private function createView(
        Request $request,
        int $templateId,
        int $lessonId,
        ?int $sectionId
    ): View {
        $customerId = $this->customerId();
        [$template, $section, $lesson] = $this->findHierarchy(
            $customerId,
            $templateId,
            $sectionId,
            $lessonId
        );

        return view('course-template-activities.create', [
            'template' => $template,
            'section' => $section,
            'lesson' => $lesson,
            'prerequisiteActivities' => $this->prerequisiteActivities(
                $customerId,
                $templateId
            ),
            'requiredFields' => $this->requiredFields(
                $customerId,
                $templateId
            ),
            'activityTypes' => self::ACTIVITY_TYPES,
            'manualDurationTypes' => self::MANUAL_DURATION_TYPES,
            'referenceActivityTypes' => self::REFERENCE_ACTIVITY_TYPES,
            'activityMedia' => collect(),
            'routePrefix' => $this->routePrefix($request, $sectionId),
            'templateRoutePrefix' => $this->templateRoutePrefix($request),
        ]);
    }

    private function storeActivity(
        Request $request,
        int $templateId,
        int $lessonId,
        ?int $sectionId
    ) {
        $customerId = $this->customerId();
        $this->findHierarchy(
            $customerId,
            $templateId,
            $sectionId,
            $lessonId
        );
        $validated = $this->validatedData(
            $request,
            $customerId,
            $templateId
        );
        $now = now();

        $activityId = DB::table('core_course_template_activities')->insertGetId(
            $this->activityValues($validated, [
                'customer_id' => $customerId,
                'template_id' => $templateId,
                'template_lesson_id' => $lessonId,
                'created_by' => $request->user()?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
        );

        $this->attachUploadedMedia($request, $activityId);

        return redirect()
            ->to(
                route(
                    $this->templateRoutePrefix($request).'.edit',
                    $templateId
                )."?tab=structure#course-template-lesson-{$lessonId}-activities"
            )
            ->with('success', __('lf.LF_course_template_activity_common_created'));
    }

    private function editView(
        Request $request,
        int $templateId,
        int $lessonId,
        int $activityId,
        ?int $sectionId
    ): View {
        $customerId = $this->customerId();
        [$template, $section, $lesson] = $this->findHierarchy(
            $customerId,
            $templateId,
            $sectionId,
            $lessonId
        );
        $activity = $this->findActivity(
            $customerId,
            $templateId,
            $lessonId,
            $activityId
        );

        return view('course-template-activities.edit', [
            'template' => $template,
            'section' => $section,
            'lesson' => $lesson,
            'activity' => $activity,
            'prerequisiteActivities' => $this->prerequisiteActivities(
                $customerId,
                $templateId,
                $activityId
            ),
            'requiredFields' => $this->requiredFields(
                $customerId,
                $templateId,
                $activityId
            ),
            'activityTypes' => self::ACTIVITY_TYPES,
            'manualDurationTypes' => self::MANUAL_DURATION_TYPES,
            'referenceActivityTypes' => self::REFERENCE_ACTIVITY_TYPES,
            'activityMedia' => $this->ownerMedia(
                'course_activity',
                $activityId
            ),
            'routePrefix' => $this->routePrefix($request, $sectionId),
            'templateRoutePrefix' => $this->templateRoutePrefix($request),
        ]);
    }

    private function updateActivity(
        Request $request,
        int $templateId,
        int $lessonId,
        int $activityId,
        ?int $sectionId
    ) {
        $customerId = $this->customerId();
        $this->findHierarchy(
            $customerId,
            $templateId,
            $sectionId,
            $lessonId
        );
        $this->findActivity(
            $customerId,
            $templateId,
            $lessonId,
            $activityId
        );
        $validated = $this->validatedData(
            $request,
            $customerId,
            $templateId,
            $activityId
        );

        DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('template_lesson_id', $lessonId)
            ->where('id', $activityId)
            ->update($this->activityValues($validated, [
                'updated_at' => now(),
            ]));

        $this->attachUploadedMedia($request, $activityId);

        return redirect()
            ->route(
                $this->routePrefix($request, $sectionId).'.edit',
                $this->activityRouteParameters(
                    $templateId,
                    $lessonId,
                    $activityId,
                    $sectionId
                )
            )
            ->with('success', __('lf.LF_course_template_activity_common_updated'));
    }

    private function destroyActivity(
        Request $request,
        int $templateId,
        int $lessonId,
        int $activityId,
        ?int $sectionId
    ) {
        $customerId = $this->customerId();
        $this->findHierarchy(
            $customerId,
            $templateId,
            $sectionId,
            $lessonId
        );
        $this->findActivity(
            $customerId,
            $templateId,
            $lessonId,
            $activityId
        );

        if (
            DB::table('core_course_template_activities')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('unlock_after_activity_id', $activityId)
                ->exists()
        ) {
            return back()->withErrors([
                'activity' => __(
                    'lf.LF_course_template_activity_common_delete_blocked'
                ),
            ]);
        }

        DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('template_lesson_id', $lessonId)
            ->where('id', $activityId)
            ->delete();

        return redirect()
            ->to(
                route(
                    $this->templateRoutePrefix($request).'.edit',
                    $templateId
                )."?tab=structure#course-template-lesson-{$lessonId}-activities"
            )
            ->with('success', __('lf.LF_course_template_activity_common_deleted'));
    }

    private function validatedData(
        Request $request,
        int $customerId,
        int $templateId,
        ?int $activityId = null
    ): array {
        $validator = Validator::make(
            $request->all(),
            $this->validationRules($customerId, $templateId, $activityId),
            [],
            [
                'activity_type' => __(
                    'lf.LF_course_template_activity_common_type'
                ),
            ]
        );

        if ($activityId !== null) {
            $validator->after(function ($validator) use (
                $request,
                $customerId,
                $templateId,
                $activityId
            ): void {
                $prerequisiteId = $request->integer(
                    'unlock_after_activity_id'
                );

                if (
                    $prerequisiteId !== 0
                    && (
                        $prerequisiteId === $activityId
                        || $this->dependencyWouldCycle(
                            $customerId,
                            $templateId,
                            $activityId,
                            $prerequisiteId
                        )
                    )
                ) {
                    $validator->errors()->add(
                        'unlock_after_activity_id',
                        __('lf.LF_course_template_activity_common_invalid_prerequisite')
                    );
                }
            });
        }

        return $validator->validate();
    }

    private function validationRules(
        int $customerId,
        int $templateId,
        ?int $activityId = null
    ): array {
        $activityType = request()->input('activity_type');

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'activity_type' => [
                'required',
                Rule::in(self::ACTIVITY_TYPES),
            ],
            'activity_ref_type' => [
                'nullable',
                'string',
                'max:100',
                'required_with:activity_ref_id',
            ],
            'activity_ref_id' => [
                'nullable',
                'integer',
                'min:1',
                'required_with:activity_ref_type',
            ],
            'external_url' => [
                'nullable',
                'url',
                'max:1000',
                'required_if:activity_type,external_link',
                'prohibited_unless:activity_type,external_link',
            ],
            'embed_code' => ['nullable', 'string'],
            'duration_seconds' => [
                'nullable',
                'integer',
                'min:0',
                Rule::prohibitedIf(
                    ! in_array(
                        $activityType,
                        self::MANUAL_DURATION_TYPES,
                        true
                    )
                ),
            ],
            'is_required' => ['required', 'boolean'],
            'completion_rule' => [
                'required',
                Rule::in([
                    'view',
                    'watch_percent',
                    'submit',
                    'pass',
                    'attend',
                    'manual',
                ]),
            ],
            'completion_threshold' => [
                'nullable',
                'integer',
                'min:0',
                'required_if:completion_rule,watch_percent,pass',
            ],
            'is_preview' => ['required', 'boolean'],
            'unlock_rule' => [
                'required',
                Rule::in([
                    'none',
                    'previous_activity_completed',
                    'previous_lesson_completed',
                    'date_based',
                ]),
            ],
            'unlock_after_activity_id' => [
                'nullable',
                'integer',
                'required_if:unlock_rule,previous_activity_completed',
                'prohibited_unless:unlock_rule,previous_activity_completed',
                Rule::exists('core_course_template_activities', 'id')
                    ->where(fn ($query) => $query
                        ->where('customer_id', $customerId)
                        ->where('template_id', $templateId)),
            ],
            'unlock_at' => [
                'nullable',
                'date',
                'required_if:unlock_rule,date_based',
                'prohibited_unless:unlock_rule,date_based',
            ],
            'status' => [
                'required',
                Rule::in(['draft', 'active', 'inactive', 'archived']),
            ],
            'activity_video_file' => [
                'nullable',
                'file',
                'max:'.(int) config('media.max_upload_kilobytes', 102400),
            ],
            'activity_audio_file' => [
                'nullable',
                'file',
                'max:'.(int) config('media.max_upload_kilobytes', 102400),
            ],
            'activity_document_file' => [
                'nullable',
                'file',
                'max:'.(int) config('media.max_upload_kilobytes', 102400),
            ],
            'activity_attachment_file' => [
                'nullable',
                'file',
                'max:'.(int) config('media.max_upload_kilobytes', 102400),
            ],
        ];
    }

    private function requiredFields(
        int $customerId,
        int $templateId,
        ?int $activityId = null
    ): array {
        return array_keys(array_filter(
            $this->validationRules($customerId, $templateId, $activityId),
            fn (array $rules): bool => in_array('required', $rules, true)
        ));
    }

    private function activityValues(
        array $validated,
        array $extra = []
    ): array {
        return array_merge([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'],
            'activity_type' => $validated['activity_type'],
            'activity_ref_type' => $validated['activity_ref_type'] ?? null,
            'activity_ref_id' => $validated['activity_ref_id'] ?? null,
            'external_url' => $validated['external_url'] ?? null,
            'embed_code' => $validated['embed_code'] ?? null,
            'duration_seconds' => in_array(
                $validated['activity_type'],
                self::MANUAL_DURATION_TYPES,
                true
            ) ? ($validated['duration_seconds'] ?? 0) : 0,
            'is_required' => (bool) $validated['is_required'],
            'completion_rule' => $validated['completion_rule'],
            'completion_threshold' => $validated['completion_threshold'] ?? null,
            'is_preview' => (bool) $validated['is_preview'],
            'unlock_rule' => $validated['unlock_rule'],
            'unlock_after_activity_id' => $validated['unlock_after_activity_id'] ?? null,
            'unlock_at' => isset($validated['unlock_at'])
                ? Carbon::parse($validated['unlock_at'])->format('Y-m-d H:i:s')
                : null,
            'status' => $validated['status'],
        ], $extra);
    }

    private function prerequisiteActivities(
        int $customerId,
        int $templateId,
        ?int $excludedActivityId = null
    ) {
        return DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->when(
                $excludedActivityId !== null,
                fn ($query) => $query->where('id', '!=', $excludedActivityId)
            )
            ->orderBy('template_lesson_id')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
    }

    private function dependencyWouldCycle(
        int $customerId,
        int $templateId,
        int $activityId,
        int $prerequisiteId
    ): bool {
        $dependencies = DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->pluck('unlock_after_activity_id', 'id');
        $visited = [];
        $currentId = $prerequisiteId;

        while ($currentId !== 0 && ! in_array($currentId, $visited, true)) {
            if ($currentId === $activityId) {
                return true;
            }

            $visited[] = $currentId;
            $currentId = (int) ($dependencies[$currentId] ?? 0);
        }

        return false;
    }

    private function findHierarchy(
        int $customerId,
        int $templateId,
        ?int $sectionId,
        int $lessonId
    ): array {
        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->first();

        abort_if(! $template, 404);
        $this->authorizeTemplateAccess($customerId, $template);

        $section = null;

        if ($sectionId !== null) {
            $section = DB::table('core_course_template_sections')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('id', $sectionId)
                ->first();

            abort_if(! $section, 404);
        }

        $lesson = DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->when(
                $sectionId === null,
                fn ($query) => $query->whereNull('template_section_id'),
                fn ($query) => $query->where(
                    'template_section_id',
                    $sectionId
                )
            )
            ->where('id', $lessonId)
            ->first();

        abort_if(! $lesson, 404);

        return [$template, $section, $lesson];
    }

    private function findActivity(
        int $customerId,
        int $templateId,
        int $lessonId,
        int $activityId
    ): object {
        $activity = DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('template_lesson_id', $lessonId)
            ->where('id', $activityId)
            ->first();

        abort_if(! $activity, 404);

        return $activity;
    }

    private function attachUploadedMedia(Request $request, int $activityId): void
    {
        foreach ([
            'activity_video_file' => ['video', 'video'],
            'activity_audio_file' => ['audio', 'audio'],
            'activity_document_file' => ['document', 'document'],
            'activity_attachment_file' => ['document', 'attachment'],
        ] as $field => [$fileType, $usageType]) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $mediaFile = $this->mediaService->upload(
                $request->file($field),
                [
                    'file_type' => $fileType,
                    'module' => 'course',
                    'entity_type' => 'activities',
                    'entity_id' => $activityId,
                    'purpose' => $usageType,
                    'display_name' => $request->input('title'),
                ],
                (int) $request->user()->id
            );

            $this->mediaService->attachUsage(
                (int) $mediaFile->id,
                'course_activity',
                $activityId,
                $usageType
            );
        }
    }

    private function ownerMedia(string $ownerType, int $ownerId): object
    {
        return $this->mediaService
            ->getOwnerMedia($ownerType, $ownerId)
            ->map(function (object $media): object {
                $media->signed_url = $this->mediaService->generateSignedUrl(
                    (int) $media->id
                );

                return $media;
            });
    }

    private function authorizeTemplateAccess(int $customerId, object $template): void
    {
        $user = request()->user();

        if ($user?->role === 'customer_admin') {
            return;
        }

        $isAssignedTeacher = $user?->role === 'teacher'
            && (
                (int) $template->created_by === (int) $user->id
                || DB::table('core_course_template_teachers')
                    ->where('customer_id', $customerId)
                    ->where('template_id', $template->id)
                    ->where('teacher_id', $user->id)
                    ->where('status', 'active')
                    ->exists()
            );

        abort_unless($isAssignedTeacher, 404);
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        return $customerId;
    }

    private function routePrefix(
        Request $request,
        ?int $sectionId
    ): string {
        return $this->templateRoutePrefix($request).(
            $sectionId === null
                ? '.lessons.activities'
                : '.sections.lessons.activities'
        );
    }

    private function activityRouteParameters(
        int $templateId,
        int $lessonId,
        int $activityId,
        ?int $sectionId
    ): array {
        return $sectionId === null
            ? [$templateId, $lessonId, $activityId]
            : [$templateId, $sectionId, $lessonId, $activityId];
    }

    private function templateRoutePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            'customer_admin' => 'admin.course-templates',
            'teacher' => 'teacher.course-templates',
            default => abort(403),
        };
    }
}
