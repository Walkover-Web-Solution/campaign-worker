<?php

namespace App\Console\Commands;

use App\Libs\RabbitMQLib;
use App\Services\ConsumerService;
use Illuminate\Console\Command;

class FailedEmailConsumer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'enqueue:failedEmail';

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
        $service->queue = 'run_email_campaigns';
        $this->rabbitmq->dequeue('failed_' . $service->queue, array($service, 'decodedData'));
    }
}
