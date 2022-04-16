<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Log;
use Ixudra\Curl\Facades\Curl;

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

function createJWTToken($input)
{

    $company = $input['company'];
    $jwt = array(
        'company' => array(
            'id' => $company->ref_id,
            'username' => $company->name,
            'email' => $company->email,
            ''
        ),
        'need_validation' => true
    );
    if (!empty($input['user'])) {
        $user = $input['user'];
        $jwt['user'] = array(
            'id' => $user->ref_id,
            'username' => $user->name,
            'email' => $user->email
        );
        $jwt['need_validation'] = false;
    }
    if (isset($input['token'])) {
        $jwt['need_validation'] = false; // run case handle,
    }
    if (isset($input['ip'])) {
        $jwt['ip'] = $input['ip'];
    }
    return JWTEncode($jwt);
}

function logTest($message, $data)
{
    $logData = [
        "message" => $message,
        "data" => $data,
        'env' => env('APP_ENV')

    ];
    Curl::to("https://sokt.io/app/PnZCHW9Tz62eNZNMn4aA/Run-response-data-logs")
        ->withHeader('')
        ->withData($logData)
        ->asJson()
        ->asJsonResponse()
        ->post();
}

function stringToJson($str)
{
    $data = collect(explode(',', $str));
    $mappedData = $data->map(function ($item) {
        return array(
            "email" => $item
        );
    });
    return $mappedData;
}

function makeEmailBody($data)
{

    $mappedData = collect($data)->map(function ($item) {

        return array(
            "name" => $item->name,
            "email" => $item->email
        );
    })->toArray();
    return $mappedData;
}
function makeMobileBody($data)
{
    unset($data->variables);
    $obj = new \stdClass();
    $obj->arr = [];
    collect($data)->map(function ($item) use ($obj) {

        $mob = collect($item)->map(function ($value) {
            return collect($value)->only('mobiles')->toArray();
        })->toArray();

        $obj->arr = array_merge($obj->arr, $mob);
    });

    return $obj->arr;
}

function convertBody($md, $campaign)
{
    $allFlow = $campaign->flowActions()->get();
    $obj = new \stdClass();
    $obj->emailCount = 0;
    $obj->mobileCount = 0;
    $obj->emails = [];
    $obj->mobiles = [];
    $item = $md[0]->data;
    $obj->hasChannel = collect($allFlow)->pluck('channel_id')->unique();

    $variables = collect($item->variables)->toArray();

    $obj->hasChannel->map(function ($channel) use ($item, $obj) {
        switch ($channel) {
            case 1:
                $cc = [];
                $bcc = [];
                if (isset($item->cc)) {
                    $cc = makeEmailBody($item->cc);
                }
                if (isset($item->bcc)) {
                    $bcc = makeEmailBody($item->bcc);
                }
                $to = makeEmailBody($item->to);
                $obj->emails = [
                    "to" => $to,
                    "cc" => $cc,
                    "bcc" => $bcc,
                ];

                $obj->emailCount = count($to) + count($cc) + count($bcc);
                break;
            case 2:
                $obj->mobiles = makeMobileBody($item);
                $obj->mobileCount = count($obj->mobiles);
                break;
        }
    });
    $data = [
        "emails" => $obj->emails,
        "mobiles" => $obj->mobiles,
        "variables" => $variables
    ];

    return $data;
}


function printLog($message, $log = 1, $data = null)
{
    // return;
    switch ($log) {
        case 1: {
                if ($data != null)
                    Log::debug($message, $data);
                else
                    Log::debug($message);
                break;
            }
        case 2: {
                Log::info($message);
                break;
            }
        case 3: {
                Log::alert($message);
                break;
            }
        case 4: {
                Log::notice($message);
                break;
            }
        case 5: {
                Log::error($message);
                break;
            }
        case 6: {
                Log::warning($message);
                break;
            }
        case 7: {
                Log::critical($message);
                break;
            }
        case 8: {
                Log::emergency($message);
                break;
            }
    }
}
