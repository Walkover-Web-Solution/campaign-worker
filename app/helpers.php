<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function ISTToGMT($date)
{
    $date = new \DateTime($date);
    return $date->modify('- 5 hours - 30 minutes')->format('Y-m-d H:i:s');
}

function GMTToIST($date)
{
    $date = new \DateTime($date);
    return $date->modify('+ 5 hours + 30 minutes')->format('Y-m-d H:i:s');
}

function filterMobileNumber($mobileNumber)
{
    if (strlen($mobileNumber) == 10) {
        return '91' . $mobileNumber;
    }
    return $mobileNumber;
}

function JWTEncode($value)
{
    $key = config('services.msg91.jwt_secret');
    return JWT::encode($value, $key, 'HS256');
}

function JWTDecode($value)
{
    $key = config('services.msg91.jwt_secret');
    return JWT::decode($value, new Key($key, 'HS256'));
}

function createJWTToken($input){

    $company=$input['company'];
    $jwt=array(
        'company'=>array(
            'id'=>$company->ref_id,
            'username'=>$company->name,
            'email'=>$company->email,
            ''
        ),
        'need_validation'=>true
    );
    if(!empty($input['user'])){
        $user=$input['user'];
        $jwt['user']=array(
            'id'=>$user->ref_id,
            'username'=>$user->name,
            'email'=>$user->email
        );
        $jwt['need_validation']=false;
    }
    if(isset($input['token'])){
        $jwt['need_validation']=false; // run case handle,
    }
    if(isset($input['ip'])){
        $jwt['ip']=$input['ip'];
    }
    return JWTEncode($jwt);
}
