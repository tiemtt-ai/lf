{{-- "Quan tâm" (ADR-0030 D4): the catalogue cards of the Products the learner marked, enrolled or not. --}}
<div class="tenant-course-grid student-favorite-grid">
    @foreach ($items as $course)
        @include('tenant.partials.course-card', ['course' => $course, 'studentMode' => true])
    @endforeach
</div>
