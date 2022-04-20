<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActionLogReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'action_log_id',
        'total',
        'delivered',
        'failed',
        'pending',
        'additional_fields'
    ];

    protected $casts = [
        'additional_fields' => 'json'
    ];

    protected $hidden = [
        'created_at',
        'updated_at'
    ];


    // get actionLog 
    public function actionLog()
    {
        return $this->belongsTo(ActionLog::class, 'action_log_id');
    }
}
