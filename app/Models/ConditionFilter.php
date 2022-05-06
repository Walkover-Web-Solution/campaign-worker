<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConditionFilter extends Model
{
    use HasFactory;

    protected $table = 'condition_filter';

    protected $fillable = [
        'condition_id ',
        'filter_id',
    ];

    protected $hidden = array(
        'created_at',
        'updated_at'
    );
}
