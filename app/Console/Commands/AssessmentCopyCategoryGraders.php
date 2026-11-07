<?php

namespace App\Console\Commands;

use App\Services\Assessment\AssessmentGraderService;
use Illuminate\Console\Command;

/**
 * ADR-0027 Amendment 1.2: graders are no longer named on categories. Run once before releasing that change, so every
 * teacher named on a category (or a parent of it) is named on each quiz of it and keeps the right. Safe to run again.
 */
class AssessmentCopyCategoryGraders extends Command
{
    protected $signature = 'assessment:copy-category-graders
        {--customer= : Chỉ một tenant}
        {--dry-run : Chỉ báo số dòng, không ghi}';

    protected $description = 'Chép giáo viên chấm theo danh mục thành giáo viên chấm của từng đề (ADR-0027 Amendment 1.2).';

    public function handle(AssessmentGraderService $graders): int
    {
        $customer = $this->option('customer') === null ? null : (int) $this->option('customer');
        $dryRun = (bool) $this->option('dry-run');
        $result = $graders->copyCategoryScopes($customer, $dryRun);

        $this->info(sprintf('%d dòng gán theo danh mục; %s %d dòng gán theo đề.',
            $result['categories'], $dryRun ? 'sẽ thêm' : 'đã thêm', $result['added']));

        return self::SUCCESS;
    }
}
