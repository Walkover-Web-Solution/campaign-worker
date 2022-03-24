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

    public function sendData($campid, $flowid, $mongoid, $actionid)
    {

        $flow = FlowAction::where('campaign_id', $campid)->where('id', $flowid)->first();
        if ($flow['linked_type'] == 'App\Models\Condition') {
            $flow = FlowAction::where('campaign_id', $campid)->where('parent_id', $flow['id'])->first()->toarray();
            $this->sendData($campid, $flow, $mongoid, $actionid);
        } else {
            /**
             * geting the data from the mongoDB
             */
            $data = $this->mongo->collection('run_campaign_data')->findOne(collect($mongoid));
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

            }
            else if ($flag = 2)
                ActionLog::where('campaign_id', $campid)->where('flow_action_id', $flowid)->update(['ref_id' => $res->message]);
            else {
            }
            dd("hello");
        }
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
