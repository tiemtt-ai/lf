<?php

namespace App\Http\Controllers;

use App\Services\MediaService;
use App\Support\TenantContext;
use App\Support\UploadLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseTemplateActivityController extends Controller
{
    private const ACTIVITY_TYPES = [
        'video',
        'embedded_video',
        'audio',
        'document',
        'quiz',
        'live_class',
    ];

    private const MANUAL_DURATION_TYPES = [
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

    public function show(
        Request $request,
        int $templateId,
        int $sectionId,
        int $lessonId,
        int $activityId
    ): View {
        return $this->showView(
            $request,
            $templateId,
            $lessonId,
            $activityId,
            $sectionId
        );
    }

    public function showDirect(
        Request $request,
        int $templateId,
        int $lessonId,
        int $activityId
    ): View {
        return $this->showView(
            $request,
            $templateId,
            $lessonId,
            $activityId,
            null
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
            'activityMedia' => collect(),
            'suggestedSortOrder' => $this->nextSortOrder(
                $customerId,
                $templateId,
                $lessonId
            ),
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
        $activityId = DB::transaction(function () use (
            $request,
            $validated,
            $customerId,
            $templateId,
            $lessonId
        ): int {
            $lesson = DB::table('core_course_template_lessons')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('id', $lessonId)
                ->lockForUpdate()
                ->first();

            abort_if(! $lesson, 404);

            $validated['sort_order'] ??= $this->nextSortOrder(
                $customerId,
                $templateId,
                $lessonId
            );
            $now = now();

            return DB::table(
                'core_course_template_activities'
            )->insertGetId($this->activityValues($validated, [
                'customer_id' => $customerId,
                'template_id' => $templateId,
                'template_lesson_id' => $lessonId,
                'created_by' => $request->user()?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        });

        $this->detachInactiveMedia($activityId, $validated['activity_type'], $request);
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
            'activityMedia' => $this->ownerMedia(
                'course_activity',
                $activityId
            ),
            'routePrefix' => $this->routePrefix($request, $sectionId),
            'templateRoutePrefix' => $this->templateRoutePrefix($request),
        ]);
    }

    private function showView(
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

        return view('course-template-activities.show', [
            'template' => $template,
            'section' => $section,
            'lesson' => $lesson,
            'activity' => $activity,
            'externalUrl' => $this->safeExternalUrl(
                $activity->external_video_url ?? $activity->live_class_url
            ),
            'activityMedia' => $this->ownerMedia(
                'course_activity',
                $activityId
            ),
            'routePrefix' => $this->routePrefix($request, $sectionId),
            'templateRoutePrefix' => $this->templateRoutePrefix($request),
        ]);
    }

    private function safeExternalUrl(?string $url): ?string
    {
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            ? $url
            : null;
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

        $this->detachInactiveMedia($activityId, $validated['activity_type'], $request);
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
            $this->validationInput($request),
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

    private function validationInput(Request $request): array
    {
        $fields = [
            'title',
            'description',
            'sort_order',
            'activity_type',
            'external_video_url',
            'live_class_url',
            'assessment_quiz_id',
            'duration_seconds',
            'is_required',
            'completion_rule',
            'completion_threshold',
            'is_preview',
            'unlock_rule',
            'unlock_after_activity_id',
            'unlock_at',
        ];

        $input = array_intersect_key(
            $request->request->all(),
            array_flip($fields)
        );

        $typeField = [
            'embedded_video' => 'external_video_url',
            'live_class' => 'live_class_url',
            'quiz' => 'assessment_quiz_id',
        ][$input['activity_type'] ?? ''] ?? null;
        foreach (['external_video_url', 'live_class_url', 'assessment_quiz_id'] as $field) {
            if ($field !== $typeField) unset($input[$field]);
        }
        if (! in_array($input['completion_rule'] ?? null, ['watch_percent', 'pass'], true)) {
            unset($input['completion_threshold']);
        }
        if (($input['unlock_rule'] ?? null) !== 'previous_activity_completed') unset($input['unlock_after_activity_id']);
        if (($input['unlock_rule'] ?? null) !== 'date_based') unset($input['unlock_at']);

        foreach ([
            'activity_video_file',
            'activity_audio_file',
            'activity_document_file',
        ] as $field) {
            $expectedType = match ($field) {
                'activity_video_file' => 'video',
                'activity_audio_file' => 'audio',
                'activity_document_file' => 'document',
            };
            if ($request->hasFile($field) && ($input['activity_type'] ?? null) === $expectedType) {
                $input[$field] = $request->file($field);
            }
        }

        return $input;
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
            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'activity_type' => [
                'required',
                Rule::in(self::ACTIVITY_TYPES),
            ],
            'external_video_url' => [
                'nullable',
                'url',
                'max:1000',
                'starts_with:https://',
                'required_if:activity_type,embedded_video',
                'prohibited_unless:activity_type,embedded_video',
            ],
            'live_class_url' => ['nullable', 'url', 'max:1000', 'starts_with:https://', 'required_if:activity_type,live_class', 'prohibited_unless:activity_type,live_class'],
            'assessment_quiz_id' => [
                'nullable', 'integer', 'min:1',
                'required_if:activity_type,quiz',
                'prohibited_unless:activity_type,quiz',
                function ($attribute, $value, $fail) use ($customerId): void {
                    if ($value === null) return;
                    if (! Schema::hasTable('core_assessment_quizzes') || ! DB::table('core_assessment_quizzes')->where('customer_id', $customerId)->where('id', $value)->exists()) {
                        $fail(__('validation.exists', ['attribute' => $attribute]));
                    }
                },
            ],
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
                Rule::in(match ($activityType) {
                    'video', 'audio' => ['view', 'watch_percent', 'manual'],
                    'document', 'embedded_video' => ['view', 'manual'],
                    'quiz' => ['submit', 'pass', 'manual'],
                    'live_class' => ['manual'],
                    default => [],
                }),
            ],
            'completion_threshold' => [
                'nullable',
                'integer',
                'min:0',
                'max:100',
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
            'activity_video_file' => [
                'nullable',
                'file',
                'max:'.UploadLimit::effectiveKilobytes(),
            ],
            'activity_audio_file' => [
                'nullable',
                'file',
                'max:'.UploadLimit::effectiveKilobytes(),
            ],
            'activity_document_file' => [
                'nullable',
                'file',
                'max:'.UploadLimit::effectiveKilobytes(),
            ],
            'activity_attachment_file' => [
                'nullable',
                'file',
                'max:'.UploadLimit::effectiveKilobytes(),
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
        return array_merge(array_filter([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? null,
            'activity_type' => $validated['activity_type'],
            'external_video_url' => $validated['external_video_url'] ?? null,
            'live_class_url' => $validated['live_class_url'] ?? null,
            'assessment_quiz_id' => $validated['assessment_quiz_id'] ?? null,
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
        ], fn ($value, $key) => $key !== 'sort_order' || $value !== null, ARRAY_FILTER_USE_BOTH), $extra);
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
            ->orderBy('id')
            ->get();
    }

    private function nextSortOrder(
        int $customerId,
        int $templateId,
        int $lessonId
    ): int {
        return (int) DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('template_lesson_id', $lessonId)
            ->max('sort_order') + 1;
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

    private function detachInactiveMedia(int $activityId, string $activityType, Request $request): void
    {
        $activeUsage = in_array($activityType, ['video', 'audio', 'document'], true)
            ? $activityType
            : null;
        $replacingActiveMedia = $activeUsage !== null
            && $request->hasFile("activity_{$activeUsage}_file");

        DB::table('media_file_usages')
            ->where('customer_id', $this->customerId())
            ->where('owner_type', 'course_activity')
            ->where('owner_id', $activityId)
            ->where('status', 'active')
            ->when($activeUsage && ! $replacingActiveMedia, fn ($query) => $query->where('usage_type', '!=', $activeUsage))
            ->when(! $activeUsage || $replacingActiveMedia, fn ($query) => $query->whereIn('usage_type', ['video', 'audio', 'document']))
            ->get()
            ->each(fn (object $usage) => $this->mediaService->detachUsage(
                (int) $usage->media_file_id,
                'course_activity',
                $activityId,
                $usage->usage_type
            ));
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
