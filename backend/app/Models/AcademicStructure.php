<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicSession extends Model
{
    use BelongsToTenant;

    protected $table = 'academic_sessions';

    protected $fillable = ['school_id', 'name', 'start_date', 'end_date', 'is_current'];
}

class Term extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'session_id', 'name', 'start_date', 'end_date', 'is_current'];
}

class SchoolClass extends Model
{
    use BelongsToTenant;

    protected $table = 'classes';

    protected $fillable = ['school_id', 'name', 'level_category', 'order_index'];
}
