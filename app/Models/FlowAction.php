<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FlowAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'campaign_id',
        'channel_id',
        'linked_type',
        'is_condition',
        'parent_id',
        'configurations',
        'style',
        'module_data'
    ];
    protected $casts = array(
        'is_condition' => 'boolean',
        'configurations' => 'object',
        'style' => 'object',
        'module_data' => 'object'
    );
    protected $hidden = array(
        'created_at',
        'updated_at',
        'channel_id'
    );


    /**
     * Get the actionLog that owns the FlowAction
     */
    public function actionLog()
    {
        return $this->belongsTo(ActionLog::class, 'campaign_id');
    }

    /**
     * Get the template that owns the FlowAction
     */
    public function template()
    {
        return $this->hasOne(Template::class);
    }

    /**
     * Get the campaign that owns the FlowAction
     */
    public function campaign()
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    /**
     * Get all of the owning linked models.
     */
    public function linked()
    {
        return $this->morphTo();
    }
}
