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
        'slug',
        'style',
        'module_data'
    ];

    protected $casts = array(
        'meta' => 'object',
        'configurations' => 'object',
        'style' => 'json',
        'module_data' => 'json'
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
     * Get the user that owns the Campaign
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the company that owns the Campaign
     *
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Get the token that owns the Campaign
     *
     */
    public function token()
    {
        return $this->belongsTo(Token::class, 'token_id');
    }

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
