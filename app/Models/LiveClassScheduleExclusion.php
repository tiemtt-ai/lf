<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveClassScheduleExclusion extends Model
{
    protected $table = 'core_liveclass_schedule_exclusions';

    protected $guarded = ['id', 'customer_id', 'schedule_id', 'created_by'];

    protected function casts(): array
    {
        return ['excluded_on' => 'date'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(LiveClassSchedule::class, 'schedule_id');
    }
}
