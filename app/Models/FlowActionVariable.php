<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FlowActionVariable extends Model
{
    use HasFactory;
    // protected $table = 'flow_action_variables';

    protected $fillable = [
        'variable_id',
        'flow_action_id',
    ];

    protected $hidden = array(
        'created_at',
        'updated_at'
    );
}
