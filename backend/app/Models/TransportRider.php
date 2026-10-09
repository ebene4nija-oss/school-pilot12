<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student signed up to the school bus. Active while `ended_on` is empty. */
class TransportRider extends Model
{
    use BelongsToTenant;

    protected $table = 'transport_riders';

    protected $fillable = ['school_id', 'student_id', 'bus_id', 'pickup_point', 'started_on', 'ended_on', 'created_by'];

    protected $casts = ['started_on' => 'date', 'ended_on' => 'date'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
