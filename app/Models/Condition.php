<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Condition extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'configurations'
    ];

    protected $casts = array(
        'configurations' => 'object',
    );


    protected $hidden = array(
        'created_at',
        'updated_at',
    );
}
