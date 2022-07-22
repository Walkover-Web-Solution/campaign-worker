<?php

use App\Jobs\Campaign\RabbitMQJob;
use App\Libs\EmailLib;
use App\Libs\MongoDBLib;
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

function getSeconds($unit, $value)
{
    $value = (int)$value;

    switch ($unit) {
        case "seconds": {
                return $value * 1;
            }
        case "minutes": {
                return $value * 60;
            }
            break;
        case "hours": {
                return $value * 3600;
            }
            break;
        case "days": {
                return $value * 86400;
            }
            break;
        default: {
                return 0;
            }
    }
}

function logTest($message, $data, $type = "run")
{
    $logData = [
        "message" => $message,
        "data" => $data,
        'env' => env('APP_ENV')
    ];
    switch ($type) {
        case "run":
            $endpoint = "https://sokt.io/app/PnZCHW9Tz62eNZNMn4aA/Run-response-data-logs";
            break;
        case "event":
            $endpoint = "https://sokt.io/app/PnZCHW9Tz62eNZNMn4aA/events-logs-test";
            break;
    }
    Curl::to($endpoint)
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
        return (object)[
            "email" => $item
        ];
    })->toArray();
    return $mappedData;
}

function convertAttachments($attachments)
{
    return collect($attachments)->map(function ($item) {
        if ($item->fileType == "url") {
            return [
                "filePath" => $item->file,
                "fileName" => $item->fileName
            ];
        } else if ($item->fileType == "base64") {
            unset($item->fileType);
            return (array)$item;
        }
    })->toArray();
}

function updateCampaignLogStatus(CampaignLog $campaignLog)
{

    $campaignLog->canRetry = false;
    $actionLogs = $campaignLog->actionLogs()->get()->toArray();
    if (empty($actionLogs)) {
        try {
            $mongoLib = new MongoDBLib;
            $data = $mongoLib->collection('run_campaign_data')->find([
                'requestId' => $campaignLog->mongo_uid
            ]);
            if (!empty($data)) {
                $campaignLog->canRetry = true;
            }
        } catch (\Exception $e) {
            printLog("exception in helpers.php line no 262 (mongolib error)" . $e->getMessage());
        }
        $campaignLog->status = "Error";
        $campaignLog->save();
    }
    $actionLogsCount = $campaignLog->actionLogs()->count();
    if ($actionLogsCount == 0) {
        printLog("No actionLogs found for campaignLog id : " . $campaignLog->id);
        return;
    }

    printLog("fetching count for actionLogs with iscomplete true for campaignLog id : " . $campaignLog->id);
    $SuccesslogCount = $campaignLog->actionLogs()->where('status', 'Completed')->count();

    if ($SuccesslogCount == $actionLogsCount) {
        $campaignLog->status = "Completed";
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
    // if ($log == 5 || str_starts_with($message, "======")) {
    if (true) {
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
    $obj->i = 0;
    $obj->keys = [];
    $obj->grpFlowActionMap = [];
    collect($obj->mongoData)->map(function ($item) use ($obj) {
        //obj have mongoData, moduleData, data(required filteredData)
        $obj->variables = empty($item->variables) ? [] : $item->variables;
        collect($item)->map(function ($contacts, $field) use ($obj) {
            if ($field != 'variables') {
                collect($contacts)->map(function ($contact) use ($obj, $field) {
                    if (!empty($contact->mobiles)) {
                        $countryCode = getCountryCode($contact->mobiles);
                        $key = 'op_' . $countryCode;
                        //In case key doesn't exists, then treat the contact in others
                        if (empty($obj->moduleData->$key)) {
                            $key = 'op_others';
                        }
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
            return 'run_whatsapp_campaigns';
        case 4:
            return 'run_voice_campaigns';
        case 5:
            return 'run_rcs_campaigns';
        case 6:
            return 'condition_queue';
    }
}

function createNewJob($input, $queue, $delayTime = 0)
{
    if (empty($input->failedCount))
        $input->failedCount = 0;
    printLog("Inside creating new job.", 2);
    if (env('APP_ENV') == 'local') {
        $job = (new RabbitMQJob($input))->onQueue($queue)->delay(Carbon::now()->addSeconds($delayTime))->onConnection('rabbitmqlocal');
        dispatch($job); //dispatching the job
    } else {
        // $job = (new RabbitMQJob($input))->onQueue($queue)->delay(Carbon::now()->addSeconds((int)$delayTime));
        // dispatch($job);
        RabbitMQJob::dispatch($input)->onQueue($queue)->delay(Carbon::now()->addSeconds($delayTime));
    }
    printLog("Successfully created new job.", 2);
}

function getChannelVariables($templateVariables, $contactVariables, $commonVariables, $channel)
{
    $obj = new \stdClass();
    $obj->variables = [];
    $obj->invalid_json = false;

    collect($templateVariables)->each(function ($variableKey) use ($obj, $contactVariables, $commonVariables, $channel) {
        if (!empty($contactVariables->$variableKey)) {
            $variableSet = $contactVariables;
        } else if (!empty($commonVariables->$variableKey)) {
            $variableSet = $commonVariables;
        } else {
            return;
        }
        if ($channel == 3) {
            if (is_string($variableSet->$variableKey)) {
                if ((\Str::startsWith($variableKey, 'button'))) {
                    // In case of wrong body of button variable
                    $obj->invalid_json = true;
                    return false;
                }

                $arr = [
                    "type" => 'text',
                    'value' => $variableSet->$variableKey
                ];
            } else {
                if (\Str::startsWith($variableKey, 'button')) {
                    // In case of wrong body of button variable
                    if (empty($variableSet->$variableKey->sub_type) || empty($variableSet->$variableKey->type) || empty($variableSet->$variableKey->value)) {
                        $obj->invalid_json = true;
                        return false;
                    }
                    $arr = $variableSet->$variableKey;
                } else {
                    $arr = [
                        "type" => empty($variableSet->$variableKey->type) ? "text" : $variableSet->$variableKey->type,
                        "value" => empty($variableSet->$variableKey->value) ? "" : $variableSet->$variableKey->value
                    ];
                }
            }

            $obj->variables = array_merge($obj->variables, [$variableKey => $arr]);
        } else {
            if (is_string($variableSet->$variableKey)) {
                $obj->variables = array_merge($obj->variables, [$variableKey => $variableSet->$variableKey]);
            } else {
                $var = $variableSet->$variableKey;
                if (empty($var->value)) {
                    $obj->variables = array_merge($obj->variables, [$variableKey => ""]);
                } else {
                    $obj->variables = array_merge($obj->variables, [$variableKey => $var->value]);
                }
            }
        }
    });
    if ($obj->invalid_json) {
        return "invalid_json";
    }
    return $obj->variables;
}

function countContacts($data)
{
    $countArr = collect($data)->map(function ($contacts) {
        return collect($contacts)->whereNotNull('mobiles')->count();
    })->toArray();

    return array_sum($countArr);
}

function storeFailedJob($exception, $log_id, $queue, $payload, $connection)
{
    $input = [
        'connection' => $connection,
        'uuid' => $log_id,
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
        case "run_whatsapp_campaigns": {
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
    $campaignLog->status = 'Error - ' . $failedJobId;
    $campaignLog->canRetry = true;
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

/**
 *
 * Return a campaign event according to the event received from corresponding chhannel_id
 */
function getEvent($event, $channel_id)
{

    switch ($channel_id) {
            //case for E-mail channel
        case 1: {
                $eventsynonyms = [
                    "success" => ['delivered'],
                    "failed" => ['rejected', 'failed'],
                    "queued" => ['bounced']

                ];
                foreach ($eventsynonyms as $key => $synonyms) {
                    if (in_array($event, $synonyms)) {
                        return $key;
                    }
                }
                return 'queued';
            }
            break;
            //case for SMS channel
        case 2: {
                $eventsynonyms = [
                    "success" => ['delivered', 'clicked', 'unsubscribed', 'opened'],
                    "failed" => [
                        'rejected by kannel or provider', 'ndnc number', 'rejected by provider',
                        'number under blocked circle', 'blocked number', 'bounced', 'auto failed', 'failed'
                    ]
                ];
                foreach ($eventsynonyms as $key => $synonyms) {
                    if (in_array($event, $synonyms)) {
                        return $key;
                    }
                }
                return 'queued';
            }
            break;
    }
}

function getRecipients($reqBodyData, $channel_id)
{
    switch ($channel_id) {
        case 1: {
                return $reqBodyData->recipients;
            }
        case 2: {
                return $reqBodyData->recipients;
            }
        case 3: {
                return $reqBodyData->payload->template->to_and_components;
            }
        case 4: {
                return [];
            }
        case 5: {
                return $reqBodyData->customer_number_variables;
            }
    }
}

function getRecipientCount($recipients, $channel_id, $test = false)
{
    switch ($channel_id) {
        case 1: {
                return count($recipients->to) + count($recipients->cc) + count($recipients->bcc);
            }
        case 2: {
                return count($recipients);
            }
        case 3: {
                return count($recipients->to);
            }
        case 4: {
                return count($recipients);
            }
        case 5: {
                return count($recipients->customer_number);
            }
    }
}
