<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One learning resource: an uploaded file or an external link. */
class LearningMaterial extends Model
{
    use BelongsToTenant;

    protected $table = 'learning_materials';

    protected $fillable = [
        'school_id', 'title', 'description', 'type', 'file_path', 'file_name', 'mime', 'size_bytes', 'url',
        'section', 'class_id', 'subject_id', 'session_id', 'term_id', 'topic', 'uploaded_by', 'status',
        'reviewed_by', 'reviewed_at', 'review_note', 'published_at', 'downloadable',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
        'downloadable' => 'boolean',
        'size_bytes' => 'integer',
    ];

    // The stored path never leaves the server.
    protected $hidden = ['file_path'];

    public function targets()
    {
        return $this->hasMany(LearningMaterialAccess::class, 'material_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}
