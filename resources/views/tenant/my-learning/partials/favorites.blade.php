{{-- "Quan tâm" (ADR-0030 D4): the catalogue cards of the Products the learner marked, enrolled or not. --}}
<div class="student-products__grid student-products__grid--courses">
    @foreach ($items as $course)
        @include('tenant.partials.course-card', ['course' => $course, 'studentMode' => true])
    @endforeach
</div>
