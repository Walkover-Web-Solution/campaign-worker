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
        'need_validation'
    ];

    protected $casts = [
        'need_validation' => 'boolean'
    ];

    protected $hidden = [
        'created_at',
        'mongo_uid',
        'updated_at'
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }
}
