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
    public function sendData($campid){

        $flow = FlowAction::where('campaign_id',$campid)->get()->toarray();
        // dd($flow);
        collect($flow)->map(function($item){
            if($item['linked_type']=='App\Models\ChannelType')
            {
                $lib=$this->setLibary($item['linked_id']);
                $lib->send(1);
            }
            else{
                
            }
        });
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
