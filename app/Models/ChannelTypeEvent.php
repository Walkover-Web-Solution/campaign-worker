<?php

namespace App\Models;

use Illuminate\Broadcasting\Channel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ChannelTypeEvent extends Pivot
{
    use HasFactory;

    protected $fillable = [
        'channel_id ',
        'event_id',
    ];

    protected $hidden = array(
        'created_at',
        'updated_at'
    );
}
