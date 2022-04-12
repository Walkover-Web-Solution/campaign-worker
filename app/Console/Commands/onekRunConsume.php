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

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $rabbitmq;
    public function __construct()
    {
        $this->rabbitmq = new RabbitMQLib;
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->rabbitmq->dequeue('1k_data_queue', array($this, 'decodedData'));
    }
    public function decodedData($msg)
    { try{
            $message = json_decode($msg->getBody(), true);
            $obj = $message['data']['command'];
            $campLogId = unserialize($obj)->data->campaignLogId;
            $obj = new RecordService();
            $obj->pickFlowAction($campLogId);
        }
        catch(\Exception $e){
            $logData=[
                "actionLog"=>$campLogId,
                "exception"=>$e->getMessage()
            ];
            logTest("faild_1k_queue",$logData);
            $this->rabbitmq->putInFailedQueue('failed_run_email_campaigns', $msg->getBody());
        }
    }
}
