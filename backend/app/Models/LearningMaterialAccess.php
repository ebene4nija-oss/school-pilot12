<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Who a material is shared with: a class, arm, section, student or virtual class. */
class LearningMaterialAccess extends Model
{
    use BelongsToTenant;

    protected $table = 'learning_material_access';

    protected $fillable = ['school_id', 'material_id', 'target_type', 'class_id', 'arm_id', 'section', 'student_id', 'live_class_id'];
}
