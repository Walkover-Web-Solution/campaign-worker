<?php

use App\Jobs\RabbitMQJob;
use App\Libs\EmailLib;
use App\Libs\RabbitMQLib;
use App\Libs\RcsLib;
use App\Libs\SmsLib;
use App\Libs\VoiceLib;
use App\Libs\WhatsAppLib;
use App\Models\CampaignLog;
use App\Models\Condition;
use App\Models\Filter;
use App\Models\FlowAction;
use App\Services\EmailService;
use App\Services\RcsService;
use App\Services\SmsService;
use App\Services\VoiceService;
use App\Services\WhatsappService;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
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
            case 1: {
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
                }
                break;
            case 6: // for condition flowAciton
                break;
            default: {
                    $obj->mobiles = collect($service->createRequestBody($item))->whereNotNull('mobiles');
                    $obj->mobileCount = count($obj->mobiles);
                }
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

function updateCampaignLogStatus(CampaignLog $campaignLog)
{
    $actionLogs = $campaignLog->actionLogs()->get()->toArray();
    if (empty($actionLogs)) {
        printLog("No actionLogs found for campaignLog id : " . $campaignLog->id);
        return;
    }

    printLog("fetching count for actionLogs with status pending for campaignLog id : " . $campaignLog->id);
    $pendingCount = $campaignLog->actionLogs()->where('status', 'pending')->count();

    if ($pendingCount == 0) {
        $campaignLog->status = "Complete";
        $campaignLog->save();
        printLog("status changed from Running to Complete for campaignLog id : " . $campaignLog->id);
    }
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
        case $rcs:
            return new RcsLib();
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
        case $rcs:
            return new RcsService();
    }
}

/**
 * This function will print logs in laravel.log file
 * present in storage/logs folder
 */
function printLog($message, $log = 1, $data = null)
{
    if ($log == 5 || str_starts_with($data, "======")) {
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
}

function getFilteredData($obj)
{
    //obj have mongoData, moduleData, data(required filteredData)
    $obj->keys = [];
    $obj->grpFlowActionMap = [];
    $obj->variables = $obj->mongoData->variables;
    collect($obj->mongoData)->map(function ($contacts, $field) use ($obj) {
        if ($field != 'variables') {
            collect($contacts)->map(function ($contact) use ($obj, $field) {
                if (!empty($contact->mobiles)) {
                    $countryCode = getCountryCode($contact->mobiles);
                    $key = 'op_' . $countryCode;
                    if (!empty($obj->moduleData->$key)) {
                        $grpKey = $key . '_grp_id';
                        if (!empty($obj->moduleData->$grpKey)) {
                            $grpId = $obj->moduleData->$grpKey;
                            if (empty($obj->data->$grpId)) {
                                array_push($obj->keys, $grpId);
                                $obj->data->$grpId = new \stdClass();
                                $obj->data->$grpId->to = [];
                                $obj->data->$grpId->cc = [];
                                $obj->data->$grpId->bcc = [];
                                $obj->data->$grpId->variables = $obj->variables;
                                $obj->grpFlowActionMap[$grpId] = $obj->moduleData->$key;
                            }
                            array_push($obj->data->$grpId->$field, $contact);
                        }
                    }
                }
            });
        }
    });
    return $obj;
}

function getFilteredDatawithRemainingGroups($obj)
{
    $usedGroupIds = $obj->keys;
    $totalGroupIds = collect($obj->moduleData->groupNames)->keys()->toArray();
    $remGroupIds = array_diff($totalGroupIds, $usedGroupIds);
    collect($remGroupIds)->map(function ($remGroupId) use ($obj) {
        collect($obj->moduleData)->map(function ($opVal, $opKey) use ($remGroupId, $obj) {
            if (\Str::endsWith($opKey, 'grp_id') && $opVal == $remGroupId) {
                $keySplit = explode('_', $opKey);
                $key = $keySplit[0] . '_' . $keySplit[1];
                if (!empty($obj->moduleData->$key)) {
                    // initializing for the first time
                    if (empty($obj->data->$remGroupId)) {
                        $obj->data->$remGroupId = new \stdClass();
                        $obj->data->$remGroupId->to = [];
                        $obj->data->$remGroupId->cc = [];
                        $obj->data->$remGroupId->bcc = [];
                        $obj->data->$remGroupId->variables = $obj->variables;
                    }
                    $obj->grpFlowActionMap[$remGroupId] = $obj->moduleData->$key;
                }
            }
        });
    });
    return $obj;
}

function getCountryCode($mobile)
{
    $condition = Condition::where('name', 'Countries')->with('filters:short_name,value')->first()->toArray();
    for ($i = 4; $i > 0; $i--) {
        $mobileCode = substr($mobile, 0, $i);

        $codeData = collect($condition['filters'])->firstWhere('value', $mobileCode);
        if (!empty($codeData)) {
            $countryCode = $codeData['short_name'];
            return $countryCode;
        }
    }
    return 'others';
}

function getQueue($channel_id)
{
    switch ($channel_id) {
        case 1:
            return 'run_email_campaigns';
        case 2:
            return 'run_sms_campaigns';
        case 3:
            return 'run_whastapp_campaigns';
        case 4:
            return 'run_voice_campaigns';
        case 5:
            return 'run_rcs_campaigns';
        case 6:
            return 'condition_queue';
    }
}

function createNewJob($channel_id, $input, $delayTime)
{
    printLog("Inside creating new job.", 2);
    //selecting the queue name as per the flow channel id
    $queue = getQueue($channel_id);
    //   printLog('Rabbitmq lib we found '.$this->rabbitmq->connection_status, 1);
    printLog("Here to dispatch job.", 2);
    // if (empty($rabbitmq)) {
    //     $rabbitmq = new RabbitMQLib;
    // }
    // $rabbitmq->enqueue($queue, $input);
    if (env('APP_ENV') == 'local') {
        $job = (new RabbitMQJob($input))->onQueue($queue)->delay(Carbon::now()->addSeconds((int)$delayTime))->onConnection('rabbitmqlocal');
        dispatch($job); //dispatching the job
    } else {
        // $job = (new RabbitMQJob($input))->onQueue($queue)->delay(Carbon::now()->addSeconds((int)$delayTime));
        // dispatch($job);
        RabbitMQJob::dispatch($input)->onQueue($queue)->delay(Carbon::now()->addSeconds((int)$delayTime));
    }
    printLog("Successfully created new job.", 2);
}

function getChannelVariables($templateVariables, $contactVariables, $commonVariables)
{
    if (empty($contactVariables)) {
        return $commonVariables;
    }
    $totalVariables = array_unique(array_merge(array_keys($contactVariables), $templateVariables));
    $variableKeys = array_intersect(array_keys($commonVariables), $totalVariables);

    $obj = new \stdClass();
    $obj->variables = [];
    collect($variableKeys)->map(function ($variableKey) use ($obj, $contactVariables, $commonVariables) {
        if (!empty($contactVariables[$variableKey])) {
            $obj->variables = array_merge($obj->variables, [$variableKey => $contactVariables[$variableKey]]);
        } else if (!empty($commonVariables[$variableKey])) {
            $obj->variables = array_merge($obj->variables, [$variableKey => $commonVariables[$variableKey]]);
        }
    });
    return $obj->variables;
}
