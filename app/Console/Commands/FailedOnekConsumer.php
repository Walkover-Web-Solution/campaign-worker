<?php

namespace App\Console\Commands;

use App\Jobs\RabbitMQJob;
use App\Libs\RabbitMQLib;
use Illuminate\Console\Command;

class FailedOnekConsumer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'enqueue:failedOnek';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';
    protected $rabbitmq;
    protected $queue;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->rabbitmq = RabbitMQLib::getInstance();

        $this->queue = '1k_data_queue';
        $this->rabbitmq->dequeue('failed_' . $this->queue, array($this, 'decodedData'));
    }

    public function decodedData($msg)
    {
        printLog("=============== We are in docodedData of Failed Job Consumer ===================", 2);
        try {
            $message = json_decode($msg->getBody(), true);
            if (empty($message['data'])) {
                return;
            }
            $obj = $message['data']['command'];
            $failedCount = ++unserialize($obj)->data->failedCount;
            $campLogId = unserialize($obj)->data->campaignLogId;
            $input = new \stdClass();
            $input->campaignLogId = $campLogId;
            $input->failedCount = $failedCount;
            RabbitMQJob::dispatch($input)->onQueue($this->queue);
        } catch (\Exception $e) {
            $logData = [
                "actionLog" => $campLogId,
                "exception" => $e->getMessage(),
                "stack" => $e->getTrace()
            ];
            logTest("failed job " . $this->queue, $logData);
            printLog("Exception in " . $this->queue, 1, $logData);

            // Feed into database
            storeFailedJob('FAILED - ' . $e->getMessage(), $campLogId, $this->queue, $message);
            // $this->rabbitmq = RabbitMQLib::getInstance();
            // $this->rabbitmq->putInFailedQueue('failed_' . $this->queue, $msg->getBody());
        } finally {
            $msg->ack();
        }
    }
}
