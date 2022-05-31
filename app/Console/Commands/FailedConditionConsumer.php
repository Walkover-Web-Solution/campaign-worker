<?php

namespace App\Console\Commands;

use App\Libs\RabbitMQLib;
use App\Services\ConsumerService;
use Illuminate\Console\Command;

class FailedConditionConsumer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'enqueue:failedCondition';

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
        $this->rabbitmq = RabbitMQLib::getInstance();

        $service = new ConsumerService();
        $service->queue = 'condition_queue';
        $this->rabbitmq->dequeue('failed_' . $service->queue, array($service, 'decodedData'));
    }
}
