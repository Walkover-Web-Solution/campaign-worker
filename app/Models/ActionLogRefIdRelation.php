<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActionLogRefIdRelation extends Model
{
    use HasFactory;

    protected $fillable = [
        'action_log_id',
        'ref_id',
        'response',
        'status',
        'no_of_records'
    ];

    protected $casts = [
        'response' => 'json'
    ];

    protected $hidden = [
        'action_log_id'
    ];

    /**
     * Get the ref_ids that owns the ActionLog
     */
    public function actionLogs()
    {
        return $this->belongsTo(ActionLog::class, 'action_log_id');
    }
}
