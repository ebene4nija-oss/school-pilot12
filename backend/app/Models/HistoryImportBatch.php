<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A legacy results file, previewed with its errors before anything is written. */
class HistoryImportBatch extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'uploaded_by', 'filename', 'status', 'rows', 'errors', 'valid_rows', 'error_rows', 'committed_at'];

    protected $casts = ['rows' => 'array', 'errors' => 'array', 'committed_at' => 'datetime'];
}
