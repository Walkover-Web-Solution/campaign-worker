<?php

namespace App\Services;

/**
 * Class EmailService
 * @package App\Services
 */
class EmailService
{
    public function sendData($requestInput, $campaign)
    {
        $inputData = $requestInput['data'];
        $action = empty($requestInput['action']) ? 'send' : $requestInput['action'];
        $data = [];
        $data['from'] = ['email' => $campaign->configurations->from_email_name . '@' . $campaign->configurations->domain];
        $data['template_id'] = $campaign->template->meta->slug;
        //$data['action']='sendemail';
        $data['recipients'] = collect($inputData)->map(function ($record) {
            $data = [];
            if (isset($record['to'])) {
                $data['to'] = collect(explode(',', $record['to']))->map(function ($email) {
                    return array(
                        'email' => $email
                    );
                })->toArray();
            }
            if (isset($record['variables'])) {
                $data['variables'] = $record['variables'];
            }
            if (isset($record['cc'])) {
                if (is_array($record['cc'])) {
                    if (isset($record['cc']['email'])) {
                        $data['cc'] = array(
                            'email' => $record['cc']['email'],
                            'name' => isset($record['cc']['name']) ? $record['cc']['name'] : ''
                        );
                    }
                } else {
                    $data['cc'] = collect(explode(',', $record['cc']))->map(function ($email) {
                        return array(
                            'email' => $email
                        );
                    })->toArray();
                }
            }
            if (isset($record['bcc'])) {
                if (is_array($record['bcc'])) {
                    if (isset($record['bcc']['email'])) {
                        $data['bcc'] = array(
                            'email' => $record['bcc']['email'],
                            'name' => isset($record['bcc']['name']) ? $record['bcc']['name'] : ''
                        );
                    }
                } else {
                    $data['bcc'] = collect(explode(',', $record['bcc']))->map(function ($email) {
                        return array(
                            'email' => $email
                        );
                    })->toArray();
                }
            }
            return $data;
        })->toArray();

        $tokenType = 1;
        $authkeyType = 2;
        $runLog = [
            'campaign_type_id' => $campaign->campaign_type_id,
            'input' => $data,
            'campaign_run_authentication_type_id' => request()->hasHeader('token') ? $tokenType : $authkeyType,
            'request_ip' => !empty(request()->ip) ? request()->ip : request()->ip()
        ];

        $token = $campaign->company->authkey;
        $data['token'] = $token;
        $res = $this->emailer->send($data);
    }
    public function getUserEmailTemplates($templateId = '')
    {
        //\Log::channel('single')->info('Request to call email type templates');
        $emailTemplates = $emailTemplates = $this->emailer->getEmailTemplates($templateId);
        //\Log::channel('single')->info('Received email type templates');
        if (!empty($templateId)) {
            $emailTemplate = $emailTemplates->data;
            return array(
                'id' => $emailTemplate->id,
                'name' => $emailTemplate->name,
                'content' => $emailTemplate->body,
                'variables' => $emailTemplate->variables,
                'meta' => $emailTemplate
            );
        }

        return collect($emailTemplates->data->data)->map(function ($emailTemplate) {
            return array(
                'id' => $emailTemplate->id,
                'name' => $emailTemplate->name,
                //'content'=>$emailTemplate->body,
                //'variables'=>$emailTemplate->variables,
                //'meta'=>$emailTemplate
            );
        })->filter()->values();
    }
}
