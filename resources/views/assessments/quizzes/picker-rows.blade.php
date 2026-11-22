@php $searchText = fn (...$parts) => mb_strtolower(implode(' ', array_filter($parts))); @endphp
{{-- Rows of the Thêm câu hỏi popup: on opening, and the matches of the whole bank its filter appends to a cut list. --}}
@if ($kind === 'passages')
    @foreach ($rows as $passage)
        @php $text = $searchText($passage->title, $passage->category_name); @endphp
        <label class="ui-pick-row cursor-interaction" data-search="{{ $text }}" x-show="show($el.dataset.search)">
            <input type="checkbox" class="ui-bulk-box" name="passage_ids[]" value="{{ $passage->id }}">
            <span>
                <span class="ui-passage-prompt">{{ $passage->title }}</span>
                <span class="ui-caption">{{ $passage->skill_type ? __('lf.LF_assessment_question_skill_'.$passage->skill_type) : __('lf.LF_assessment_question_not_set') }} · {{ $passage->category_name ?? __('lf.LF_assessment_question_no_category') }}</span>
            </span>
            <span class="ui-caption">{{ trans_choice('lf.LF_assessment_quiz_picker_ready_count', (int) $passage->ready_count, ['count' => $passage->ready_count]) }}</span>
        </label>
    @endforeach
@else
    @foreach ($rows as $question)
        @php $text = $searchText($question->prompt, $question->category_name, __('lf.LF_assessment_question_type_'.$question->question_type)); @endphp
        <label class="ui-pick-row cursor-interaction" data-search="{{ $text }}" x-show="show($el.dataset.search)">
            <input type="checkbox" class="ui-bulk-box" name="question_ids[]" value="{{ $question->id }}">
            <span>
                <span class="ui-passage-prompt">{{ $question->prompt }}</span>
                <span class="ui-caption">{{ __('lf.LF_assessment_question_type_'.$question->question_type) }} · {{ $question->category_name ?? __('lf.LF_assessment_question_no_category') }}</span>
            </span>
            <span class="ui-caption">{{ __('lf.LF_assessment_quiz_points_value', ['points' => rtrim(rtrim(number_format((float) $question->default_points, 2, '.', ''), '0'), '.')]) }}</span>
        </label>
    @endforeach
@endif
