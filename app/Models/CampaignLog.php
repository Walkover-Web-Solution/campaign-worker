<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CampaignLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'created_at',
        'updated_at',
        'no_of_contacts',
        'status',
        'ip',
        'need_validation',
        'is_paused'
    ];

    protected $casts = [
        'need_validation' => 'boolean',
        'is_paused' => 'boolean'
    ];

    protected $hidden = [
        'created_at',
        'mongo_uid',
        'updated_at'
    ];

    /**
     * Get Campaign of this Campaign Log
     */
    public function campaign()
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    /**
     * Get all action logs belongs to this Campaign Log
     */
    public function actionLogs()
    {
        return $this->hasMany(ActionLog::class);
    }
}
