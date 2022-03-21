<?php

namespace App\Console\Commands;

use App\Jobs\RabbitMQJob;
use App\Libs\RabbitMQLib;
use Illuminate\Console\Command;

class RunEmailCampaignConsumer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:consume';

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
    public function __construct(RabbitMQLib $rabbitmq)
    {
        parent::__construct();
        $this->rabbitmq=$rabbitmq;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->rabbitmq->dequeue('run_email_campaigns',array($this,'decodedData'));
    }
    public function decodedData($msg){

        print_r(json_decode($msg));
        die;        $message=json_decode($msg->getBody(),true);
        var_dump($message['data']['command']['campaign_id']);
        var_dump($message);
        die;
        $obj=collect($message['data']['command']);
        dd($obj);

    }
}
