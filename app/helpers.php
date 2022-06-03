<?php

use App\Jobs\RabbitMQJob;
use App\Libs\EmailLib;
use App\Libs\RcsLib;
use App\Libs\SmsLib;
use App\Libs\VoiceLib;
use App\Libs\WhatsAppLib;
use App\Models\ActionLog;
use App\Models\CampaignLog;
use App\Models\Condition;
use App\Models\FailedJob;
use App\Services\EmailService;
use App\Services\RcsService;
use App\Services\SmsService;
use App\Services\VoiceService;
use App\Services\WhatsappService;
use Carbon\Carbon;
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
    $obj->variables = [];
    $obj->hasChannel = collect($allFlow)->pluck('channel_id')->unique();

    $obj->hasChannel->map(function ($channel) use ($obj, $md) {
        $service = setService($channel);
        collect($md)->map(function ($item) use ($obj, $service, $channel) {
            switch ($channel) {
                case 1: {
                        if (!empty($item->variables))
                            $obj->variables = collect($item->variables)->toArray();

                        $to = [];
                        $cc = [];
                        $bcc = [];
                        if (isset($item->to)) {
                            $to = $service->createRequestBody($item->to);
                            $to = collect($to)->whereNotNull('email')->toArray();
                        }
                        if (isset($item->cc)) {
                            $cc = $service->createRequestBody($item->cc);
                            $cc = collect($cc)->whereNotNull('email')->toArray();
                        }
                        if (isset($item->bcc)) {
                            $bcc = $service->createRequestBody($item->bcc);
                            $bcc = collect($bcc)->whereNotNull('email')->toArray();
                        }
                        $emails = [
                            "to" => $to,
                            "cc" => $cc,
                            "bcc" => $bcc,
                            "variables" => $obj->variables
                        ];
                        $obj->emailCount += count($to) + count($cc) + count($bcc);
                        array_push($obj->emails, $emails);
                    }
                    break;
                case 6: // for condition flowAciton
                    break;
                default: {
                        $mobiles = collect($service->createRequestBody($item))->whereNotNull('mobiles')->toArray();
                        $obj->mobiles = array_merge($obj->mobiles, $mobiles);
                        $obj->mobileCount += count($obj->mobiles);
                    }
                    break;
            }
        });
    });

    $data = [
        "emails" => $obj->emails,
        "mobiles" => $obj->mobiles,
        "variables" => $obj->variables
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

function getFilteredData($obj)
{
    $obj->i = 0;
    $obj->keys = [];
    $obj->grpFlowActionMap = [];
    collect($obj->mongoData)->map(function ($item) use ($obj) {
        //obj have mongoData, moduleData, data(required filteredData)
        $obj->variables = $item->variables;
        collect($item)->map(function ($contacts, $field) use ($obj) {
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
                                    $obj->data->$grpId = [];
                                    $obj->grpFlowActionMap[$grpId] = $obj->moduleData->$key;
                                }
                                if (empty($obj->data->$grpId[$obj->i])) {
                                    $obj->data->$grpId[$obj->i] = new \stdClass();
                                    $obj->data->$grpId[$obj->i]->to = [];
                                    $obj->data->$grpId[$obj->i]->cc = [];
                                    $obj->data->$grpId[$obj->i]->bcc = [];
                                    $obj->data->$grpId[$obj->i]->variables = $obj->variables;
                                }
                                array_push($obj->data->$grpId[$obj->i]->$field, $contact);
                            }
                        }
                    }
                });
            }
        });
        $obj->i++;
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
                        $obj->data->$remGroupId = [];
                        $obj->data->$remGroupId[0] = new \stdClass();
                        $obj->data->$remGroupId[0]->to = [];
                        $obj->data->$remGroupId[0]->cc = [];
                        $obj->data->$remGroupId[0]->bcc = [];
                        $obj->data->$remGroupId[0]->variables = $obj->variables;
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
    $input->failedCount = 0;
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

function countContacts($data)
{
    $countArr = collect($data)->map(function ($contacts) {
        return collect($contacts)->whereNotNull('mobiles')->count();
    })->toArray();

    return array_sum($countArr);
}

function storeFailedJob($exception, $log_id, $queue, $payload)
{
    $input = [
        'connection' => 'rabbitmq',
        'uuid' => $payload['uuid'],
        'queue' => $queue,
        'payload' => $payload,
        'exception' => $exception,
        'failed_at' => Carbon::now(),
        'log_id' => $log_id
    ];

    $failedJob = FailedJob::create($input);

    switch ($queue) {
        case "1k_data_queue": {
                updateCampaignLog($log_id, $failedJob->id);
                break;
            }
        case "run_email_campaigns": {
                updateActionLog($log_id, $failedJob->id);
                break;
            }
        case "run_sms_campaigns": {
                updateActionLog($log_id, $failedJob->id);
                break;
            }
        case "run_rcs_campaigns": {
                updateActionLog($log_id, $failedJob->id);
                break;
            }
        case "run_voice_campaigns": {
                updateActionLog($log_id, $failedJob->id);
                break;
            }
        case "run_whastapp_campaigns": {
                updateActionLog($log_id, $failedJob->id);
                break;
            }
        case "condition_queue": {
                updateActionLog($log_id, $failedJob->id);
                break;
            }
        default: {
                //
            }
    }
}
function updateCampaignLog($log_id, $failedJobId)
{
    $campaignLog = CampaignLog::where('id', $log_id)->first();
    $campaignLog->status = 'Failed -' . $failedJobId;
    $campaignLog->save();
}
function updateActionLog($log_id, $failedJobId)
{
    $actionLog = ActionLog::where('id', $log_id)->first();
    $actionLog->status = 'Failed';
    $actionLog->response = [
        "data" => $failedJobId
    ];
    $actionLog->save();
}
