<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveClassSchedule extends Model
{
    protected $table = 'core_liveclass_schedules';

    protected $guarded = ['id', 'customer_id', 'cohort_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function slots(): HasMany
    {
        return $this->hasMany(LiveClassScheduleSlot::class, 'schedule_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    public function exclusions(): HasMany
    {
        return $this->hasMany(LiveClassScheduleExclusion::class, 'schedule_id')
            ->orderBy('excluded_on')->orderBy('id');
    }
}
