<?php

namespace App\Support\AiDemo;

use App\Contracts\Ai\AuthoringProposalProvider;
use App\Support\Ai\AuthoringProposalInput;

/**
 * A stand-in authoring model for the local demo command. It answers from fixed
 * text and never leaves the process: no network, no credential, no real model.
 * The output is deliberately varied (every kind, both mapping modes, confidence
 * from low to high, long text) so the review UI shows every shape it must handle.
 */
final class DemoAuthoringProposalProvider implements AuthoringProposalProvider
{
    public const PROVIDER = 'demo-local-provider';

    public const MODEL = 'demo-authoring-model';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function supportsModel(string $model): bool
    {
        return $model === self::MODEL;
    }

    public function propose(string $model, AuthoringProposalInput $input): string
    {
        $kinds = $input->requestedKinds;
        $items = [];
        $add = static function (array $item) use (&$items): void {
            $items[] = $item + ['source_refs' => [1]];
        };

        if (in_array('summary', $kinds, true)) {
            $add(['kind' => 'summary', 'title' => 'Tóm tắt bài học', 'confidence' => 0.86,
                'body' => "Bài học giới thiệu khái niệm phân số, cách đọc và cách so sánh hai phân số.\n\nHọc viên làm quen với tử số, mẫu số và ý nghĩa của phần bằng nhau.",
                'rationale' => 'Nêu lại nội dung chính của đoạn nguồn đầu tiên.']);
        }
        if (in_array('concept', $kinds, true)) {
            $add(['kind' => 'concept', 'title' => 'Phân số', 'confidence' => 0.78,
                'body' => 'Phân số biểu thị một hoặc nhiều phần bằng nhau của một đơn vị.',
                'rationale' => 'Khái niệm xuất hiện lặp lại trong tài liệu.']);
            $add(['kind' => 'concept', 'title' => 'Tử số và mẫu số', 'confidence' => 0.41,
                'body' => 'Mẫu số cho biết đơn vị được chia thành bao nhiêu phần; tử số cho biết lấy bao nhiêu phần.',
                'rationale' => 'Suy ra từ ví dụ minh hoạ, chưa có định nghĩa rõ trong nguồn.']);
        }
        if (in_array('learning_objective', $kinds, true)) {
            $add(['kind' => 'learning_objective', 'title' => 'Đọc và viết được phân số đơn giản', 'confidence' => 0.7,
                'body' => 'Sau bài học, học viên đọc, viết và biểu diễn được các phân số có mẫu số nhỏ hơn 10.',
                'rationale' => 'Phù hợp với các ví dụ và bài tập trong hoạt động.']);
        }
        if (in_array('competency', $kinds, true) && $input->frameworkBasis !== null) {
            $add(['kind' => 'competency', 'title' => 'So sánh hai phân số', 'confidence' => 0.64,
                'body' => 'Học viên so sánh được hai phân số cùng mẫu số và khác mẫu số.',
                'rationale' => 'Bài tập cuối tài liệu yêu cầu so sánh.']);
        }
        if (in_array('node_mapping', $kinds, true) && $input->frameworkBasis !== null) {
            $candidate = $input->frameworkBasis['candidates'][0] ?? null;
            if ($candidate !== null) {
                $add(['kind' => 'node_mapping', 'title' => 'Liên kết với năng lực đã có', 'confidence' => 0.58,
                    'body' => 'Hoạt động luyện tập năng lực đã có trong bộ chuẩn.',
                    'rationale' => 'Nội dung khớp tiêu chí của năng lực này.',
                    'mapping' => ['mode' => 'reuse_existing', 'node_id' => $candidate['node_id'],
                        'definition_id' => $candidate['definition_id'], 'role' => 'practices', 'weight' => null]]);
            }
            $add(['kind' => 'node_mapping', 'title' => 'Đề xuất năng lực mới', 'confidence' => 0.52,
                'body' => 'Bộ chuẩn chưa có năng lực về quy đồng mẫu số.',
                'rationale' => 'Hoạt động dạy nội dung mà bộ chuẩn hiện chưa phủ.',
                'mapping' => ['mode' => 'propose_new', 'code' => 'QUY-DONG', 'label' => 'Quy đồng mẫu số',
                    'node_type' => 'competency', 'criteria' => null, 'role' => 'teaches', 'weight' => 0.5]]);
        }

        return json_encode(['items' => $items], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
