<?php

namespace App\Services;

use App\Jobs\RabbitMQJob;
use App\Libs\EmailLib;
use App\Libs\MongoDBLib;
use App\Libs\OtpLib;
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
    }

    public function sendData($actionLogId)
    {
        printLog("----- Lets process action log ----------", 2);
        $action_log = ActionLog::where('id', $actionLogId)->first();

        /**
         * generating the token
         */
        $campaign = Campaign::find($action_log->campaign_id);
        $input['company'] = $campaign->company;
        config(['msg91.jwt_token' => createJWTToken($input)]);

        printLog("Till now we found Campaign, and created JWT. And now about to find flow action.", 2);
        $flow = FlowAction::where('campaign_id', $action_log->campaign_id)->where('id', $action_log->flow_action_id)->first();

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

        printLog("generating the request body data according to flow channel id.", 2);
        $data = $this->getRequestBody($flow, $convertedData);
        /**
         * Geting the libary object according to the flow channel id to send the data to the microservice
         */
        $lib = $this->setLibrary($flow['channel_id']);
        $res = $lib->send($data);
        /**
         * updating the response comes from the microservice into the ref_id of current flow action
         */
        printLog('We have successfully send data to SMS.', 1, empty($res) ? 'NULL RESPONSE' : (array)$res);

        $new_action_log = $this->updateActionLogResponse($flow, $action_log, $res);
        // printLog('Got new action log and its id is ' . $new_action_log->id, 1);
        if (!empty($new_action_log)) {
            printLog("Now creating new job for action log.", 1);
            $input = new \stdClass();
            $input->action_log_id =  $new_action_log->id;
            $channel_id = FlowAction::where('id', $new_action_log->flow_action_id)->pluck('channel_id')->first();
            $this->createNewJob($channel_id, $input);
        }
        return;
    }

    public function setLibrary($channel)
    {
        $email = 1;
        $sms = 2;
        $otp = 3;
        $whatsapp = 4;
        $voice = 5;
        switch ($channel) {
            case $email:
                return new EmailLib();
            case $sms:
                return new SmsLib();
            case $otp:
                return new OtpLib();
            case $whatsapp:
                return new WhatsAppLib();
            case $voice:
                return new VoiceLib();
        }
    }

    public function getRequestBody($flow, $md)
    {
        /**
         * extracting the all the variables from the mongo data
         */
        $var = $md['variables'];

        // get template of this flowAction
        $temp = $flow->template;

        //filter out variables of this flowActions template
        $variables = collect($var)->map(function ($value, $key) use ($temp) {
            if (in_array($key, $temp->variables)) {
                return $value;
            }
        });
        $variables = array_filter($variables->toArray());

        $mongo_data = $md;
        switch ($flow['channel_id']) {
            case 1:
                $obj = new \stdClass();
                $obj->values = [];
                collect($flow["configurations"])->map(function ($item) use ($obj) {
                    if ($item->name != 'template')
                        $obj->values[$item->name] = $item->value;
                });
                if (!empty($mongo_data['emails']['cc'])) {
                    $cc = $mongo_data['emails']['cc'];
                } else {
                    $cc = stringToJson($obj->values['cc']);
                }
                if (!empty($mongo_data['emails']['bcc'])) {
                    $bcc = $mongo_data['emails']['bcc'];
                } else {
                    $bcc = stringToJson($obj->values['bcc']);
                }
                $domain = empty($obj->values['parent_domain']) ? $obj->values['domain'] : $obj->values['parent_domain'];
                //$domain = $obj->values['domain'];
                $email = $obj->values['from_email'] . "@" . $domain;
                $from = [
                    "name" => $obj->values['from_email_name'],
                    "email" => $email
                ];
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
                break;
            case 2:
                $data = [
                    "flow_id" => $temp->template_id,
                    'recipients' => $mongo_data['mobiles'],
                    "short_url" => true
                ];
                break;
            case 3:
                //
                break;
        }
        $data = json_decode(collect($data));
        return $data;
    }

    public function updateActionLogResponse($flow, $action_log, $res)
    {

        printLog("Now sending data to microservice", 1);

        $val = "";
        if ($flow->channel_id == 1 && !empty($res)) {
            $val = $res->data->unique_id;
        } else if ($flow->channel_id == 2 && !empty($res)) {
            $val = $res->data;
        } else {
            //
        }
        $action = ActionLog::where('id', $action_log->id)->first();
        if (!empty($var))
            $action->update(['ref_id' => $val]);
        $channel = ChannelType::where('id', $flow->channel_id)->first();
        $conditions = $channel->conditions()->pluck('name')->toArray(); //generating an array of all the condition belong to flow channel id
        $campaign = Campaign::find($action_log->campaign_id);
        /**
         *  geting the next flow id according to the responce status from microservice
         */

        if (isset($flow->module_data->op_success) || isset($flow->module_data->op_failure)) {
            printLog("We are here to create new action log as per module data", 1);
            if (empty($val))
                $status = 'Failure';
            else
                $status = ucfirst($res->status);
            $next_flow_id = null;
            if ($status == 'Success')
                $next_flow_id = $flow->module_data->op_success;
            else
                $next_flow_id = $flow->module_data->op_failure;

            $action->update(['status' => $status]);
            printLog('Get status from microservice ' . $status, 1);
            printLog("Conditions are ", 1, $conditions);
            if (in_array($status, $conditions) && !empty($next_flow_id)) {
                printLog('Next flow id is ' . $next_flow_id, 1);
                $flow = FlowAction::where('campaign_id', $action_log->campaign_id)->where('id', $next_flow_id)->first();
                if (!empty($flow)) {
                    printLog("Found next flow action.");
                    $actionLogData = [
                        "campaign_id" => $action_log->campaign_id,
                        "no_of_records" => $action_log->no_of_records,
                        "ip" => request()->ip(),
                        "status" => "pending",
                        "reason" => "pending",
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

        $lib = $this->setLibrary($channelId);

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
            case 5: {
                }
                break;
        }
        $res = $lib->getReports($data);

        $service = $this->setService($channelId);

        $service->storeReport($res, $actionLog, $collection);
    }



    public function createNewJob($channel_id, $input)
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
                $queue = 'run_otp_campaigns';
                break;
            case 4:
                $queue = 'run_whastapp_campaigns';
                break;
            case 5:
                $queue = 'run_voice_campaigns';
                break;
        }
        // printLog('Rabbitmq lib we found '.$this->rabbitmq->connection_status, 1);
        if (empty($this->rabbitmq)) {
            $this->rabbitmq = new RabbitMQLib;
        }
        $this->rabbitmq->enqueue($queue, $input);
        // RabbitMQJob::dispatch($input)->onQueue($queue); //dispatching the job
    }
    public function setService($channel)
    {
        $email = 1;
        $sms = 2;
        $otp = 3;
        $whatsapp = 4;
        $voice = 5;
        switch ($channel) {
            case $email:
                return new EmailService();
            case $sms:
                return new SmsService();
            case $otp:
                return new OtpService();
            case $whatsapp:
                return new WhatsappService();
            case $voice:
                return new VoiceService();
        }
    }
}
