<?php
/* TaskRunner class (c) 2019 justinas@eofnet.lt
* Rewritten in 2020 using redis as backend
*/
namespace Acelle\Library;

use Acelle\Library\Log as MailLog;
use SendGrid\Mail;
use Redis;
// some requirements by rabbitmq
//use PhpAmqpLib\Connection\AMQPStreamConnection;
//use PhpAmqpLib\Message\AMQPMessage;

class TaskRunner
{
    const MESSAGE_TYPE_LIST_UPDATE = 1;
    const MESSAGE_TYPE_CAMPAIGN_UPDATE = 2;
    const MESSAGE_TYPE_CUSTOMER_UPDATE = 3;
    const MESSAGE_TYPE_REDIS_CLEANER = 500;
    const MESSAGE_TYPE_WARMUP_ADD = 501;
    const MESSAGE_TYPE_SMTP_SEND = 502;
    const PRIORITY_HIGH = 1;
    const PRIORITY_MEDIUM = 2;
    const PRIORITY_LOW = 3;


    protected $mq_host = 'localhost';
    protected $mq_port = 5672;
    protected $mq_user = 'guest';
    protected $mq_pass = 'guest';



    public function __construct()
    {

    }

    public function send_queue($type,$customer_id,$priority,$value)
    {
        $CHAN = 'taskrunner';
        $message_obj = new \stdClass;
        $message_obj->type = $type;
        $message_obj->customer = $customer_id;
        $message_obj->priority = $priority;
        $message_obj->val = $value;
        \Redis::publish($CHAN,json_encode($message_obj));

    }

    public function respond($type,$customer_id,$data) {
    $QUERY_CHAN = 'taskrunner_query';
    $ANSWER_CHAN = 'taskrunner_answer';
    $message_obj = new \stdClass;
    $message_obj->uid = \uniqid();
    $message_obj->type = $type;
    $message_obj->customer = $customer_id;
    $message_obj->data = $data;
    // return obj
    $resp = new \stdClass;
    $resp->uid = "";
    $resp->status = 0;
    $resp->data = "";
    $msg = "";
    //MailLog::info("Publishing: ".print_r($message_obj,true));
    \Redis::publish($QUERY_CHAN,json_encode($message_obj));
    $counter = 0;
    $tries = 1000;
    while(true) {
	    if (\Redis::Hexists($ANSWER_CHAN,$message_obj->uid)) {
		    $msg = \Redis::Hget($ANSWER_CHAN,$message_obj->uid);
	             //MailLog::info("got answeris: ".$msg);
	            \Redis::Hdel($ANSWER_CHAN,$message_obj->uid);
		    break;
	    }
	    $counter++;
	    if ($counter >= $tries) break;
	    usleep(100);
	    }
    if ($msg != "") {
// return because its json string
    return $msg;
    } else {
// encode it because it's object
      return json_encode($resp);
    }
   }
}
