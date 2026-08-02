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
                $builder->where('school_id', Auth::user()->userProfile->school_id);
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
