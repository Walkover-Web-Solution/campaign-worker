<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Variable extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'company_id'
    ];

    protected $hidden = array(
        'created_at',
        'updated_at',
        'company_id'
    );
    public static function boot()
    {
        parent::boot();

        static::creating(function ($variable) {
            $company_id = request()->company->id;
            if (empty($variable->company_id)) {
                $variable->company_id = $company_id;
            }
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function flowActions()
    {
        return $this->belongsToMany(FlowAction::class);
    }
}
