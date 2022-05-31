<?php

namespace App\Services;

use App\Jobs\RabbitMQJob;
use App\Libs\RabbitMQLib;

/**
 * Class ConsumerService
 * @package App\Services
 */
class ConsumerService
{
    public $queue;

    public function decodedData($msg)
    {
        printLog("=============== We are in docodedData Failed Channel Job ===================", 2);
        printLog("====== Queue name === " . $this->queue, 2);
        try {
            $message = json_decode($msg->getBody(), true);
            if (empty($message['data'])) {
                $msg->ack();
                return;
            }
            $obj = $message['data']['command'];
            $actionLogId = unserialize($obj)->data->action_log_id;
            if (empty($actionLogId)) {
                //
            }
            $input = new \stdClass();
            $input->action_log_id = $actionLogId;
            RabbitMQJob::dispatch($input)->onQueue($this->queue);
        } catch (\Exception $e) {
            $logData = [
                "actionLog" => $actionLogId,
                "exception" => $e->getMessage(),
                "stack" => $e->getTrace()
            ];
            logTest("failed job 1k", $logData);
            printLog("Exception in onek", 1, $logData);

            $this->rabbitmq = RabbitMQLib::getInstance();
            $this->rabbitmq->putInFailedQueue('failed_' . $this->queue, $msg->getBody());
        }
        $msg->ack();
    }
}
