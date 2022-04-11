<?php

namespace App\Console\Commands;

use App\Libs\RabbitMQLib;
use App\Services\ChannelService;
use Illuminate\Console\Command;

class ReportAnalize extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:analize';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
        $this->rabbitmq->dequeue('getReports', array($this, 'decodedData'));
    }

    public function decodedData($msg)
    {
        try {
            $message = json_decode($msg->getBody(), true);
            $obj = $message['data']['command'];
            $action_log_id = unserialize($obj)->data['action_log_id'];
            $obj = new ChannelService();

            $obj->getReports($action_log_id);
        } catch (\Exception $e) {

            if (empty($this->rabbitmq)) {
                $this->rabbitmq = new RabbitMQLib;
            }
            $this->rabbitmq->putInFailedQueue('failed_getReports', $msg->getBody());
        }
        $msg->ack();
    }
}
