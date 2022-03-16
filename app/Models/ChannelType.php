<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChannelType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'configurations',
    ];

    protected $casts = [
        'configurations' => 'json',
    ];

    protected $hidden = array(
        'created_at',
        'updated_at'
    );

    /**
     * Get all of the post's flowActions.
     */
    public function flowActions()
    {
        return $this->morphMany(FlowAction::class, 'linked');
    }
}
