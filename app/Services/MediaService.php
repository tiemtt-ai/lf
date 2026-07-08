<?php

namespace App\Services;

use App\Support\TenantContext;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class MediaService
{
    public function upload(
        UploadedFile $file,
        array $attributes,
        int $uploadedBy
    ): object {
        return $this->uploadMedia($file, $attributes, $uploadedBy);
    }

    public function uploadMedia(
        UploadedFile $file,
        array $attributes,
        int $uploadedBy
    ): object {
        $customerId = $this->customerId();
        $this->assertUploaderBelongsToTenant($customerId, $uploadedBy);

        $attributes = $this->normalizeAttributes($attributes);
        $validated = $this->validateUploadAttributes(
            $customerId,
            $file,
            $attributes
        );

        $extension = $this->normalizedExtension($file);
        $mimeType = (string) $file->getMimeType();
        $this->validateFileContent($validated['file_type'], $mimeType, $extension);
        $fileSizeBytes = $file->getSize() ?: 0;
        $checksum = 'sha256:'.hash_file('sha256', $file->getRealPath());

        $duplicate = $this->findDuplicateMediaFile(
            $customerId,
            $checksum,
            $fileSizeBytes,
            $mimeType
        );

        if ($duplicate) {
            return $duplicate;
        }

        $storageDisk = (string) config('media.disk', 'media_local');
        $storageBucket = (string) config('media.bucket');

        abort_if($storageBucket === '', 500, 'Media storage bucket is not configured.');

        $storageKey = $this->generateStorageKey(
            $customerId,
            $validated['module'],
            $validated['entity_type'],
            (int) $validated['entity_id'],
            $validated['purpose'],
            $extension
        );

        $this->storePrivateObject($storageDisk, $storageKey, $file);

        $now = now();
        $mediaFileId = null;

        try {
            $mediaFileId = DB::table('media_files')->insertGetId([
                'customer_id' => $customerId,
                'category_id' => $validated['category_id'] ?? null,
                'uploaded_by' => $uploadedBy,
                'file_type' => $validated['file_type'],
                'mime_type' => $mimeType,
                'original_name' => $file->getClientOriginalName(),
                'display_name' => $validated['display_name']
                    ?? $file->getClientOriginalName(),
                'extension' => $extension,
                'storage_disk' => $storageDisk,
                'storage_bucket' => $storageBucket,
                'storage_region' => config('media.region'),
                'storage_key' => $storageKey,
                'storage_class' => config('media.storage_class'),
                'cdn_url' => null,
                'public_url' => null,
                'checksum' => $checksum,
                'file_size_bytes' => $fileSizeBytes,
                'duration_seconds' => $validated['duration_seconds'] ?? null,
                'width' => $validated['width'] ?? null,
                'height' => $validated['height'] ?? null,
                'page_count' => $validated['page_count'] ?? null,
                'language' => $validated['language'] ?? null,
                'visibility' => $validated['visibility'] ?? 'private',
                'status' => 'ready',
                'metadata' => isset($validated['metadata'])
                    ? json_encode($validated['metadata'])
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $exception) {
            Storage::disk($storageDisk)->delete($storageKey);

            throw $exception;
        }

        return DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('id', $mediaFileId)
            ->first();
    }

    public function generateSignedUrl(
        int $mediaFileId,
        ?DateTimeInterface $expiresAt = null
    ): string {
        $customerId = $this->customerId();
        $mediaFile = DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('id', $mediaFileId)
            ->first();

        abort_if(! $mediaFile || $mediaFile->status !== 'ready', 404);

        $expiresAt ??= now()->addMinutes(
            (int) config('media.signed_url_ttl_minutes', 10)
        );

        if ($this->shouldUseSignedDeliveryRoute($mediaFile)) {
            return URL::temporarySignedRoute(
                'media.files.signed',
                $expiresAt,
                [
                    'mediaFile' => $mediaFile->id,
                    'expiration' => $expiresAt->getTimestamp(),
                ]
            );
        }

        try {
            return Storage::disk($mediaFile->storage_disk)->temporaryUrl(
                $mediaFile->storage_key,
                $expiresAt
            );
        } catch (Throwable) {
            return URL::temporarySignedRoute(
                'media.files.signed',
                $expiresAt,
                [
                    'mediaFile' => $mediaFile->id,
                    'expiration' => $expiresAt->getTimestamp(),
                ]
            );
        }
    }

    private function shouldUseSignedDeliveryRoute(object $mediaFile): bool
    {
        $disk = config('filesystems.disks.'.$mediaFile->storage_disk);

        return ($disk['driver'] ?? null) === 'local';
    }

    public function attachUsage(
        int $mediaFileId,
        string $ownerType,
        int $ownerId,
        string $usageType,
        array $metadata = []
    ): object {
        $customerId = $this->customerId();
        $this->assertMediaFileBelongsToTenant($customerId, $mediaFileId);
        $usage = $this->validateUsageReference(
            $ownerType,
            $ownerId,
            $usageType,
            $metadata
        );
        $now = now();
        $createdBy = $this->currentTenantUserId($customerId);

        return DB::transaction(function () use (
            $customerId,
            $mediaFileId,
            $usage,
            $now,
            $createdBy
        ): object {
            $existing = DB::table('media_file_usages')
                ->where('customer_id', $customerId)
                ->where('media_file_id', $mediaFileId)
                ->where('owner_type', $usage['owner_type'])
                ->where('owner_id', $usage['owner_id'])
                ->where('usage_type', $usage['usage_type'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                DB::table('media_file_usages')
                    ->where('customer_id', $customerId)
                    ->where('id', $existing->id)
                    ->update([
                        'status' => 'active',
                        'metadata' => $this->encodeMetadata(
                            $usage['metadata']
                        ),
                        'created_by' => $existing->created_by ?? $createdBy,
                        'updated_at' => $now,
                    ]);

                return $this->findUsage($customerId, (int) $existing->id);
            }

            $usageId = DB::table('media_file_usages')->insertGetId([
                'customer_id' => $customerId,
                'media_file_id' => $mediaFileId,
                'owner_type' => $usage['owner_type'],
                'owner_id' => $usage['owner_id'],
                'usage_type' => $usage['usage_type'],
                'status' => 'active',
                'metadata' => $this->encodeMetadata($usage['metadata']),
                'created_by' => $createdBy,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->findUsage($customerId, $usageId);
        });
    }

    public function detachUsage(
        int $mediaFileId,
        string $ownerType,
        int $ownerId,
        string $usageType
    ): object {
        $customerId = $this->customerId();
        $this->assertMediaFileBelongsToTenant($customerId, $mediaFileId);
        $usage = $this->validateUsageReference(
            $ownerType,
            $ownerId,
            $usageType
        );

        $existing = DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('media_file_id', $mediaFileId)
            ->where('owner_type', $usage['owner_type'])
            ->where('owner_id', $usage['owner_id'])
            ->where('usage_type', $usage['usage_type'])
            ->first();

        abort_if(! $existing, 404);

        DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('id', $existing->id)
            ->update([
                'status' => 'detached',
                'updated_at' => now(),
            ]);

        return $this->findUsage($customerId, (int) $existing->id);
    }

    public function getUsages(int $mediaFileId): object
    {
        $customerId = $this->customerId();
        $this->assertMediaFileBelongsToTenant($customerId, $mediaFileId);

        return DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('media_file_id', $mediaFileId)
            ->orderBy('id')
            ->get();
    }

    public function getOwnerMedia(
        string $ownerType,
        int $ownerId,
        ?string $usageType = null
    ): object {
        $customerId = $this->customerId();
        $usage = $this->validateUsageReference(
            $ownerType,
            $ownerId,
            $usageType ?? config('media.usage_types.0', 'attachment')
        );

        $query = DB::table('media_file_usages')
            ->join('media_files', function ($join): void {
                $join->on(
                    'media_files.id',
                    '=',
                    'media_file_usages.media_file_id'
                )->on(
                    'media_files.customer_id',
                    '=',
                    'media_file_usages.customer_id'
                );
            })
            ->where('media_file_usages.customer_id', $customerId)
            ->where('media_file_usages.owner_type', $usage['owner_type'])
            ->where('media_file_usages.owner_id', $usage['owner_id'])
            ->where('media_file_usages.status', 'active');

        if ($usageType !== null) {
            $query->where(
                'media_file_usages.usage_type',
                $usage['usage_type']
            );
        }

        return $query
            ->orderBy('media_file_usages.id')
            ->select([
                'media_files.*',
                'media_file_usages.id as usage_id',
                'media_file_usages.owner_type',
                'media_file_usages.owner_id',
                'media_file_usages.usage_type',
                'media_file_usages.status as usage_status',
                'media_file_usages.metadata as usage_metadata',
                'media_file_usages.created_by as usage_created_by',
            ])
            ->get();
    }

    public function isInUse(int $mediaFileId): bool
    {
        $customerId = $this->customerId();
        $this->assertMediaFileBelongsToTenant($customerId, $mediaFileId);

        return DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('media_file_id', $mediaFileId)
            ->where('status', 'active')
            ->exists();
    }

    public function deleteMedia(int $mediaFileId): object
    {
        $customerId = $this->customerId();
        $mediaFile = $this->assertMediaFileBelongsToTenant(
            $customerId,
            $mediaFileId
        );

        if ($this->isInUse($mediaFileId)) {
            throw ValidationException::withMessages([
                'media_file_id' => __('lf.LF_media_file_delete_blocked_in_use'),
            ]);
        }

        $this->deleteStorageObject($mediaFile);

        DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('id', $mediaFile->id)
            ->update([
                'status' => 'deleted',
                'updated_at' => now(),
            ]);

        return DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('id', $mediaFile->id)
            ->first();
    }

    private function deleteStorageObject(object $mediaFile): void
    {
        $disk = Storage::disk($mediaFile->storage_disk);

        try {
            if (! $disk->exists($mediaFile->storage_key)) {
                return;
            }

            if ($disk->delete($mediaFile->storage_key) === false) {
                Log::warning('Media storage object delete returned false.', [
                    'media_file_id' => $mediaFile->id,
                    'customer_id' => $mediaFile->customer_id,
                    'storage_disk' => $mediaFile->storage_disk,
                    'storage_key' => $mediaFile->storage_key,
                ]);

                throw ValidationException::withMessages([
                    'media_file_id' => __('lf.LF_media_file_delete_storage_failed'),
                ]);
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Media storage object delete failed.', [
                'media_file_id' => $mediaFile->id,
                'customer_id' => $mediaFile->customer_id,
                'storage_disk' => $mediaFile->storage_disk,
                'storage_key' => $mediaFile->storage_key,
                'exception' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'media_file_id' => __('lf.LF_media_file_delete_storage_failed'),
            ]);
        }
    }

    private function validateUploadAttributes(
        int $customerId,
        UploadedFile $file,
        array $attributes
    ): array {
        $validator = Validator::make(
            array_merge($attributes, ['file' => $file]),
            [
                'file' => [
                    'required',
                    'file',
                    'max:'.(int) config('media.max_upload_kilobytes', 102400),
                ],
                'category_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('media_categories', 'id')
                        ->where('customer_id', $customerId),
                ],
                'file_type' => [
                    'required',
                    Rule::in(config('media.file_types', [])),
                ],
                'module' => [
                    'required',
                    'string',
                    'max:100',
                    'regex:/^[a-z0-9_-]+$/',
                ],
                'entity_type' => [
                    'required',
                    'string',
                    'max:100',
                    'regex:/^[a-z0-9_-]+$/',
                ],
                'entity_id' => ['required', 'integer', 'min:1'],
                'purpose' => [
                    'required',
                    'string',
                    'max:100',
                    'regex:/^[a-z0-9_-]+$/',
                ],
                'display_name' => ['nullable', 'string', 'max:255'],
                'duration_seconds' => ['nullable', 'integer', 'min:0'],
                'width' => ['nullable', 'integer', 'min:0'],
                'height' => ['nullable', 'integer', 'min:0'],
                'page_count' => ['nullable', 'integer', 'min:0'],
                'language' => ['nullable', 'string', 'max:20'],
                'visibility' => [
                    'nullable',
                    Rule::in(config('media.visibility', [])),
                ],
                'metadata' => ['nullable', 'array'],
            ]
        );

        return $validator->validate();
    }

    private function validateUsageReference(
        string $ownerType,
        int $ownerId,
        string $usageType,
        array $metadata = []
    ): array {
        $validator = Validator::make(
            [
                'owner_type' => Str::lower(trim($ownerType)),
                'owner_id' => $ownerId,
                'usage_type' => Str::lower(trim($usageType)),
                'metadata' => $metadata,
            ],
            [
                'owner_type' => [
                    'required',
                    Rule::in(config('media.owner_types', [])),
                ],
                'owner_id' => ['required', 'integer', 'min:1'],
                'usage_type' => [
                    'required',
                    Rule::in(config('media.usage_types', [])),
                ],
                'metadata' => ['array'],
            ]
        );

        return $validator->validate();
    }

    private function validateFileContent(
        string $fileType,
        string $mimeType,
        ?string $extension
    ): void {
        $allowed = [
            'image' => [
                'mimes' => [
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'image/webp',
                    'image/svg+xml',
                ],
                'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
            ],
            'audio' => [
                'mimes' => [
                    'audio/mpeg',
                    'audio/mp3',
                    'audio/wav',
                    'audio/x-wav',
                    'audio/ogg',
                    'audio/webm',
                    'audio/mp4',
                ],
                'extensions' => ['mp3', 'wav', 'ogg', 'webm', 'm4a', 'aac'],
            ],
            'video' => [
                'mimes' => [
                    'video/mp4',
                    'video/webm',
                    'video/quicktime',
                    'video/x-msvideo',
                ],
                'extensions' => ['mp4', 'webm', 'mov', 'avi'],
            ],
            'document' => [
                'mimes' => [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-powerpoint',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'text/plain',
                ],
                'extensions' => [
                    'pdf',
                    'doc',
                    'docx',
                    'xls',
                    'xlsx',
                    'ppt',
                    'pptx',
                    'txt',
                ],
            ],
            'subtitle' => [
                'mimes' => ['text/vtt', 'application/x-subrip', 'text/plain'],
                'extensions' => ['vtt', 'srt', 'txt'],
            ],
            'transcript' => [
                'mimes' => ['text/plain', 'application/json'],
                'extensions' => ['txt', 'json'],
            ],
            'archive' => [
                'mimes' => [
                    'application/zip',
                    'application/x-zip-compressed',
                    'application/x-tar',
                    'application/gzip',
                ],
                'extensions' => ['zip', 'tar', 'gz'],
            ],
        ];

        if ($fileType === 'other') {
            return;
        }

        $rules = $allowed[$fileType] ?? null;

        if (
            ! $rules
            || ! in_array($mimeType, $rules['mimes'], true)
            || ! $extension
            || ! in_array($extension, $rules['extensions'], true)
        ) {
            throw ValidationException::withMessages([
                'file' => __('validation.mimes', [
                    'attribute' => 'file',
                    'values' => implode(', ', $rules['extensions'] ?? []),
                ]),
            ]);
        }
    }

    private function normalizeAttributes(array $attributes): array
    {
        foreach (['module', 'entity_type', 'purpose', 'file_type'] as $field) {
            if (isset($attributes[$field])) {
                $attributes[$field] = Str::lower(trim((string) $attributes[$field]));
            }
        }

        return $attributes;
    }

    private function generateStorageKey(
        int $customerId,
        string $module,
        string $entityType,
        int $entityId,
        string $purpose,
        ?string $extension
    ): string {
        return sprintf(
            'tenants/%d/%s/%s/%d/%s/%s.%s',
            $customerId,
            $module,
            $entityType,
            $entityId,
            $purpose,
            (string) Str::ulid(),
            $extension ?: 'bin'
        );
    }

    private function normalizedExtension(UploadedFile $file): ?string
    {
        $extension = $file->getClientOriginalExtension()
            ?: $file->guessExtension();

        if ($extension === '') {
            return null;
        }

        return Str::lower($extension);
    }

    private function storePrivateObject(
        string $storageDisk,
        string $storageKey,
        UploadedFile $file
    ): void {
        $stream = fopen($file->getRealPath(), 'r');

        try {
            Storage::disk($storageDisk)->put($storageKey, $stream, [
                'visibility' => 'private',
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function findDuplicateMediaFile(
        int $customerId,
        string $checksum,
        int $fileSizeBytes,
        string $mimeType
    ): ?object {
        return DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('checksum', $checksum)
            ->where('file_size_bytes', $fileSizeBytes)
            ->where('mime_type', $mimeType)
            ->whereNotIn('status', ['deleted', 'failed'])
            ->orderBy('id')
            ->first();
    }

    private function assertMediaFileBelongsToTenant(
        int $customerId,
        int $mediaFileId
    ): object {
        $mediaFile = DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('id', $mediaFileId)
            ->first();

        abort_if(! $mediaFile, 404);

        return $mediaFile;
    }

    private function findUsage(int $customerId, int $usageId): object
    {
        return DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('id', $usageId)
            ->first();
    }

    private function encodeMetadata(array $metadata): ?string
    {
        if ($metadata === []) {
            return null;
        }

        return json_encode($metadata);
    }

    private function currentTenantUserId(int $customerId): ?int
    {
        $user = auth()->user();

        if (! $user || (int) $user->customer_id !== $customerId) {
            return null;
        }

        return (int) $user->id;
    }

    private function assertUploaderBelongsToTenant(
        int $customerId,
        int $uploadedBy
    ): void {
        $exists = DB::table('users')
            ->where('customer_id', $customerId)
            ->where('id', $uploadedBy)
            ->exists();

        abort_if(! $exists, 404);
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        return $customerId;
    }
}
