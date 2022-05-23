<?php

namespace App\Jobs;

use App\Services\ChannelService;
use App\Services\ConditionService;
use App\Services\RecordService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

    public function decodedData($msg)
    {
        
        printLog("=============== We are in docodedData ===================", 2);
        try {
            $logNameId = null;
            if ($this->queue == "1k_data_queue") {
                $log_id = $msg->campaignLogId;
                $logNameId = "campaign log id :" . $log_id;
                $recordService = new RecordService();
                $recordService->executeFlowAction($log_id);
            } else if ($this->queue == "condition_queue") {
                $log_id = $msg->action_log_id;
                $logNameId = "condition flow action log id :" . $log_id;
                $channelService = new ConditionService();
                $channelService->handleCondition($log_id);
            } else {
                $log_id = $msg->action_log_id;
                $logNameId = "flow action log id :" . $log_id;
                $channelService = new ChannelService();
                $channelService->sendData($log_id);
            }
        } catch (\Exception $e) {

            $failed_queue = 'failed_' . $this->queue;

            $logData = [
                "actionLog" => $logNameId,
                "exception" => $e->getMessage(),
                "stack" => $e->getTrace()
            ];
            logTest($failed_queue, $logData);
            printLog("Exception in" . $failed_queue, 1, $logData);
            $failedJob = (new RabbitMQJob($msg))->onQueue($failed_queue);
            dispatch($failedJob);
            if (env('APP_ENV') == 'local') {
                $job = (new RabbitMQJob($msg))->onQueue($failed_queue)->onConnection('rabbitmqlocal');
                dispatch($job); //dispatching the job
            } else {
                $job = (new RabbitMQJob($msg))->onQueue($failed_queue);
                dispatch($job);
            }
        }
    }
}
