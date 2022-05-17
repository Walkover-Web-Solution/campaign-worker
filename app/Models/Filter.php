<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Filter extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'source'
    ];
    
    protected $casts = array(
        'source' => 'json',
    );

    protected $hidden = array(
        'created_at',
        'updated_at',
        'pivot'
    );
}
