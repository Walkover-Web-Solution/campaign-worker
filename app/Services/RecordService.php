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
use Illuminate\Support\Facades\Log;

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
        printLog("We are here to find flow action and to execute it", 2);
        $camplog = CampaignLog::where('id', $campLogId)->first();
        $camp = Campaign::where('id', $camplog->campaign_id)->first();
        // In case if campaign deleted by user
        if (empty($camp)) {
            printLog("xxxxxxxxxxxxxxxx Campaign not found for campaign log id xxxxxxxxxxxxxxxxxx", 7);
            throw new Exception("No campaign found.");
        }

        printLog("----- We found Campaign here -----", 2);
        printLog("Now moving forward to get flowactions for campaign", 2);
        $allFlow = FlowAction::select('channel_id')->where('campaign_id', $camp->id)->get();
        $flow = FlowAction::where('id', $camp->module_data['op_start'])->first();
        // In case of flowaction deleted by user
        if (empty($flow)) {
            printLog("No flow actions found.", 5);
            throw new Exception("No flowaction found.");
        }

        printLog("Now fetching for mongo data.", 2);
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
            $actionLogData = [
                "no_of_records" => $camplog['no_of_contacts'],
                "ip" => request()->ip(),
                "status" => "pending",
                "reason" => "pending",
                "ref_id" => "",
                "flow_action_id" => $flow->id,
                "mongo_id" => $reqId,
                'campaign_log_id' => $camplog->id
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
        printLog("About to create job for " . $queue, 1);
        if (empty($this->rabbitmq)) {
            $this->rabbitmq = new RabbitMQLib;
        }
        $this->rabbitmq->enqueue($queue, $input);
        printLog("'================= Created Job in " . $queue . " =============", 1);
        // RabbitMQJob::dispatch($input)->onQueue($queue); //dispatching the job
    }
}
