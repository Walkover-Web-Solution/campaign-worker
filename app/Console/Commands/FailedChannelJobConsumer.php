<?php

namespace App\Console\Commands;

use App\Jobs\RabbitMQJob;
use App\Libs\RabbitMQLib;
use Illuminate\Console\Command;

class FailedChannelJobConsumer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'enqueue:failedChannelJobs';

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
        dd($this->rabbitmq);

        $this->queue = 'run_email_campaigns';
        $this->rabbitmq->dequeue('failed_' . $this->queue, array($this, 'decodedData'));
        $this->queue = 'run_rcs_campaigns';
        $this->rabbitmq->dequeue('failed_' . $this->queue, array($this, 'decodedData'));
        $this->queue = 'run_sms_campaigns';
        $this->rabbitmq->dequeue('failed_' . $this->queue, array($this, 'decodedData'));
        $this->queue = 'condition_queue';
        $this->rabbitmq->dequeue('failed_' . $this->queue, array($this, 'decodedData'));
        $this->queue = 'run_whatsapp_campaigns';
        $this->rabbitmq->dequeue('failed_' . $this->queue, array($this, 'decodedData'));
        $this->queue = 'run_voice_campaigns';
        $this->rabbitmq->dequeue('failed_' . $this->queue, array($this, 'decodedData'));
    }

    public function decodedData($msg)
    {
        printLog("=============== We are in docodedData ===================", 2);
        try {
            $message = json_decode($msg->getBody(), true);
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
