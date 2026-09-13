<?php

namespace App\Console\Commands;

use App\Exceptions\AiKnowledgeIngestionException;
use App\Exceptions\MediaReadException;
use App\Services\AiKnowledgeIngestionService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AiKnowledgePrepare extends Command
{
    protected $signature = 'ai:knowledge-prepare
        {--customer= : Tenant ID}
        {--actor= : User ID whose Media access is checked}
        {--owner-type= : course_activity or course_version_activity}
        {--owner-id= : Activity ID}
        {--usage-type= : document, audio or video}
        {--content-type= : extracted_text, region, table, formula, transcript or video_frame_text}
        {--title= : Knowledge source title}
        {--locale= : Optional Media locale}
        {--language-profile= : Optional exact locale set, for example vi,ko}';

    protected $description = 'Prepare Knowledge Source and Chunks through authorized Media Read; no embedding/provider calls.';

    public function handle(AiKnowledgeIngestionService $ingestion): int
    {
        $ids = [];
        foreach (['customer', 'actor', 'owner-id'] as $name) {
            $value = filter_var($this->option($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($value === false) {
                $this->error("Option --{$name} must be a positive integer.");

                return self::FAILURE;
            }
            $ids[$name] = $value;
        }

        $ownerType = (string) $this->option('owner-type');
        $usageType = (string) $this->option('usage-type');
        $contentType = (string) $this->option('content-type');
        $title = trim((string) $this->option('title'));
        $pairs = [
            'document' => ['extracted_text', 'region', 'table', 'formula'],
            'audio' => ['transcript'],
            'video' => ['transcript', 'video_frame_text'],
        ];
        if (! in_array($ownerType, ['course_activity', 'course_version_activity'], true)
            || ! in_array($contentType, $pairs[$usageType] ?? [], true)
            || $title === '' || mb_strlen($title) > 255) {
            $this->error('Supply a supported owner/content pair and a title of 1–255 characters.');

            return self::FAILURE;
        }

        $customer = DB::table('saas_customers')->where('id', $ids['customer'])->first();
        if ($customer === null || ! DB::table('users')->where('id', $ids['actor'])
            ->where('customer_id', $ids['customer'])->exists()) {
            $this->error('Customer or tenant actor is invalid.');

            return self::FAILURE;
        }

        $previous = TenantContext::customer();
        TenantContext::set($customer);
        try {
            $result = $ingestion->ingestMedia(
                $ids['actor'], $ownerType, $ids['owner-id'], $usageType, $contentType,
                $this->option('locale') ?: null, $title,
                $this->option('language-profile') ?: null,
            );
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (AiKnowledgeIngestionException|MediaReadException $exception) {
            $this->error($exception->errorCode);

            return self::FAILURE;
        } catch (InvalidArgumentException) {
            $this->error('Invalid locale or language profile.');

            return self::FAILURE;
        } finally {
            TenantContext::set($previous);
        }
    }
}
