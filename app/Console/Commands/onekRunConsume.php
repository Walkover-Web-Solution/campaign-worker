<?php

namespace App\Console\Commands;

use App\Libs\RabbitMQLib;
use App\Services\RecordService;
use Illuminate\Console\Command;

class onekRunConsume extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'onek:consume';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'To consume the queue have record less than 1k data';
    protected $rabbitmq;
    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {

        parent::__construct();
    }

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
        $this->rabbitmq->dequeue('1k_data_queue', array($this, 'decodedData'));
    }
    public function decodedData($msg)
    {
        try {
            $message = json_decode($msg->getBody(), true);
            $obj = $message['data']['command'];
            $campLogId = unserialize($obj)->data->campaignLogId;
            $recordService = new RecordService();
            $recordService->pickFlowAction($campLogId);
        } catch (\Exception $e) {
            dd($e->getMessage());
            $logData = [
                "actionLog" => $campLogId,
                "exception" => $e->getMessage()
            ];
            logTest("failed_1k_queue", $logData);
            if (empty($this->rabbitmq)) {
                $this->rabbitmq = new RabbitMQLib;
            }
            $this->rabbitmq->putInFailedQueue('failed_1k_data_queue', $msg->getBody());
        }
        $msg->ack();
    }
}
