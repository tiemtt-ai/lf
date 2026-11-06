{{-- One media file of a version snapshot (question or passage). --}}
@if ($url)
    @if ($media['media_role'] === 'prompt_image')
        <img class="ui-passage-media ui-result-media" src="{{ $url }}" alt="">
    @elseif ($media['media_role'] === 'prompt_audio')
        <audio class="ui-passage-media ui-result-media" controls preload="metadata" src="{{ $url }}"></audio>
    @else
        <video class="ui-passage-media ui-result-media" controls preload="metadata" src="{{ $url }}"></video>
    @endif
@else
    <p class="ui-caption">{{ __('lf.LF_assessment_passage_media_processing') }}</p>
@endif
