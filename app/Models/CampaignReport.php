<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CampaignReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'campign_id',
        'action_log_id',
        'report'
    ];

    protected $casts = [
        'report' => 'json',
    ];

    protected $hidden = array(
        'created_at',
        'updated_at'
    );

    /**
     * Get the actionLog that owns the CampaignReport
     */
    public function actionLog()
    {
        return $this->belongsTo(ActionLog::class, 'action_log_id');
    }

    /**
     * Get the campaign that owns the CampaignReport
     */
    public function campaign()
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }
}
