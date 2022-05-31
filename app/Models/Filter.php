<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Filter extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'field',
        'short_name',
        'operation',
        'value',
        'query'
    ];

    protected $hidden = array(
        'created_at',
        'updated_at',
        'pivot'
    );
}
