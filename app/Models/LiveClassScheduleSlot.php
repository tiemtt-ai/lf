<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveClassScheduleSlot extends Model
{
    protected $table = 'core_liveclass_schedule_slots';

    protected $guarded = ['id', 'customer_id', 'schedule_id', 'created_by'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(LiveClassSchedule::class, 'schedule_id');
    }
}
