<?php

namespace App\Services;

use App\Jobs\RabbitMQJob;
use App\Libs\EmailLib;
use App\Libs\MongoDBLib;
use App\Libs\RabbitMQLib;
use App\Libs\SmsLib;
use App\Libs\VoiceLib;
use App\Libs\WhatsAppLib;
use App\Models\ActionLog;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\ChannelType;
use App\Models\Company;
use App\Models\FlowAction;
use App\Models\Template;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

/**
 * Class ChannelService
 * @package App\Services
 */
class ChannelService
{
    protected $mongo;
    protected $rabbitmq;
    public function __construct()
    {
        //
    }

    public function sendData($actionLogId)
    {
        printLog("----- Lets process action log ----------", 2);
        $action_log = ActionLog::where('id', $actionLogId)->first();
        /**
         * generating the token
         */
        $campaignLog = $action_log->campaignLog;
        $campaign = Campaign::find($action_log->campaign_id);
        $input['company'] = $campaign->company;
        $input['user'] = $campaign->user;
        $input['ip'] = $campaignLog->ip;
        $input['need_validation'] = (bool) $campaignLog->need_validation;
        config(['msg91.jwt_token' => createJWTToken($input)]);

        printLog("Till now we found Campaign, and created JWT. And now about to find flow action.", 2);
        $flow = FlowAction::where('campaign_id', $action_log->campaign_id)->where('id', $action_log->flow_action_id)->first();

        // handling condition
        while ($flow->channel_id == 5) {
            $flow = handleCondition($flow);
        }

        if (empty($this->mongo)) {
            $this->mongo = new MongoDBLib;
        }
        /**
         * Geting the data from mongo
         */
        $data = $this->mongo->collection('flow_action_data')->find([
            'requestId' => $action_log->mongo_id
        ]);
        $md = json_decode(json_encode($data));
        /**
         * generating the request body data according to flow channel id
         */

        printLog("converting the contact body data to required context.", 2);
        $convertedData = convertBody($md, $campaign);
        printLog("BEFORE GET REQUEST BODY", 1, $convertedData);

        printLog("generating the request body data according to flow channel id.", 2);
        $reqBody = $this->getRequestBody($flow, $convertedData);

        //get unique data only and count duplicate
        $duplicateCount = 0;
        if ($flow['channel_id'] == 2) {
            $reqBody->data->recipients = collect($reqBody->data->recipients)->unique()->toArray();
            //original count
            $duplicateCount = $reqBody->count;
            //new count after removing duplicate
            $reqBody->count = count($reqBody->data->recipients);
            //calculating duplicate
            $duplicateCount -= $reqBody->count;
        }

        /**
         * Geting the libary object according to the flow channel id to send the data to the microservice
         */
        $lib = setLibrary($flow['channel_id']);
        if ($reqBody->count == 0) {
            $res = new \stdClass();
            $res->hasError = true;
            $res->message = "No Data Found";
        } else {
            if ($flow['channel_id'] == 2) {
                printLog("DATA HERE", 1, (array)$data);
            }
            $res = $lib->send($reqBody->data);
            //adding duplicate count to response
            if (!empty($res)) {
                $res->duplicate = $duplicateCount;
            }
        }
        /**
         * updating the response comes from the microservice into the ref_id of current flow action
         */
        printLog('We have successfully send data to: ' . $flow['channel_id'] . ' channel', 1, empty($res) ? (array)['message' => 'NULL RESPONSE'] : (array)$res);

        $new_action_log = $this->updateActionLogResponse($flow, $action_log, $res, $reqBody->count);
        $delayTime=collect($flow->configurations)->firstWhere('name','delay');
        printLog('Got new action log and its id is ' . empty($new_action_log) ? "Action Log NOT FOUND" : $new_action_log->id, 1);
        if (!empty($new_action_log)) {
            printLog("Now creating new job for action log.", 1);
            $input = new \stdClass();
            $input->action_log_id =  $new_action_log->id;
            $channel_id = FlowAction::where('id', $new_action_log->flow_action_id)->pluck('channel_id')->first();
            $this->createNewJob($channel_id, $input,$delayTime->value);
        }

        return;
    }



    public function getRequestBody($flow, $md)
    {
        /**
         * extracting the all the variables from the mongo data
         */
        $var = $md['variables'];
        $obj = new \stdClass();
        $obj->count = 0;
        // get template of this flowAction
        $temp = $flow->template;

        //filter out variables of this flowActions template
        $variables = collect($var)->map(function ($value, $key) use ($temp) {
            if (in_array($key, $temp->variables)) {
                return $value;
            }
        });
        $variables = array_filter($variables->toArray());

        $data = [];
        $mongo_data = $md;
        switch ($flow['channel_id']) {
            case 1: //For Email
                $cc = [];
                $bcc = [];
                $obj->values = [];
                collect($flow["configurations"])->map(function ($item) use ($obj) {
                    if ($item->name != 'template')
                        $obj->values[$item->name] = $item->value;
                });
                if (!empty($mongo_data['emails']['cc'])) {
                    $cc = $mongo_data['emails']['cc'];
                } else if (!empty($obj->values['cc'])) {
                    $cc = stringToJson($obj->values['cc']);
                }
                if (!empty($mongo_data['emails']['bcc'])) {
                    $bcc = $mongo_data['emails']['bcc'];
                } else if (!empty($obj->values['bcc'])) {
                    $bcc = stringToJson($obj->values['bcc']);
                }
                $domain = empty($obj->values['domain']) ? $obj->values['parent_domain'] : $obj->values['domain'];
                $email = $obj->values['from_email'] . "@" . $domain;
                $from = [
                    "name" => $obj->values['from_email_name'],
                    "email" => $email
                ];
                $to = $mongo_data['emails']['to'];
                $obj->count = count($to);
                if (!empty($cc))
                    $obj->count += count($cc);
                if (!empty($bcc))
                    $obj->count += count($bcc);

                $data = array(
                    "recipients" => array(
                        [
                            "to" => $mongo_data['emails']['to'],
                            "cc" => $cc,
                            "bcc" => $bcc,
                            "variables" => json_decode(collect($variables))
                        ]
                    ),
                    "from" => json_decode(collect($from)),
                    "template_id" => $temp->template_id
                );
                printLog("GET REQUEST BODY", 1, $data);
                break;
            case 2: //For SMS
                $obj->mobilesArr = [];
                $mongo_data['mobiles']->map(function ($item) use ($obj, $variables) {
                    $item = array_merge($item, $variables);
                    array_push($obj->mobilesArr, $item);
                });

                $data = [
                    "flow_id" => $temp->template_id,
                    'recipients' => collect($obj->mobilesArr),
                    "short_url" => true
                ];
                $obj->count = count($mongo_data['mobiles']);
                break;
            case 3:
                //
                break;
        }
        $obj->data = json_decode(collect($data));

        return $obj;
    }

    public function updateActionLogResponse($flow, $action_log, $res, $reqDataCount)
    {

        printLog("Now sending data to microservice", 1);

        $val = "";
        $status = "Success";
        if ($flow->channel_id == 1 && !empty($res) && !$res->hasError) {
            $val = $res->data->unique_id;
        } else if ($flow->channel_id == 2 && !empty($res) && !$res->hasError) {
            $val = $res->data;
        } else {
            $status = "Failed";
            printLog("Microservice api failed.");
        }

        $events = ChannelType::where('id', $flow->channel_id)->first()->events()->pluck('name')->toArray(); //generating an array of all the events belong to flow channel id
        $campaign = Campaign::find($action_log->campaign_id);
        /**
         *  geting the next flow id according to the responce status from microservice
         */
        // if (empty($val))
        //     $status = 'Failed';
        // else
        //     $status = ucfirst($res->status);

        // Need to save response received from microservice. - TASK
        $action = ActionLog::where('id', $action_log->id)->first();
        $action->update(['status' => $status, "no_of_records" => $reqDataCount, 'ref_id' => $val, 'response' => $res]);

        printLog("We are here to create new action log as per module data", 1);

        $next_flow_id = null;
        if ($status == 'Success')
            $next_flow_id = isset($flow->module_data->op_success) ? $flow->module_data->op_success : null;
        else
            $next_flow_id = isset($flow->module_data->op_failed) ? $flow->module_data->op_failed : null;

        printLog('Get status from microservice ' . $status, 1);
        printLog("Enents are ", 1, $events);
        if (in_array($status, $events) && !empty($next_flow_id)) {
            printLog('Next flow id is ' . $next_flow_id, 1);
            $flow = FlowAction::where('campaign_id', $action_log->campaign_id)->where('id', $next_flow_id)->first();
            if (!empty($flow)) {
                printLog("Found next flow action.");
                $actionLogData = [
                    "campaign_id" => $action_log->campaign_id,
                    "no_of_records" => 0,
                    "response" => "",
                    "status" => "pending",
                    "report_status" => "pending",
                    "ref_id" => "",
                    "flow_action_id" => $next_flow_id,
                    "mongo_id" => $action_log->mongo_id,
                    'campaign_log_id' => $action_log->campaign_log_id
                ];
                printLog('Creating new action as per channel id ', 1);
                $actionLog = $campaign->actionLogs()->create($actionLogData);

                return $actionLog;
            } else {
                printLog("Didn't found next flow action.");
            }
        }
        return;
    }
    public function creteNextActionLog()
    {
    }
    public function getReports($actionLogId)
    {
        $actionLog = ActionLog::where('id', $actionLogId)->first();

        // create token
        $campaign = Campaign::where('id', $actionLog->campaign_id)->first();
        $input['company'] = $campaign->company()->first();
        config(['msg91.jwt_token' => createJWTToken($input)]);

        $channelId = FlowAction::where('id', $actionLog->flow_action_id)->pluck('channel_id')->first();

        $lib = setLibrary($channelId);

        $data = [];
        $collection = '';
        switch ($channelId) {
            case 1: {
                    $collection = 'email_report_data';
                    $data = ["unique_id" => $actionLog->ref_id];
                }
                break;
            case 2: {
                    $collection = 'msg91_report_data';
                    $data = $actionLog->ref_id;
                }
                break;
            case 3: {
                }
                break;
            case 4: {
                }
                break;
        }
        $res = $lib->getReports($data);

        $service = setService($channelId);

        $service->storeReport($res, $actionLog, $collection);
    }



    public function createNewJob($channel_id, $input,$delayTime)
    {
        //selecting the queue name as per the flow channel id
        switch ($channel_id) {
            case 1:
                $queue = 'run_email_campaigns';
                break;
            case 2:
                $queue = 'run_sms_campaigns';
                break;
            case 3:
                $queue = 'run_whastapp_campaigns';
                break;
            case 4:
                $queue = 'run_voice_campaigns';
                break;
        }
        // printLog('Rabbitmq lib we found '.$this->rabbitmq->connection_status, 1);
        if (empty($this->rabbitmq)) {
            $this->rabbitmq = new RabbitMQLib;
        }
        // $this->rabbitmq->enqueue($queue, $input);
        RabbitMQJob::dispatch($input)->onQueue($queue)->delay(Carbon::now()->addSeconds($delayTime)); //dispatching the job
    }
}
