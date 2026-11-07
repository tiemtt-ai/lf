{{--
    Static sketch of the learner's paper (ADR-0025 D4/D6): the passage shows once, right above its first question,
    and its questions follow; a question outside the passage comes after as usual. Nothing here is real data.
--}}
<details class="ui-passage-demo">
    <summary class="cursor-interaction">
        <x-backend-icon name="eye" class="ui-icon" />
        {{ __('lf.LF_assessment_passage_demo_toggle') }}
    </summary>
    <div class="ui-passage-demo__body">
        <div class="ui-passage-demo__paper" role="img" aria-label="{{ __('lf.LF_assessment_passage_demo_caption') }}">
            <div class="ui-passage-demo__group">
                <span class="ui-passage-demo__mark" aria-hidden="true">1</span>
                <div class="ui-passage-demo__passage">
                    <b>{{ __('lf.LF_assessment_passage_demo_title') }}</b>
                    <span class="ui-passage-demo__media">
                        <x-backend-icon name="audio" class="ui-icon" />
                        <span class="ui-passage-demo__bar"></span>
                        <small>{{ __('lf.LF_assessment_passage_demo_media') }}</small>
                    </span>
                    <span>{{ __('lf.LF_assessment_passage_demo_text') }}</span>
                </div>
                <span class="ui-passage-demo__mark" aria-hidden="true">2</span>
                @foreach ([1, 2] as $n)
                    <div class="ui-passage-demo__question">
                        <b>{{ __('lf.LF_assessment_passage_demo_q'.$n) }}</b>
                        @foreach (['a', 'b'] as $option)
                            <span class="ui-passage-demo__option"><i aria-hidden="true"></i>{{ __('lf.LF_assessment_passage_demo_q'.$n.'_'.$option) }}</span>
                        @endforeach
                    </div>
                @endforeach
            </div>
            <span class="ui-passage-demo__mark" aria-hidden="true">3</span>
            <div class="ui-passage-demo__question">
                <b>{{ __('lf.LF_assessment_passage_demo_q3') }}</b>
                <span class="ui-passage-demo__option"><i aria-hidden="true"></i>…</span>
            </div>
        </div>
        <ol class="ui-passage-demo__notes">
            @foreach (['passage', 'children', 'next'] as $i => $note)
                <li><span class="ui-passage-demo__mark" aria-hidden="true">{{ $i + 1 }}</span><span>{{ __('lf.LF_assessment_passage_demo_note_'.$note) }}</span></li>
            @endforeach
        </ol>
    </div>
</details>
