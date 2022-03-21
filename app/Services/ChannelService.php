<?php

namespace App\Services;

use App\Libs\EmailLib;
use App\Libs\OtpLib;
use App\Libs\SmsLib;
use App\Libs\VoiceLib;
use App\Libs\WhatsAppLib;
use App\Models\FlowAction;

/**
 * Class ChannelService
 * @package App\Services
 */
class ChannelService
{
    public function sendData($campid,$flowid)
    {
        $flow = FlowAction::where('campaign_id',$campid)->where('id',$flowid)->first()->toarray();
        if($flow['linked_type']=='App\Models\Condition')
        {
            $flow = FlowAction::where('campaign_id',$campid)->where('parent_id',$flow['id'])->first()->toarray();
            $this->sendData($campid,$flow);
        }
        else
        {
            dd($flow['linked_id']);
            $lib=$this->setLibary($flow['linked_id']);
            $lib->send(1);
        }
    }

 

    public function setLibary($channel)
    {
        $email=1;$sms=2;$otp=3;$whatsapp=4;$voice=5;
        switch($channel)
        {
            case $email:
                return new EmailLib() ;
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
