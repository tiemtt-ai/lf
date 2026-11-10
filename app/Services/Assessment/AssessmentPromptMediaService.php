<?php

namespace App\Services\Assessment;

use App\Models\User;
use App\Services\MediaService;
use App\Services\MediaShareService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prompt media of a question or a passage (core_assessment_question_media, ADR-0025 D4). Media Domain owns the file;
 * this keeps one row per role and the matching media_file_usages row (owner `assessment_question` or
 * `assessment_passage`). Phase 1: a question has one prompt image and one prompt audio, a passage also one video,
 * each uploaded in the form or picked from the Media library (ADR-0021: a teacher only their own or shared Media).
 */
final class AssessmentPromptMediaService
{
    public const TABLE = 'core_assessment_question_media';

    /** kind => owner column, media owner type, upload entity, media_role => [file_type, usage_type]. */
    private const KINDS = [
        'question' => [
            'column' => 'question_id',
            'owner_type' => 'assessment_question',
            'entity' => 'questions',
            'roles' => ['prompt_image' => ['image', 'image'], 'prompt_audio' => ['audio', 'audio']],
        ],
        'passage' => [
            'column' => 'passage_id',
            'owner_type' => 'assessment_passage',
            'entity' => 'passages',
            'roles' => ['prompt_image' => ['image', 'image'], 'prompt_audio' => ['audio', 'audio'], 'prompt_video' => ['video', 'video']],
        ],
    ];

    public function __construct(
        private readonly MediaService $media,
        private readonly MediaShareService $shares,
    ) {}

    /** @return list<string> */
    public static function roles(string $kind): array
    {
        return array_keys(self::KINDS[$kind]['roles']);
    }

    /**
     * Media picked from the library, checked before anything is saved: a ready Media of the tenant with the role's file
     * type that the user may use (an admin: any; a teacher: their own or one shared with them). A role that also has an
     * uploaded file ignores the pick.
     *
     * @param  array<string, mixed>  $picked  role => media_file_id from the form
     * @param  array<string, UploadedFile|null>  $files  role => uploaded file
     * @return array<string, int> role => media_file_id
     */
    public function libraryChoices(string $kind, int $customerId, User $user, array $picked, array $files = []): array
    {
        $choices = [];

        foreach (self::KINDS[$kind]['roles'] as $role => [$fileType]) {
            $mediaId = (int) ($picked[$role] ?? 0);

            if ($mediaId <= 0 || ($files[$role] ?? null)) {
                continue;
            }

            $usable = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaId)
                ->where('file_type', $fileType)->where('status', 'ready')->exists()
                && ($user->role !== 'teacher'
                    || $this->shares->visibleToTeacher($customerId, (int) $user->id)->where('media_files.id', $mediaId)->exists());

            if (! $usable) {
                throw ValidationException::withMessages([$role.'_file' => __('lf.LF_assessment_media_pick_invalid')]);
            }

            $choices[$role] = $mediaId;
        }

        return $choices;
    }

    /**
     * Applies the form: a new file or a Media picked from the library replaces the role's current media (the new file is
     * uploaded first, so a rejected one leaves the current media in place), a removal mark only detaches it.
     *
     * @param  array<string, UploadedFile|null>  $files  role => uploaded file
     * @param  array<string, bool>  $remove  role => remove the saved one
     * @param  array<string, int>  $library  role => media_file_id checked by libraryChoices()
     */
    public function sync(string $kind, int $customerId, int $userId, int $ownerId, array $files, array $remove, string $displayName, array $library = []): void
    {
        $config = self::KINDS[$kind];

        foreach ($config['roles'] as $role => [$fileType, $usageType]) {
            $file = $files[$role] ?? null;
            $libraryId = $file ? null : ($library[$role] ?? null);

            if (! $file && ! $libraryId && ! ($remove[$role] ?? false)) {
                continue;
            }

            if (! $file && ! $libraryId) {
                $this->detachRole($kind, $customerId, $ownerId, $role);

                continue;
            }

            // Upload (and validate) the new file before the current one is detached: a rejected file keeps the saved media.
            $mediaFileId = $libraryId ?? (int) $this->media->upload($file, [
                'file_type' => $fileType,
                'module' => 'assessment',
                'entity_type' => $config['entity'],
                'entity_id' => $ownerId,
                'purpose' => $role,
                'display_name' => $displayName,
            ], $userId)->id;

            DB::transaction(function () use ($kind, $config, $customerId, $userId, $ownerId, $role, $fileType, $usageType, $mediaFileId, $libraryId): void {
                if ($libraryId) {
                    // The pick was checked before saving; check it again under the share lock so a revocation committed
                    // meanwhile is seen and the new use cannot slip past it (ADR-0021 D7).
                    $actor = User::query()->where('customer_id', $customerId)->find($userId);
                    $locked = $actor ? $this->shares->lockForAttach($customerId, (int) $mediaFileId, $actor) : null;
                    if (! $locked || $locked->file_type !== $fileType || $locked->status !== 'ready') {
                        throw ValidationException::withMessages([$role.'_file' => __('lf.LF_assessment_media_pick_invalid')]);
                    }
                }
                $this->detachRole($kind, $customerId, $ownerId, $role);
                $this->media->attachUsage($mediaFileId, $config['owner_type'], $ownerId, $usageType);
                DB::table(self::TABLE)->insert([
                    'customer_id' => $customerId,
                    $config['column'] => $ownerId,
                    'media_file_id' => $mediaFileId,
                    'media_role' => $role,
                    'sort_order' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }
    }

    /** Before an owner is deleted: its media rows cascade, but the usages must be released explicitly. */
    public function detachAll(string $kind, int $customerId, int $ownerId): void
    {
        foreach (self::roles($kind) as $role) {
            $this->detachRole($kind, $customerId, $ownerId, $role);
        }
    }

    /** @return Collection<string, object> role => media_files row (with media_role) */
    public function forOwner(string $kind, int $customerId, int $ownerId): Collection
    {
        return DB::table(self::TABLE.' as qm')
            ->join('media_files as mf', function ($join): void {
                $join->on('mf.id', '=', 'qm.media_file_id')->on('mf.customer_id', '=', 'qm.customer_id');
            })
            ->where('qm.customer_id', $customerId)
            ->where('qm.'.self::KINDS[$kind]['column'], $ownerId)
            ->get(['mf.*', 'qm.media_role'])
            ->keyBy('media_role');
    }

    private function detachRole(string $kind, int $customerId, int $ownerId, string $role): void
    {
        $config = self::KINDS[$kind];
        $rows = DB::table(self::TABLE)
            ->where('customer_id', $customerId)->where($config['column'], $ownerId)->where('media_role', $role)
            ->get(['id', 'media_file_id']);

        foreach ($rows as $row) {
            DB::transaction(function () use ($config, $customerId, $ownerId, $role, $row): void {
                $this->media->detachUsage((int) $row->media_file_id, $config['owner_type'], $ownerId, $config['roles'][$role][1]);
                DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $row->id)->delete();
            });
        }
    }
}
