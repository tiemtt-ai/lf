@extends('layouts.backend')

@section('title', __('lf.LF_teacher_title_teacher_dashboard'))
@section('page_title', __('lf.LF_navigation_menu_teacher_dashboard'))

@section('content')
    <p class="admin-dashboard-welcome">
        {{ __('lf.LF_teacher_message_teacher_welcome', ['name' => auth()->user()->name]) }}
    </p>

    <div class="teacher-dashboard-grid">
        @foreach ([
            ['label' => __('lf.LF_teacher_title_teacher_my_courses'), 'value' => '--', 'copy' => __('lf.LF_teacher_card_teacher_courses')],
            ['label' => __('lf.LF_teacher_title_teacher_students'), 'value' => '--', 'copy' => __('lf.LF_teacher_card_teacher_students')],
            ['label' => __('lf.LF_teacher_title_teacher_pending_gradings'), 'value' => '--', 'copy' => __('lf.LF_teacher_card_teacher_pending_gradings')],
            ['label' => __('lf.LF_teacher_title_teacher_upcoming_classes'), 'value' => '--', 'copy' => __('lf.LF_teacher_card_teacher_upcoming_classes')],
            ['label' => __('lf.LF_teacher_title_teacher_reports'), 'value' => '--', 'copy' => __('lf.LF_teacher_card_teacher_reports')],
            ['label' => __('lf.LF_teacher_title_teacher_ai_assistant'), 'value' => 'AI', 'copy' => __('lf.LF_teacher_card_teacher_ai_assistant')],
        ] as $card)
            <section class="admin-card teacher-dashboard-card">
                <p>{{ $card['label'] }}</p>
                <strong>{{ $card['value'] }}</strong>
                <span>{{ $card['copy'] }}</span>
            </section>
        @endforeach
    </div>
@endsection
