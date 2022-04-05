<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;
    protected $fillable = [
        'client_id',
        'name',
        'ref_id',
        'email',
        'authkey'
    ];

    /**
     * The users that belong to the Company
     */
    public function users(){
        return  $this->hasMany(User::class);
     }

     /**
      * Get all of the tokens and campaign for the Company
    */
      public function tokens(){
        return $this->hasMany(Token::class);
       }


       public function campaigns(){
        return $this->hasMany(Campaign::class);
       }

}
