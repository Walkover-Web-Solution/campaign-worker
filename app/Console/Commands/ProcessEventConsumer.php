<?php

namespace App\Console\Commands;

use App\Libs\RabbitMQLib;
use App\Services\EventService;
use Illuminate\Console\Command;

class ProcessEventConsumer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';
    protected $rabbitmq;

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
        $this->rabbitmq->dequeue('event_processing', array($this, 'decodedData'));
    }
    public function decodedData($msg)
    {
        try {
            printLog("======== Found job in event_processing queue ========", 2);
            $message = json_decode($msg->getBody(), true);
            $obj = $message['data']['command'];
            $eventMongoId = unserialize($obj)->data->eventMongoId;
            $channelService = new EventService();
            $channelService->processEvent($eventMongoId);
        } catch (\Exception $e) {
            $logData = [
                "eventMongoId" => $eventMongoId,
                "exception" => $e->getMessage(),
                "stack" => $e->getTrace()
            ];
            logTest("failed job email", $logData);
            printLog("Found exception in event process ", 1,  $logData);
            if (empty($this->rabbitmq)) {
                $this->rabbitmq = new RabbitMQLib;
            }
            $this->rabbitmq->putInFailedQueue('failed_event_processing', $msg->getBody());
        }
        $msg->ack();
    }
}
