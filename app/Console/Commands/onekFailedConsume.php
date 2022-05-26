<?php

namespace App\Console\Commands;

use App\Jobs\RabbitMQJob;
use App\Libs\RabbitMQLib;
use Illuminate\Console\Command;

class onekFailedConsume extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'onekfailed:consume';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will pick failed jobs and requeue in onek data queue.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (empty($this->rabbitmq)) {
            $this->rabbitmq = new RabbitMQLib;
        }
        $this->rabbitmq->dequeue('failed_1k_data_queue', array($this, 'decodedData'));
    }

    public function decodedData($msg)
    {
        printLog("=============== We are in docodedData of failed onek queue ===================", 2);
        try {
            // $message = json_encode($msg->getBody(),true);
            RabbitMQJob::dispatch($msg)->onQueue('1k_data_queue');
        } catch (\Exception $e) {
            $logData = [
                "exception" => $e->getMessage(),
                "stack" => $e->getTrace()
            ];
            
            printLog("Exception in failed onek", 5, $logData);
        }
        $msg->ack();
    }
}
