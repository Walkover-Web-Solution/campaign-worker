<?php

namespace App\Services;

use App\Libs\EmailLib;
use App\Libs\MongoDBLib;
use App\Libs\OtpLib;
use App\Libs\RabbitMQLib;
use App\Libs\SmsLib;
use App\Libs\VoiceLib;
use App\Libs\WhatsAppLib;
use App\Models\ActionLog;
use App\Models\Campaign;
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
        $this->mongo = new MongoDBLib;
         $this->rabbitmq = new RabbitMQLib;
    }

    public function sendData($actionLogId)
    {

        $action_log = ActionLog::where('id', $actionLogId)->first();

        /**
         * generating the token
         */
        $campaign = Campaign::find($action_log->campaign_id);
        $input['company'] = $campaign->company;

        config(['msg91.jwt_token' => createJWTToken($input)]);

        $flow = FlowAction::where('campaign_id', $action_log->campaign_id)->where('id', $action_log->flow_action_id)->first();
        $data = $this->mongo->collection('run_campaign_data')->find([
            'action_log_id' => $action_log->id
        ]);

        $md = json_decode(json_encode($data));
        $lib = $this->setLibrary($flow['channel_id']);    //geting the object of the library
        $temp = Template::where('flow_action_id', $flow['id'])->first();

        $var = collect($md[0]->data);
        unset($var['emails']);
        unset($var['mobiles']);
        unset($var['mobile']);

        $variables = collect($var)->map(function ($value, $key) use ($temp) {
            if (in_array($key, $temp->variables)) {
                return $value;
            }
        });

        $variables = array_filter($variables->toArray());

        $flag = 0;
        $obj = new \stdClass();
        $obj->data = [];
        $mongo_data = collect($md[0]->data);
        $channel = ChannelType::where('id', $flow['channel_id'])->first();
        $conditions = $channel->conditions()->pluck('name')->toArray();
        switch ($flow['channel_id']) {
            case 1:
                array_push($obj->data, $mongo_data['emails']);
                $data = array(
                    'variables' => $variables,
                    'template_id' => $temp->template_id
                );
                $next_flow_id = null;
                $data = array_merge(collect($obj->data[0])->toArray(), $data);
                if (!empty($flow->module_data->op_success))
                    $next_flow_id = $flow->module_data->op_success;
                else if (!empty($flow->module_data->op_failure))
                    $next_flow_id = $flow->module_data->op_failure;
                $flag = 1;
                break;
            case 2:
                array_push($obj->data, $mongo_data['mobiles']);
                $data = [
                    "flow_id" => $temp->template_id,
                    'recipients' => $obj->data[0]
                ];
                $flag = 2;
                break;
            case 3:
                array_push($obj->data, $mongo_data['mobile']); //for otp
                break;
        }
        $res = $lib->send($data);
        if ($flag == 1) {
            $action = ActionLog::where('id', $action_log->id)->first();
            $action->update(['ref_id' => $res->data->unique_id]);
        } else if ($flag = 2) {
            $action = ActionLog::where('id', $action_log->id)->first();
            $action->update(['ref_id' => $res->data]);
        } else {
            //
        }
        $res = ucfirst('success');
        if (in_array($res, $conditions) && $next_flow_id != null) {
            $flow = FlowAction::where('campaign_id', $action_log->campaign_id)->where('id', $next_flow_id)->first();
            if (!empty($flow)) {
                $actionLogData = [
                    "campaign_id" => $action_log->campaign_id,
                    "no_of_records" => $action_log->no_of_records,
                    "ip" => request()->ip(),
                    "status" => "",
                    "reason" => "",
                    "ref_id" => "",
                    "flow_action_id" => $flow->module_data->op_success,
                    "mongo_id" => $action_log->mongo_id
                ];
                $actionLog = $campaign->actionLogs()->create($actionLogData);
                if ($action_log->mongo_id->filled('data')) {
                    $data = [
                        'action_log_id' => $actionLog->id,
                        'data' => $action_log->mongo_id
                    ];
                    // insert into mongo
                    $mongo_id = $this->mongo->collection('run_campaign_data')->insertOne($data);
                }


                $actionLog->mongo_id = $mongo_id;
                $actionLog->save();

                \JOB::processRunCampaign($actionLog);
            }
        }

        //1. get all the codition according to channel
        //2. compare with the module data and response from $res;
        //3. fetch the flow_action according to the condition comes from step 2
        //goto 4th step
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
}
