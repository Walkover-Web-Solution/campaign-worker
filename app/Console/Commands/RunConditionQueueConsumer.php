<?php

namespace App\Console\Commands;

use App\Libs\RabbitMQLib;
use App\Services\ConditionService;
use Illuminate\Console\Command;

class runConditionQueueConsumer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'condition:consume';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'The command is use to consume and handle the condition queue';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function handle()
    {
        if (empty($this->rabbitmq)) {
            $this->rabbitmq = new RabbitMQLib;
        }
        $this->rabbitmq->dequeue('condition_queue', array($this, 'decodedData'));
    }

    public function decodedData($msg)
    {
        try {
            printLog("======== Found job in condition queue ========", 2);
            $message = json_decode($msg->getBody(), true);
            // printLog("Decoding data from job ", 1, (array)$message);
            // $message = json_decode($msg->getBody(), true);
            // $obj = $message['data']['command'];
            // $action_log_id = unserialize($obj)->data->action_log_id;
            $action_log_id=$message['action_log_id'];
            $channelService = new ConditionService();
            $channelService->handleCondition($action_log_id);
        } catch (\Exception $e) {
            if (empty($this->rabbitmq)) {
                $this->rabbitmq = new RabbitMQLib;
            }
            $logData = [
                "actionLog" => $action_log_id,
                "exception" => $e->getMessage(),
                "stack" => $e->getTrace()
            ];
            logTest("failed job consition", $logData);
            printLog("Found exception in run sms ", 1,  $logData);

            $this->rabbitmq->putInFailedQueue('failed_condition_queue', $msg->getBody());
        }
        $msg->ack();
    }
}
