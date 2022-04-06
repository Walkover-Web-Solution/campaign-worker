<?php

namespace App\Services;

use App\Libs\JobLib;
use App\Libs\RabbitMQLib;
use App\Models\FlowAction;

/**
 * Class JobService
 * @package App\Services
 */
class JobService
{
    protected $lib;
    public function __construct(RabbitMQLib $lib)
    {
        $this->lib = $lib;
    }

    public function processRunCampaign($actionLog)
    {
        // setting object to be send with job
        $input = new \stdClass();
        $input->action_log_id = $actionLog->id;

        // get linked id from flow action to name the queue
        $flow_action = FlowAction::where('id', $actionLog->flow_action_id)->first();
        switch ($flow_action->channel_id) {
            case 1:
                $queue = 'run_email_campaigns';
                break;
            case 2:
                $queue = 'run_sms_campaigns';
                break;
            case 3:
                $queue = 'run_otp_campaigns';
                break;
            case 4:
                $queue = 'run_whastapp_campaigns';
                break;
            case 5:
                $queue = 'run_voice_campaigns';
                break;
        }
        $this->lib->enqueue($queue, $input);
    }
}
