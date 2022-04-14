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
        $channelHas = collect($allFlow)->pluck('channel_id')->toArray();
        $emailCount = 0;
        $mobileCount = 0;
        $sendto = collect($md[0]->data->sendTo)->map(function ($item) use ($flow, $channelHas,$emailCount,$mobileCount) {
            $obj = new \stdClass();
            $obj->values = [];
            collect($flow["configurations"])->map(function ($item) use ($obj) {
                $key = $item->name;
                if ($key != 'template')
                    $obj->values[$key] = $item->value;
            });
            $variables = collect($item->variables)->toArray();
            $emails = null;
            $mobiles = null;

            if (in_array(1, $channelHas)) {

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
                $to=makeEmailBody($item->to);
                $emails = [
                    "to" => $to,
                    "cc" => $cc,
                    "bcc" => $bcc,
                ];
                $emailCount = count($to) + count($cc) + count($bcc);
            }
            if (in_array(2, $channelHas)) {

                $mobiles = makeMobileBody($item);
                $mobileCount = count($mobiles);

            }
            $data = [
                "emails" => $emails,
                "mobiles" => $mobiles,
                "variables" => $variables
            ];
            $data = array_filter($data);

            return ($data);
        })->toJson();
        $sendTo = json_decode($sendto);
        collect($sendTo)->map(function ($item) use ($flow, $camplog, $camp ,$emailCount,$mobileCount) {
            $reqId = preg_replace('/\s+/', '',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
            $data = [
                'requestId' => $reqId,
                'data' => $item
            ];
            $mongoId = $this->mongo->collection('flow_action_data')->insertOne($data);
            $no_of_records = $emailCount + $mobileCount;
            // insert data in ActionLogs table
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
