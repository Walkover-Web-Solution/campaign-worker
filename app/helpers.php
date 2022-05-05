<?php

use App\Libs\EmailLib;
use App\Libs\SmsLib;
use App\Libs\VoiceLib;
use App\Libs\WhatsAppLib;
use App\Models\Condition;
use App\Models\Filter;
use App\Models\FlowAction;
use App\Services\EmailService;
use App\Services\SmsService;
use App\Services\VoiceService;
use App\Services\WhatsappService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache as FacadesCache;
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
            'email' => $company->email
        )
    );
    if (!empty($input['user'])) {
        $user = $input['user'];
        $jwt['user'] = array(
            'id' => $user->ref_id,
            'username' => $user->name,
            'email' => $user->email
        );
    }
    if (isset($input['need_validation']))
        $jwt['need_validation'] = $input['need_validation'];
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
    $variables = [];
    if (!empty($item->variables))
        $variables = collect($item->variables)->toArray();

    $obj->hasChannel->map(function ($channel) use ($item, $obj) {
        $service = setService($channel);
        switch ($channel) {
            case 1:
                $to = [];
                $cc = [];
                $bcc = [];
                if (isset($item->to)) {
                    $to = $service->createRequestBody($item->to);
                    $to = collect($to)->whereNotNull('email');
                }
                if (isset($item->cc)) {
                    $cc = $service->createRequestBody($item->cc);
                    $cc = collect($cc)->whereNotNull('email');
                }
                if (isset($item->bcc)) {
                    $bcc = $service->createRequestBody($item->bcc);
                    $bcc = collect($bcc)->whereNotNull('email');
                }
                $obj->emails = [
                    "to" => $to,
                    "cc" => $cc,
                    "bcc" => $bcc,
                ];

                $obj->emailCount = count($to) + count($cc) + count($bcc);
                break;
            case 2:
                $obj->mobiles = collect($service->createRequestBody($item))->whereNotNull('mobiles');
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

function handleCondition(FlowAction $flowAction)
{
    dd($flowAction);
    $conditionId = collect($flowAction->configurations)->firstWhere('name', 'Condition')->value;

    switch ($conditionId) {
        case 1: {
                //
            }
            break;
        default: {
                //
            }
    }
    // $condition = Condition::where('id', $conditionId);
    $flowAction = FlowAction::where('id', $flowAction->module_data->op_success)->first();
    return $flowAction;
}

function setLibrary($channel)
{
    $email = 1;
    $sms = 2;
    $whatsapp = 3;
    $voice = 4;
    $rcs = 5;
    switch ($channel) {
        case $email:
            return new EmailLib();
        case $sms:
            return new SmsLib();
        case $whatsapp:
            return new WhatsAppLib();
        case $voice:
            return new VoiceLib();
            // case $rcs:
            //     return new RcsLib();
    }
}
function setService($channel)
{
    $email = 1;
    $sms = 2;
    $whatsapp = 3;
    $voice = 4;
    $rcs = 5;
    switch ($channel) {
        case $email:
            return new EmailService();
        case $sms:
            return new SmsService();
        case $whatsapp:
            return new WhatsappService();
        case $voice:
            return new VoiceService();
            // case $rcs:
            //     return new RcsService();
    }
}

/**
 * This function will print logs in laravel.log file
 * present in storage/logs folder
 */
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

function validPhoneNumber($mobile, $filter)
{
    $path = Filter::where('name', 'countries')->pluck('source')->first();
    $countriesJson = FacadesCache::get('countriesJson');
    if (empty($countriesJson)) {
        $countriesJson = json_decode(file_get_contents($path));
        FacadesCache::put('countriesJson', $countriesJson, 86400);
    }
    $code = collect($countriesJson)->pluck('International dialing')->toArray();
    for ($i = 4; $i > 0; $i--) {
        $mobileCode = substr($mobile, 0, $i);

        if (in_array($mobileCode, $code)) {
            $codeData = collect($countriesJson)->firstWhere('International dialing', $mobileCode);
            $codeData = (array)$codeData;
            return $codeData['Country code'] == $filter ? true : false;
        }
    }
}
