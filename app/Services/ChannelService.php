<?php

namespace App\Services;

use App\Libs\EmailLib;
use App\Libs\MongoDBLib;
use App\Libs\OtpLib;
use App\Libs\SmsLib;
use App\Libs\VoiceLib;
use App\Libs\WhatsAppLib;
use App\Models\ActionLog;
use App\Models\Campaign;
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
    public function __construct()
    {
        $this->mongo = new MongoDBLib;
    }

    public function sendData($actionLogId)
    {
        Log::debug('For ' . $actionLogId);
        $action_log = ActionLog::where('id', $actionLogId)->first();

        /**
         * generating the token
         */
        $campaign = Campaign::find($action_log->campaign_id);
        $input['company'] = $campaign->company;
        config(['msg91.jwt_token' => createJWTToken($input)]);

        $flow = FlowAction::where('campaign_id', $action_log->campaign_id)->where('id', $action_log->flow_action_id)->first();


        // if ($flow['linked_type'] == 'App\Models\Condition') {
        //     $flow = FlowAction::where('campaign_id', $campid)->where('parent_id', $flow->id)->first()->toarray();
        //     $this->sendData($campid, $flow, $mongoid, $actionid);
        // } else {
        /**
         * geting the data from the mongoDB
         */
        $data = $this->mongo->collection('run_campaign_data')->findOne(collect($action_log->mongo_id));
        $md = json_decode(json_encode($data['data']));
        $lib = $this->setLibrary($flow['linked_id']);    //geting the object of the library
        $temp = Template::where('flow_action_id', $flow['id'])->first();
        $flag = 0;
        if ($flow['linked_id'] == 1) {
            $data = array(
                'to' => $md[0]->to,
                'from' => $md[0]->to,
                'cc' => $md[0]->cc,
                'bcc' => $md[0]->bcc,
                'variables' => $md[0]->variables
            );
            $flag = 1;
        } else {
            $data = [
                "flow_id" => "611f7d5744b035602c46cb47",
                'recipients' => $md[0]->mobiles
            ];
            $flag = 2;
        }

        $res = $lib->send($data);

        if ($flag == 1) {
            //
        } else if ($flag = 2) {
            $action = ActionLog::where('id', $action_log->id)->first();
            $action->update(['ref_id' => $res->message]);
        } else {
            //
        }

        $flow = FlowAction::where('campaign_id', $action_log->campaign_id)->where('parent_id', $flow->id)->first();
        if (!empty($flow)) {
            while ($flow['linked_type'] == 'App\Models\Condition') {
                $flow = FlowAction::where('campaign_id', $action_log->campaign_id)->where('parent_id', $flow->id)->first();
                if (empty($flow)) {
                    return;
                }
            }
            $actionLogData = [
                "no_of_records" => $action_log->no_of_records,
                "ip" => request()->ip(),
                "status" => "",
                "reason" => "",
                "ref_id" => "",
                "flow_action_id" => $flow->id,
                "mongo_id" => $action_log->mongo_id
            ];
            $action_log = $campaign->actionLogs()->create($actionLogData);
            Log::debug('Found next action log ' . $actionLogId);
            $this->sendData($action_log->id);
        } else {
            return;
        }
        // while ($flow['linked_type'] == 'App\Models\Condition') {
        //     $flow = FlowAction::where('campaign_id', $action_log->campaign_id)->where('parent_id', $flow->id)->first()->toarray();
        // if (empty($flow)) {
        //     return;
        // }
        // }
        // Log::debug('Found next action log ' . $flow->id);
        // if (!empty($flow)) {
        // $actionLogData = [
        //     "no_of_records" => $action_log->no_of_records,
        //     "ip" => request()->ip(),
        //     "status" => "",
        //     "reason" => "",
        //     "ref_id" => "",
        //     "flow_action_id" => $flow->id,
        //     "mongo_id" => $action_log->mongo_id
        // ];
        // $action_log = $campaign->actionLogs()->create($actionLogData);
        // }

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
