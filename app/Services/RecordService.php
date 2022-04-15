<?php

namespace App\Services;

use App\Jobs\RabbitMQJob;
use App\Libs\MongoDBLib;
use App\Libs\RabbitMQLib;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\FlowAction;
use Carbon\Carbon;
use Exception;

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

    /**
     * This function will fetch start point (flowaction) of any campaign using campaign log id
     * Then from bulk data breakdown data as per flowaction.
     * Then generate action log for that flowaction and create job for same
     */
    public function executeFlowAction($campLogId)
    {
        $camplog = CampaignLog::where('id', $campLogId)->first();
        $camp = Campaign::where('id', $camplog->campaign_id)->first();
        // In case if campaign deleted by user
        if (empty($camp))
            throw new Exception("No campaign found.");
        $allFlow = FlowAction::select('channel_id')->where('campaign_id', $camp->id)->get();
        $flow = FlowAction::where('id', $camp->module_data['op_start'])->first();
        // In case of flowaction deleted by user
        if (empty($flow))
            throw new Exception("No flowaction found.");
        $data = $this->mongo->collection('run_campaign_data')->find([
            'requestId' => $camplog['mongo_uid']
        ]);
        $md = json_decode(json_encode($data));
        collect($md[0]->data->sendTo)->map (function($item) use ($camplog,$flow,$camp){
            $reqId = preg_replace('/\s+/', '',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
            $data = [
                'requestId' => $reqId,
                'data' => $item
            ];
            $mongoId = $this->mongo->collection('flow_action_data')->insertOne($data);
            $no_of_records = $camplog->sms_records + $camplog->email_records;
            $actionLogData = [
                "no_of_records" => $no_of_records,
                "ip" => request()->ip(),
                "status" => "pending",
                "reason" => "",
                "ref_id" => "",
                "flow_action_id" => $flow->id,
                "mongo_id" => $reqId,
                'uid' => $camplog->id
            ];
            $actionLog = $camp->actionLogs()->create($actionLogData);
            if (!empty($actionLog)) {
                $input = new \stdClass();
                $input->action_log_id =  $actionLog->id;
                $this->createNewJob($flow->channel_id, $input);
            }
        });
        /*$obj=new \stdClass();
        $obj->emailCount = 0;
        $obj->mobileCount = 0;
        $obj->emails=[];
        $obj->mobiles=[];
        $obj->hasChannel=collect($allFlow)->pluck('channel_id')->unique()->toArray();
        $sendto = collect($md[0]->data->sendTo)->map(function ($item) use ($flow, $obj) {

            $variables = collect($item->variables)->toArray();
            $emails = null;
            $mobiles = null;

            collect($obj->hasChannel)->map(function ($channel) use ($flow,$item,$obj) {
                switch ($channel) {
                    case 1:
                        $cc=[];
                        $bcc=[];
                        if (isset($item->cc)) {
                            $cc = makeEmailBody($item->cc);
                        }
                        if (isset($item->bcc)) {
                            $bcc = makeEmailBody($item->bcc);
                        }
                        $to = makeEmailBody($item->to);
                        $obj->emails = [
                            "to" => $to,
                            "cc" => $cc,
                            "bcc" => $bcc,
                        ];

                        $obj->emailCount = count($to) + count($cc) + count($bcc);
                        break;
                    case 2:
                        $obj->mobiles = makeMobileBody($item);
                        $obj->mobileCount = count($obj->mobiles);
                        break;
                }
            });
            $data = [
                "emails" => $obj->emails,
                "mobiles" => $obj->mobiles,
                "variables" => $variables
            ];

            return ($data);
        })->toJson();*/
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
        RabbitMQJob::dispatch($input)->onQueue($queue); //dispatching the job
    }
}
