{{-- Below a live activity only its further sessions, such as a cancelled one; the Content tab has no such row, so most activities show nothing here. --}}
@if ($activitySlots['others']->isNotEmpty())
    <div class="ui-cohort-session-tree__slots">
        @foreach ($activitySlots['others'] as $session)
            @include('course-cohorts.partials.tabs.session-tree-slot', ['showTitle' => false])
        @endforeach
    </div>
@endif
