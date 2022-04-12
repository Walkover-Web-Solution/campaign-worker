<?php

namespace App\Services;

use App\Libs\MongoDBLib;
use App\Libs\RabbitMQLib;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\FlowAction;

/**
 * Class RecordService
 * @package App\Services
 */
class RecordService
{
    protected $mongo;
    protected $rabbitmq;
    public function __construct()
    {
        $this->mongo = new MongoDBLib;
        $this->rabbitmq = new RabbitMQLib;
    }

    public function pickFlowAction($campLogId)
    {
        $camplog = CampaignLog::where('id', $campLogId)->first();
        $camp = Campaign::where('id', $camplog->campaign_id)->first();
        $flow = FlowAction::where('id', $camp->module_data['op_start'])->first();
        $data = $this->mongo->collection('run_campaign_data')->find([
            'requestId' => $camplog['mongo_uid']
        ]);
        $md = json_decode(json_encode($data));
        collect($md[0]->data->sendTo)->map(function ($item)use ($flow){
            switch($flow['channel_id']){
                case 1:
                    $arr=array_merge(collect($item->to)->pluck('email',)->toArray(),collect($item->cc)->pluck('email')->toArray(),collect($item->bcc)->pluck('email')->toArray());
                    dd($arr);
                    $countEmail = count(collect($item['to'])->pluck('email')) + count(collect($item['cc'])->pluck('email')) + count(collect($item['bcc'])->pluck('email'));

            }
            $countEmail = count(collect($item['to'])->pluck('email')) + count(collect($item['cc'])->pluck('email')) + count(collect($item['bcc'])->pluck('email'));
            $countMobile = count(collect($item['to'])->pluck('mobile')) + count(collect($item['cc'])->pluck('mobile')) + count(collect($item['bcc'])->pluck('mobile'));
            return ($countEmail + $countMobile);
        });
        // insert data in ActionLogs table
        $actionLogData = [
            "no_of_records" => "",
            "ip" => request()->ip(),
            "status" => "pending",
            "reason" => "",
            "ref_id" => "",
            "flow_action_id" => $flow->id,
            "mongo_id" => $camplog->mongo_uid,
            'uid' => $camplog->id
        ];
    }
}
