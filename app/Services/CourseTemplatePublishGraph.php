<?php

namespace App\Services;

use Illuminate\Support\Collection;

final readonly class CourseTemplatePublishGraph
{
    public function __construct(
        public object $template,
        public Collection $sections,
        public Collection $lessons,
        public Collection $activities,
    ) {}
}
