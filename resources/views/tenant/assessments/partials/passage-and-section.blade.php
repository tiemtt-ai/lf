{{-- Section title (when the paper has several) and the shared passage, each shown before its first question. --}}
@if ($sections->count() > 1 && $question->section_key !== ($lastSection ?? null))
    @php $section = $sections->get($question->section_key); @endphp
    <div class="lf-exam-section">
        <h2>{{ $section['title'] ?? '' }}</h2>
        @if (filled($section['description'] ?? null))
            <p>{{ $section['description'] }}</p>
        @endif
    </div>
@endif
@if ($question->passage_id && $question->passage_id !== ($lastPassage ?? null))
    @php $passage = $passages->get((string) $question->passage_id); @endphp
    @if ($passage)
        <div class="lf-exam-passage">
            <p class="lf-exam-passage__title">{{ $passage['title'] }}</p>
            @foreach ($passage['media'] ?? [] as $media)
                @include('tenant.assessments.partials.media', ['media' => $media, 'url' => $mediaUrls[$media['media_file_id']] ?? null])
            @endforeach
            @if (filled($passage['content'] ?? null))
                <p class="lf-exam-text">{{ $passage['content'] }}</p>
            @endif
        </div>
    @endif
@endif
