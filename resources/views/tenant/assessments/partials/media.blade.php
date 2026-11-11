{{-- One media file of the version snapshot (question or passage). --}}
@if ($url)
    @if ($media['media_role'] === 'prompt_image')
        <img class="lf-exam-media" data-exam-media="{{ $media['media_file_id'] }}" src="{{ $url }}" alt="" loading="lazy">
    @elseif ($media['media_role'] === 'prompt_audio')
        <audio class="lf-exam-media" data-exam-media="{{ $media['media_file_id'] }}" controls preload="metadata" src="{{ $url }}"></audio>
    @else
        <video class="lf-exam-media" data-exam-media="{{ $media['media_file_id'] }}" controls preload="metadata" src="{{ $url }}"></video>
    @endif
@else
    <p class="student-section-copy">{{ __('lf.LF_assessment_passage_media_processing') }}</p>
@endif
