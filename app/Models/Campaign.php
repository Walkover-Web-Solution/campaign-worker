<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'token_id',
        'is_active',
        'configurations',
        'meta',
        'slug'
    ];

    protected $casts = array(
        'meta' => 'object',
        'configurations' => 'object',
    );


    protected $hidden = array(
        'created_at',
        'updated_at',
        'user_id',
        "company_id",
        "token_id",
        'deleted_at',
        'meta'
    );

    /**
     * Get all of the actionLogs for the Campaign
     */
    public function actionLogs()
    {
        return $this->hasMany(ActionLog::class, 'campaign_id');
    }

    /**
     * Get all of the flowAction for the Campaign
     */
    public function flowActions()
    {
        return $this->hasMany(FlowAction::class, 'campaign_id');
    }

    /**
     * Get all of the campaignReports for the Campaign
     */
    public function campaignReports()
    {
        return $this->hasMany(CampaignReport::class, 'campaign_id');
    }
}
