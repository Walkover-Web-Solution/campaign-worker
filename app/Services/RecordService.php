<?php

namespace App\Services;

use App\Jobs\RabbitMQJob;
use App\Libs\MongoDBLib;
use App\Libs\RabbitMQLib;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\FlowAction;
use Carbon\Carbon;

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
        if (empty($camp))
            return;
        $flow = FlowAction::where('id', $camp->module_data['op_start'])->first();
        if (empty($flow))
            return;
        $data = $this->mongo->collection('run_campaign_data')->find([
            'requestId' => $camplog['mongo_uid']
        ]);
        $md = json_decode(json_encode($data));
        $sendto = collect($md[0]->data->sendTo)->map(function ($item) use ($flow) {
            $obj = new \stdClass();
            $obj->values = [];
            collect($flow["configurations"])->map(function ($item) use ($obj) {
                $key = $item->name;
                if ($key != 'template')
                    $obj->values[$key] = $item->value;
            });
            if (isset($obj->values['cc']))
                $cc = stringToJson($obj->values['cc']);
            if (isset($obj->values['bcc']))
                $bcc = stringToJson($obj->values['bcc']);
            if (isset($item->cc) || (isset($obj->values['cc']) && isset($item->cc))) {
                $cc = makeEmailBody($item->cc);
            }

            if (isset($item->bcc) || (isset($obj->values['bcc']) && isset($item->bcc))) {
                $bcc = makeEmailBody($item->bcc);
            }
            $variables = collect($item->variables)->toArray();
            $data = [
                "emails" => [
                    "to" => makeEmailBody($item->to),
                    "cc" => $cc,
                    "bcc" => $bcc,
                ],
                "mobiles" => makeMobileBody($item),
                "variables" => $variables
            ];

            return ($data);
        })->toJson();
        $sendTo = json_decode($sendto);
        collect($sendTo)->map(function ($item) use ($flow, $camplog, $camp) {
            $reqId = preg_replace('/\s+/', '',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
            $data = [
                'requestId' => $reqId,
                'data' => $item
            ];
            $mongoId = $this->mongo->collection('flow_action_data')->insertOne($data);
            // insert data in ActionLogs table
            $actionLogData = [
                "no_of_records" => "",
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
