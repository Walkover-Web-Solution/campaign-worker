<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;


    protected $fillable = [
        'name',
        'is_boolean',
        'wait_to_fail',
    ];

    protected $casts = array(
        'is_boolean' => 'boolean',
        'wait_to_fail' => 'boolean'
    );

    protected $hidden = array(
        'created_at',
        'updated_at',
        'pivot'
    );

    /**
     * Get all of the post's flowActions.
     */
    public function flowActions()
    {
        return $this->morphMany(FlowAction::class, 'linked');
    }

    public function channel()
    {
        return $this->belongsToMany(ChannelType::class)->using(ChannelTypeEvents::class);
    }
}
