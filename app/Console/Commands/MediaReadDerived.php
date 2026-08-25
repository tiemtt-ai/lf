<?php

namespace App\Console\Commands;

use App\Exceptions\MediaReadException;
use App\Services\MediaReadService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MediaReadDerived extends Command
{
    /** LF-Media-Read-Contract § 3: a consumer addresses owner context, never a media_file_id. */
    private const OWNER_TYPES = ['course_activity', 'course_version_activity'];

    /** LF-Media-Read-Contract § 5. */
    private const CONTENT_TYPES = ['extracted_text', 'transcript', 'caption_asset', 'variant'];

    protected $signature = 'media:read-derived
        {--customer= : Tenant saas_customers.id resolved into the tenant context}
        {--actor= : users.id the read is performed as; the contract forbids an implicit actor}
        {--owner-type= : course_activity or course_version_activity}
        {--owner-id= : Owner record the media is attached to}
        {--content-type= : extracted_text, transcript, caption_asset or variant}
        {--locale= : BCP 47 locale; defaults to the canonical media_files.processing_locale}
        {--processing-version= : Read this exact revision, including an archived one}
        {--source-fingerprint= : Require the revision to be built from this source content}
        {--consumer=console : Value recorded as media_access_logs.source_type}
        {--format=text : Output format: text or json}';

    protected $description = 'Read derived content units through the Media Read Service, per LF-Media-Read-Contract.';

    public function handle(MediaReadService $reader): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('Option --format must be text or json.');

            return self::FAILURE;
        }

        $customerId = $this->positiveOption('customer');
        $actorId = $this->positiveOption('actor');
        $ownerId = $this->positiveOption('owner-id');
        $ownerType = (string) $this->option('owner-type');
        $contentType = (string) $this->option('content-type');

        foreach (['customer' => $customerId, 'actor' => $actorId, 'owner-id' => $ownerId] as $name => $value) {
            if ($value === null) {
                $this->error("Option --{$name} is required and must be a positive integer id.");

                return self::FAILURE;
            }
        }
        if (! in_array($ownerType, self::OWNER_TYPES, true)) {
            $this->error('Option --owner-type must be one of: '.implode(', ', self::OWNER_TYPES).'.');

            return self::FAILURE;
        }
        if (! in_array($contentType, self::CONTENT_TYPES, true)) {
            $this->error('Option --content-type must be one of: '.implode(', ', self::CONTENT_TYPES).'.');

            return self::FAILURE;
        }

        $customer = DB::table('saas_customers')->where('id', $customerId)->first();
        if (! $customer) {
            $this->error("Customer {$customerId} does not exist.");

            return self::FAILURE;
        }
        TenantContext::set($customer);

        try {
            $units = $reader->read(
                $actorId,
                $ownerType,
                $ownerId,
                $contentType,
                $this->stringOption('locale'),
                $this->stringOption('processing-version'),
                $this->stringOption('source-fingerprint'),
                (string) $this->option('consumer'),
            );
        } catch (MediaReadException $exception) {
            // The contract's error codes are the interface; they are never flattened
            // into an empty result set.
            $this->renderError($format, $exception->errorCode);

            return self::FAILURE;
        } catch (InvalidArgumentException) {
            // A malformed --locale is a bad argument, not a contract outcome, so it
            // must not borrow one of the contract's error codes.
            $this->error('Option --locale must be a valid BCP 47 locale.');

            return self::FAILURE;
        }

        $this->renderUnits($format, $units);

        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $units */
    private function renderUnits(string $format, array $units): void
    {
        if ($format === 'json') {
            $this->line((string) json_encode(
                ['decision' => 'allowed', 'units' => $units],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            return;
        }

        $this->line(sprintf('%d unit(s).', count($units)));
        foreach ($units as $unit) {
            $locator = $unit['locator'] === null ? 'none' : $unit['locator']['type'].'='.$unit['locator']['value'];
            $this->line(sprintf(
                '- media_file_id=%d locator=%s locale=%s processing_version=%s source_fingerprint=%s status=%s',
                $unit['media_file_id'], $locator, $unit['locale'] ?? 'none',
                $unit['processing_version'], $unit['source_fingerprint'], $unit['status']
            ));
            if ($unit['delivery_url'] !== null) {
                $this->line('  delivery_url: '.$unit['delivery_url']);
            }
            if ($unit['text'] !== null) {
                $text = (string) $unit['text'];
                $this->line(sprintf('  text (%d chars): %s', mb_strlen($text), mb_substr($text, 0, 200)));
                if (mb_strlen($text) > 200) {
                    $this->line('  (truncated; use --format=json for the full unit)');
                }
            }
        }
    }

    private function renderError(string $format, string $errorCode): void
    {
        if ($format === 'json') {
            $this->line((string) json_encode(['decision' => 'denied', 'error_code' => $errorCode], JSON_PRETTY_PRINT));

            return;
        }
        $this->error($errorCode);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function positiveOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_scalar($value) && preg_match('/^[1-9][0-9]*$/', (string) $value) === 1 ? (int) $value : null;
    }
}
