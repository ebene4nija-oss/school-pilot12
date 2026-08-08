<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant()
    {
        static::addGlobalScope('school_id', function (Builder $builder) {
            if (Auth::check() && Auth::user()->userProfile && Auth::user()->userProfile->role !== 'super_admin') {
                /*
                 * Table-qualified deliberately.
                 *
                 * A bare `where('school_id', ...)` is ambiguous the moment the
                 * query joins another tenant-scoped table — `students`,
                 * `classes` and `homework` all carry the column — and SQL
                 * rejects it outright. That made any joined aggregate a 500:
                 * the principal dashboard's "best performing classes" query
                 * was one, and it shipped that way because nothing tested it.
                 */
                $builder->where(
                    $builder->getModel()->qualifyColumn('school_id'),
                    Auth::user()->userProfile->school_id
                );
            }
        });

        static::creating(function (Model $model) {
            if (Auth::check() && Auth::user()->userProfile && empty($model->school_id)) {
                $model->school_id = Auth::user()->userProfile->school_id;
            }
        });
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
