#!/bin/bash

# If you want to have more than one application, and in just one of them to run the supervisor, uncomment the lines below,
# and add the env variable IS_WORKER as true in the EBS application you want the supervisor

#if [ "${IS_WORKER}" != "true" ]; then
#    echo "Not a worker. Set variable IS_WORKER=true to run supervisor on this instance"
#    exit 0
#fi

echo "Supervisor - starting setup"



# echo "installing supervisor"
# sudo easy_install supervisor

if [ ! -f /usr/bin/supervisord ]; then
    echo "installing supervisor"
    sudo easy_install supervisor
    # sudo amazon-linux-extras enable epel
    # sudo yum install -y epel-release
    # sudo yum -y update
    # sudo yum -y install supervisor
else
    echo "supervisor already installed."
    # sudo service supervisord restart
fi


if [ ! -d /var/log/supervisor ]; then
    mkdir /var/log/supervisor
    echo "create supervisor log  directory"
fi




if [ ! -d /etc/supervisor ]; then
    mkdir /etc/supervisor
    echo "create supervisor directory"
fi


if [ ! -d /etc/supervisor/conf.d ]; then
    mkdir /etc/supervisor/conf.d
    echo "create supervisor configs directory"
fi

cat .ebextensions/supervisor/supervisord.conf > /etc/supervisor/supervisord.conf
cat .ebextensions/supervisor/supervisord.conf > /etc/supervisord.conf
cat .ebextensions/supervisor/email_consumption.conf > /etc/supervisor/conf.d/email_consumption.conf
cat .ebextensions/supervisor/sms_consumption.conf > /etc/supervisor/conf.d/sms_consumption.conf
cat .ebextensions/supervisor/onek_data_process.conf > /etc/supervisor/conf.d/onek_data_process.conf
cat .ebextensions/supervisor/condition_consumption.conf > /etc/supervisor/conf.d/condition_consumption.conf
cat .ebextensions/supervisor/whatsapp_consumption.conf > /etc/supervisor/conf.d/whatsapp_consumption.conf
cat .ebextensions/supervisor/events_processing.conf > /etc/supervisor/conf.d/events_processing.conf
cat .ebextensions/supervisor/rcs_consumption.conf > /etc/supervisor/conf.d/rcs_consumption.conf
cat .ebextensions/supervisor/failed_email_consumption.conf > /etc/supervisor/conf.d/failed_email_consumption.conf
cat .ebextensions/supervisor/failed_sms_consumption.conf > /etc/supervisor/conf.d/failed_sms_consumption.conf
cat .ebextensions/supervisor/failed_onek_data_process.conf > /etc/supervisor/conf.d/failed_onek_data_process.conf
cat .ebextensions/supervisor/failed_condition_consumption.conf > /etc/supervisor/conf.d/failed_condition_consumption.conf
cat .ebextensions/supervisor/failed_rcs_consumption.conf > /etc/supervisor/conf.d/failed_rcs_consumption.conf
cat .ebextensions/supervisor/failed_whatsapp_consumption.conf > /etc/supervisor/conf.d/failed_whatsapp_consumption.conf
cat .ebextensions/supervisor/failed_events_processing.conf > /etc/supervisor/conf.d/failed_events_processing.conf

# Create queue if not exist
sudo /usr/bin/php /var/app/current/artisan rabbitmq:queue-declare "1k_data_queue"
sudo /usr/bin/php /var/app/current/artisan rabbitmq:queue-declare "run_sms_campaigns"
sudo /usr/bin/php /var/app/current/artisan rabbitmq:queue-declare "run_email_campaigns"
sudo /usr/bin/php /var/app/current/artisan rabbitmq:queue-declare "run_rcs_campaigns"
sudo /usr/bin/php /var/app/current/artisan rabbitmq:queue-declare "condition_queue"
sudo /usr/bin/php /var/app/current/artisan rabbitmq:queue-declare "run_whastapp_campaigns"
sudo /usr/bin/php /var/app/current/artisan rabbitmq:queue-declare "event_processing"
sudo /usr/bin/php /var/app/current/artisan rabbitmq:queue-declare "failed_1k_data_queue"
sudo /usr/bin/php /var/app/current/artisan rabbitmq:queue-declare "failed_condition_queue"
sudo /usr/bin/php /var/app/current/artisan rabbitmq:queue-declare "failed_run_email_campaigns"
sudo /usr/bin/php /var/app/current/artisan rabbitmq:queue-declare "failed_run_sms_campaigns"
sudo /usr/bin/php /var/app/current/artisan rabbitmq:queue-declare "failed_run_rcs_campaigns"
sudo /usr/bin/php /var/app/current/artisan rabbitmq:queue-declare "failed_run_whastapp_campaigns"
sudo /usr/bin/php /var/app/current/artisan rabbitmq:queue-declare "failed_event_processing"

if ps aux | grep "[/]usr/bin/supervisord"; then
    echo "supervisor is running"
    /usr/bin/supervisorctl stop all
else
    echo "starting supervisor"
    /usr/bin/supervisord
fi

/usr/bin/supervisorctl reread
/usr/bin/supervisorctl update
/usr/bin/supervisorctl restart all

echo "Supervisor Running!"
