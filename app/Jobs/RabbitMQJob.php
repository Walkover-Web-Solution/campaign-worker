<?php

namespace App\Jobs;

use App\Models\ActionLog;
use App\Services\ChannelService;
use App\Services\ConditionService;
use App\Services\EventService;
use App\Services\RecordService;
use App\Services\SlackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

class RabbitMQJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public $data;
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->decodedData($this->data);
    }

    protected function decodedData($msg)
    {
        printLog("=============== We are in docodedData ===================", 2);
        try {
            $log_id = null;
            $failedCount = empty($msg->failedCount) ? 0 : $msg->failedCount;
            switch ($this->queue) {
                case "1k_data_queue": {
                        $log_id = $msg->campaignLogId;
                        $recordService = new RecordService();
                        $recordService->executeFlowAction($log_id);
                        break;
                    }
                case "failed_1k_data_queue": {
                        $log_id = $msg->campaignLogId;
                        $msg->failedCount++;
                        createNewJob($msg, "1k_data_queue");
                        break;
                    }
                case "run_email_campaigns": {
                        $log_id = $msg->action_log_id;
                        $channelService = new ChannelService();
                        $channelService->sendData($log_id);
                        break;
                    }
                case "failed_run_email_campaigns": {
                        $log_id = $msg->action_log_id;
                        $msg->failedCount++;
                        createNewJob($msg, "run_email_campaigns");
                        break;
                    }
                case "run_sms_campaigns": {
                        $log_id = $msg->action_log_id;
                        $channelService = new ChannelService();
                        $channelService->sendData($log_id);
                        break;
                    }
                case "failed_run_sms_campaigns": {
                        $log_id = $msg->action_log_id;
                        $msg->failedCount++;
                        createNewJob($msg, "run_sms_campaigns");
                        break;
                    }
                case "run_rcs_campaigns": {
                        $log_id = $msg->action_log_id;
                        $channelService = new ChannelService();
                        $channelService->sendData($log_id);
                        break;
                    }
                case "failed_run_rcs_campaigns": {
                        $log_id = $msg->action_log_id;
                        $msg->failedCount++;
                        createNewJob($msg, "run_rcs_campaigns");
                        break;
                    }
                case "run_voice_campaigns": {
                        $log_id = $msg->action_log_id;
                        $channelService = new ChannelService();
                        $channelService->sendData($log_id);
                        break;
                    }
                case "failed_run_voice_campaigns": {
                        $log_id = $msg->action_log_id;
                        $msg->failedCount++;
                        createNewJob($msg, "run_voice_campaigns");
                        break;
                    }
                case "run_whastapp_campaigns": {
                        $log_id = $msg->action_log_id;
                        $channelService = new ChannelService();
                        $channelService->sendData($log_id);
                        break;
                    }
                case "failed_run_whastapp_campaigns": {
                        $log_id = $msg->action_log_id;
                        $msg->failedCount++;
                        createNewJob($msg, "run_whastapp_campaigns");
                        break;
                    }
                case "condition_queue": {
                        $log_id = $msg->action_log_id;
                        $channelService = new ConditionService();
                        $channelService->handleCondition($log_id);
                        break;
                    }
                case "failed_condition_queue": {
                        $log_id = $msg->action_log_id;
                        $msg->failedCount++;
                        createNewJob($msg, "condition_queue");
                        break;
                    }
                case "event_processing": {
                        $log_id = $msg->eventMongoId; // Event mongo id is log_id for event processing in catch below
                        $eventService = new EventService();
                        $eventService->processEvent($msg->eventMongoId);
                        break;
                    }
                case "failed_event_processing": {
                        $log_id = $msg->eventMongoId; // Event mongo id is log_id for event processing in catch below
                        $msg->failedCount++;
                        createNewJob($msg, "event_processing");
                        break;
                    }
                case "email_to_campaign_logs": {
                        $log_id = $msg->request_id; // Event request_id is ref_id for event processing
                        $actionLog = ActionLog::where('ref_id', $log_id)->first();
                        $eventService = new EventService();
                        $eventService->processEvent($actionLog, $msg, true);
                        break;
                    }
                case "failed_email_to_campaign_logs": {
                        $log_id = $msg->request_id; // Event request_id is ref_id for event processing
                        $msg->failedCount++;
                        createNewJob($msg, "email_to_campaign_logs");
                        break;
                    }
                default: {
                        //
                    }
            }
        } catch (\Exception $e) {

            $failed_queue = 'failed_' . $this->queue;

            $logData = [
                "Log_id" => 'LogId : ' . $log_id,
                "exception" => $e->getMessage(),
                "stack" => $e->getTrace()
            ];
            logTest($failed_queue, $logData);
            printLog("Exception in" . $failed_queue, 1, $logData);

            // Send error to Slack
            $slack = new SlackService();
            $error = array(
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'lineNo' => $e->getLine(),
                'file' => $e->getFile(),
                'input' => json_encode($msg)
            );
            $slack->sendErrorToSlack((object)$error);

            if ($failedCount >= 3) {
                storeFailedJob($e->__toString(), $log_id, $this->queue, $msg, $this->connection);
                return;
            }

            createNewJob($msg, $failed_queue);
        }
    }
}
