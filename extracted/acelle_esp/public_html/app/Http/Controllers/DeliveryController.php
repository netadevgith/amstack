<?php

namespace Acelle\Http\Controllers;

use Acelle\Library\StorageHelper;
use Illuminate\Http\Request;
use Acelle\Library\Log as MailLog;
use Acelle\Library\StringHelper;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use GuzzleHttp\Client;
use Acelle\Model\TrackingLog;
use Acelle\Model\BounceLog;
use Acelle\Model\SendingServer;
use Acelle\Model\SendingServerMailgun;
use Acelle\Model\SendingServerSendGrid;
use Acelle\Model\SendingServerElasticEmail;
use Acelle\Model\SendingServerSparkPost;
use Acelle\Model\FeedbackLog;
use Redis;

class DeliveryController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        parent::__construct();

        $this->middleware('auth', [
            'except' => [
                'notify',
            ],
        ]);
    }


    /**
     * Campaign notification.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function notify(Request $request)
    {
        // Make sure the request is POST
        // ElasticEmail send notification via GET
        // if (!$request->isMethod('post')) {
        //     return;
        // }

     //   MailLog::info("Notify accoured!");

        $type = $request->stype;

        echo $type;

        if ($type == 'amazon') { // @TODO hard-coded here, seeking for a solution
            $this->handleAws();
        } elseif ($type == SendingServerMailgun::WEBHOOK) {
            $this->handleMailgun();
        } elseif ($type == SendingServerSendGrid::WEBHOOK) {
            $this->handleSendGrid();
        } elseif ($type == SendingServerElasticEmail::WEBHOOK) {
            // hack MailLog::configure(storage_path().'/logs/handler-elasticemail.log');
            SendingServerElasticEmail::handleNotification($_GET);
        } elseif ($type == SendingServerSparkPost::WEBHOOK) {
            // hack MailLog::configure(storage_path().'/logs/handler-sparkpost.log');
            return SendingServerSparkPost::handleNotification($_GET);
        } else {
            return;
        }
    }

    /**
     * Handle SendGrid Event Notification
     *
     * @param SendGrid POST
     */
    private function handleSendGrid()
    {
        // hack MailLog::configure(storage_path().'/logs/handler-sendgrid.log');
        $messages = json_decode(file_get_contents('php://input'), true);
       // MailLog::info(file_get_contents('php://input'));
        $date = date("Y.m.d_H-i-s");
        foreach($messages as $message) {
            switch ($message['event']) {
                case 'dropped':
                    $current_deployment = \Config::get('app.deployment');
                    $has_headers = 0;
                    $campuid = "";
                    $fix_header = \Config::get('app.default_mail_header');
                    $deployment = "";
                    $testing = false;
                    $email = $message['email'];
                    if (isset($message[$fix_header]) != false) {
                        $has_headers = 1;
                        preg_match("/(?<campaign>\w+) \[(?<deployment>\w+)/", $message[$fix_header], $matches);
                        if (isset($matches[1])) $campuid = $matches[1];
                        if (isset($matches[2])) $deployment = $matches[2];
                    }
                    if (isset($message['production'])&&$message['production'] == "test") $testing = true;
                    if ($testing) MailLog::info("We here just doing some tests");
                    $status = $message['reason'];
                    $rawStatus = $message['status'];
                    MailLog::info("Got Transient softbounce from SendGrid: $email campaign: $campuid deployment: $deployment details: $rawStatus");
                    if ($testing == false && $has_headers > 0 && Redis::exists($campuid) && !Redis::hexists($campuid."_undelivered_data", $email)) {
                        Redis::incr($campuid . "_bounced");
                        Redis::hset($campuid . "_undelivered_data", $email, json_encode(['status' => $status."_".$rawStatus, 'type' => "bounced"]));
                        Redis::rpush($campuid . '_undelivered_val', $email);
                    }
                    break;
                case 'bounce':
                $current_deployment = \Config::get('app.deployment');
                $has_headers = 0;
                $campuid = "";
                    $fix_header = \Config::get('app.default_mail_header');

                $deployment = "";
                $testing = false;
                $email = $message['email'];
                if (isset($message[$fix_header]) != false) {
                    $has_headers = 1;
                    preg_match("/(?<campaign>\w+) \[(?<deployment>\w+)/", $message[$fix_header], $matches);
                    if (isset($matches[1])) $campuid = $matches[1];
                    if (isset($matches[2])) $deployment = $matches[2];
                }
                if (isset($message['production'])&&$message['production'] == "test") $testing = true;
                if ($testing) MailLog::info("We here just doing some tests");
                $status = $message['reason'];
                $rawStatus = $message['status'];
                MailLog::info("Got hardbounce email from SendGrid: " . $email. " campaign: $campuid deployment: $deployment");
                    if (\Config::get('app.storage') == true) {
                        $stor = new StorageHelper();
                        $stor->SubmitEmail(strtolower($email), 1, "SendGrid hardbounce");
                    }
                \Redis::hset('blacklists', $email, 'Catched in SendGrid');

                if ($testing == 0 && $has_headers > 0 && Redis::exists($campuid) && !Redis::hexists($campuid."_undelivered_data", $email)) {
                    Redis::incr($campuid . "_bounced");
                    Redis::hset($campuid . "_undelivered_data", $email, json_encode(['status' => $status."_".$rawStatus, 'type' => "bounced"]));
                    Redis::rpush($campuid . '_undelivered_val', $email);
                }
                    break;
                case 'delivered':
                    $current_deployment = \Config::get('app.deployment');
                    $has_headers = 0;
                    $campuid = "";
                    $fix_header = \Config::get('app.default_mail_header');
                    $deployment = "";
                    $testing = false;
                    $email = $message['email'];
                    if (isset($message[$fix_header]) != false) {
                        $has_headers = 1;
                        preg_match("/(?<campaign>\w+) \[(?<deployment>\w+)/", $message[$fix_header], $matches);
                        if (isset($matches[1])) $campuid = $matches[1];
                        if (isset($matches[2])) $deployment = $matches[2];
                    }
                    if (isset($message['production'])&&$message['production'] == "test") $testing = true;
                    if ($testing) MailLog::info("We here just doing some tests");
                   // MailLog::info("We got campaign: $campuid and deployment $deployment testing: $testing");
                    MailLog::info("The mail was delivered successfully trough sendgrid to ".$message['email']." campaign: $campuid deployment: $deployment");
                    if ($testing == false && $has_headers > 0 && Redis::exists($campuid)) {
                        // set the deliveried data to campaign redis hset
                        if (Redis::hexists($campuid . "_undelivered_data", $email)) {
                            Redis::decr($campuid."_bounced");
                            Redis::hdel($campuid . "_undelivered_data", $email);
                        }
                        // set deliveries to uid_sent_data hset list
                        Redis::hset($campuid."_sent_data", $email,"ok");
                        Redis::incr($campuid . "_sent");
                    }
                    break;
                case 'spamreport':
                    $email = $message['email'];
                    try {
                        $reason = "Complain from SendGrid";
                        if (\Config::get('app.storage') == true) {
                            $stor = new StorageHelper();
                            $stor->SubmitEmail(strtolower($email),4, "SendGrid Complain");
                        }
                        \DB::unprepared("INSERT IGNORE INTO blacklists (email,created_at,updated_at,reason,customer_id) VALUES ('$email','$date','$date','$reason',1)");
                        \Redis::hset('blacklists_fast',$email,'Abuse reported in SendGrid');
                    } catch (\Exception $ex) {
                        MailLog::error("Unable to insert complaint email gathered from the SendGrid api: ".$email);
                    }
                    MailLog::info("Got complaint email from sendgrid: ".$email);
                    break;
                default:
                    // nothing
            }
        }

        header('X-PHP-Response-Code: 200', true, 200);
    }

    private function handleMailgun()
    {
        // hack // hack MailLog::configure(storage_path().'/logs/handler-mailgun.log');

        // @TODO: POST request not verified because we cannot retrive sending server information
        // The complete check should be
        //    if (isset($_POST['timestamp']) && isset($_POST['token']) && isset($_POST['signature']) && hash_hmac('sha256', $_POST['timestamp'].$_POST['token'], $sendingServer->api_key) === $_POST['signature']) {
        if (isset($_POST['timestamp']) && isset($_POST['token']) && isset($_POST['signature'])) {
            if ($_POST['event'] == 'complained') {
                $feedbackLog = new FeedbackLog();
                $feedbackLog->runtime_message_id = StringHelper::cleanupMessageId($_POST['Message-Id']);
                // For Mailgun, runtime_message_id EQUIV. message_id
                $feedbackLog->message_id = $feedbackLog->runtime_message_id;
                $feedbackLog->feedback_type = 'spam';
                $feedbackLog->raw_feedback_content = '';
                $feedbackLog->save();
                // hack // hack MailLog::info('Feedback recorded for message '.$feedbackLog->runtime_message_id);
            } elseif ($_POST['event'] == 'bounced') {
                $bounceLog = new BounceLog();
                $bounceLog->runtime_message_id = StringHelper::cleanupMessageId($_POST['Message-Id']);
                // For Mailgun, runtime_message_id EQUIV. message_id
                $bounceLog->message_id = $bounceLog->runtime_message_id;
                $bounceLog->bounce_type = BounceLog::HARD;
                $bounceLog->raw = $_POST['error'];
                $bounceLog->save();
                // hack // hack MailLog::info('Bounce recorded for message '.$bounceLog->runtime_message_id);
                // hack // hack MailLog::info('Adding email to blacklist');
                $bounceLog->findSubscriberByRuntimeMessageId()->sendToBlacklist($bounceLog->raw);
            }
        }
        header('X-PHP-Response-Code: 200', true, 200);
    }

    private function FindCampuidInArray($var) {
        $fix_header = \Config::get('app.default_mail_header');
        try {
            foreach ($var['mail']['headers'] as $header) {
                if ($header['name'] == $fix_header)
                    return $header['value'];
            }
            return false;
        } catch (\Exception $ex) {
            return false;
        }
    }

    private function CheckIfEmailIstest($var) {
        try {
            foreach ($var['mail']['headers'] as $header) {
                if ($header['name'] == "production")
                    return $header['value'];
            }
            return false;
        } catch (\Exception $ex) {
            return false;
        }
    }

    private function handleAws()
    {
        $logger = MailLog::create(storage_path('logs/aws.log'));
        //hack // hack MailLog::configure(storage_path().'/logs/handler-aws.log');
     //   MailLog::info("handing the submission via amazons aws notification endpoint");
        $date = date("Y.m.d_H-i-s");
        try {
            // hack // hack MailLog::info('validating message...');
            // Create a message from the post data and validate its signature
            $message = Message::fromRawPostData();
            $validator = new MessageValidator();
            $validator->validate($message);
            // hack MailLog::info('message validated!');
        } catch (\Exception $e) {
            // Pretend we're not here if the message is invalid
            // hack MailLog::warning('not an Amazon push');
            return;
        }

        if ($message['Type'] === 'SubscriptionConfirmation') {
            // hack MailLog::info('subscription received');
            // Send a request to the SubscribeURL to complete subscription
            (new Client())->get($message['SubscribeURL']);

           // MailLog::info('subscription confirmed');
            $logger->info('subscription confirmed');
            return;
        }

        if ($message['Type'] != 'Notification') {
            // hack MailLog::info('not notification');

            return;
        }

        $responseMessage = json_decode($message['Message'], true);

        if ($responseMessage['notificationType'] == 'AmazonSnsSubscriptionSucceeded') {
            $logger->info('subscription confirmed by AWS');

            return;
        }

        /*
        sleep(5);
        $trackingLog = TrackingLog::where("message_id", $responseMessage['mail']['messageId'])->first() ;
        if (empty($trackingLog)) {
            // hack MailLog::error('message_id not found');
            return;
        }
        */

    //    MailLog::info("Got notification from amazon SES: ".print_r($responseMessage,true));

        $current_deployment = \Config::get('app.deployment');
        $header = $this->FindCampuidInArray($responseMessage);
        $has_headers = 0;
        $campuid = "";
        $deployment = "";
        if ($header != false) {
            $has_headers = 1;
            preg_match("/(?<campaign>\w+) \[(?<deployment>\w+)/", $header, $matches);
            if (isset($matches[1])) $campuid = $matches[1];
            if (isset($matches[2])) $deployment = $matches[2];
            //MailLog::info("HEADER DEBUG: ".$header);
        }
        $testing = 0;
        $test_email = $this->CheckIfEmailIstest($responseMessage);
        if ($test_email != false)
            $testing = 1;

        // my new implementation that simply works!
        if ($responseMessage['notificationType'] == 'Bounce') {
            // if hardbounce is detected add to blacklist and notify the campaign
            if ($responseMessage['bounce']['bounceType'] === 'Permanent') {
                $recipients = $responseMessage['bounce']['bouncedRecipients'];
                // FIXME here we should get the campaign uid and report it to the campaign undelivered redis backend
                foreach ($recipients as $recipient) {
                    $email = $recipient['emailAddress'];
                    $status = $recipient['status'];
                    $rawStatus = $recipient['diagnosticCode'];
                    $logger->info("Got hardbounce email from amazon ses: " . $email. " campaign: $campuid deployment: $deployment");
                    if (\Config::get('app.storage') == true) {
                        $stor = new StorageHelper();
                        $stor->SubmitEmail(strtolower($email),1, "SES Hardbounce");
                    }
                    \Redis::hset('blacklists', $email, 'Catched in amazon SES');
                    if ($testing == 0 && $has_headers > 0 && Redis::exists($campuid) && !Redis::hexists($campuid."_undelivered_data", $email)) {
                        Redis::incr($campuid . "_bounced");
                        Redis::hset($campuid . "_undelivered_data", $email, json_encode(['status' => $status."_".$rawStatus, 'type' => "bounced"]));
                        Redis::rpush($campuid . '_undelivered_val', $email);
                    }
                }
                return;
            }
            // handle the softbounce and report the corresponding campaign
            if ($responseMessage['bounce']['bounceType'] === "Transient") {
               // MailLog::info("Got notification from amazon SES: ".print_r($responseMessage,true));
                $recipients = $responseMessage['bounce']['bouncedRecipients'];
                // FIXME here we should get the campaign uid and report it to the campaign undelivered redis backend
                foreach  ($recipients as $recipient) {
                    $email = $recipient['emailAddress'];
                    $status = "";
                    if (isset($recipient['status'])) $status = $recipient['status'];
                    $rawStatus =  "";
                    if (isset($recipient['diagnosticCode'])) $rawStatus = $recipient['diagnosticCode'];
                    $logger->info("Got Transient softbounce from aws: $email campaign: $campuid deployment: $deployment details: $rawStatus");

                    if ($testing == 0 && $has_headers > 0 && Redis::exists($campuid) && !Redis::hexists($campuid."_undelivered_data", $email)) {
                        Redis::incr($campuid . "_bounced");
                        Redis::hset($campuid . "_undelivered_data", $email, json_encode(['status' => $status."_".$rawStatus, 'type' => "bounced"]));
                        Redis::rpush($campuid . '_undelivered_val', $email);
                    }


                    }
                return;
            }
        }
        if ($responseMessage['notificationType'] == 'Delivery') {
            //MailLog::info("debug: ".print_r($responseMessage,true));
            $recipients = $responseMessage['mail']['destination'];
            $custom = "";
            if ($testing > 0) $custom = "(test email)";
            foreach ($recipients as $recipient) {

                $logger->info("Got successfull aws delivery to: $recipient campaign: $campuid deployment: $deployment $custom");
                if ($testing == 0 && $has_headers > 0 && Redis::exists($campuid)) {
                    // set the deliveried data to campaign redis hset
                    if (Redis::hexists($campuid . "_undelivered_data", $recipient)) {
                        Redis::decr($campuid."_bounced");
                        Redis::hdel($campuid . "_undelivered_data", $recipient);
                    }
                    if (\Config::get('app.storage') == true) {
                        try {
                            $stor = new StorageHelper();
                            $stor->SubmitDelivery(strtolower($recipient), $campuid, $responseMessage['delivery']['remoteMtaIp'], '', $deployment, $responseMessage['delivery']['smtpResponse']);
                        } catch (\Exception $ex) {
                            $logger->error("Got problem by transferring delivery stage to storage via amazon ses delivery handler!");
                        }
                    }
                    // set deliveries to uid_sent_data hset list
                    Redis::hset($campuid."_sent_data", $recipient,"ok");
                    Redis::incr($campuid . "_sent");
                }
            }
            return;
        }

        if ($responseMessage['notificationType'] == 'Complaint') {

            $recipients = $responseMessage['complaint']['complainedRecipients'];
          //  $useragent = $responseMessage['complaint']['userAgent'];
         //   $complaintFeedbackType = $responseMessage['complaint']['complaintFeedbackType'];
          //  $arrivalDate = $responseMessage['complaint']['arrivalDate'];
          //  $timestamp = $responseMessage['complaint']['timestamp'];
            //$feedbackId = $responseMessage['complaint']['feedbackId'];

            foreach ($recipients as $recipient) {
                $email = $recipient['emailAddress'];
                try {
                    $reason = "Complain from SES";
                    if (\Config::get('app.storage') == true) {
                        $stor = new StorageHelper();
                        $stor->SubmitEmail(strtolower($email),4, "SES Complain");
                    }
                    \DB::unprepared("INSERT IGNORE INTO blacklists (email,created_at,updated_at,reason,customer_id) VALUES ('$email','$date','$date','$reason',1)");
                    \Redis::hset('blacklists_fast',$email,'Abuse reported in amazon SES');
                } catch (\Exception $ex) {
                    MailLog::error("Unable to insert complaint email gathered from the amazon sns api: ".$email);
                }
                $logger->info("Got complaint email from amazon ses: ".$email);

            }
            return;

        }


//        if ($responseMessage['notificationType'] == 'Bounce') {
//            $bounce = $responseMessage['bounce'];
//
//            $bounceLog = new BounceLog();
//            $bounceLog->runtime_message_id = $responseMessage['mail']['messageId'];
//            $trackingLog = TrackingLog::where('runtime_message_id', $bounceLog->runtime_message_id)->first();
//            $bounceLog->message_id = $trackingLog->message_id;
//            $bounceLog->bounce_type = $bounce['bounceType']; // !== 'Permanent' ? BounceLog::SOFT : BounceLog::HARD;
//            $bounceLog->raw = $message['Message'];
//            $bounceLog->save();
//            // hack MailLog::info('Bounce recorded for message '.$bounceLog->runtime_message_id);
//
//            if ($bounce['bounceType'] === 'Permanent') {
//                // hack MailLog::info('Adding email to blacklist');
//                $bounceLog->findSubscriberByRuntimeMessageId()->sendToBlacklist($bounceLog->raw);
//            }
//        }
//
//       if ($responseMessage['notificationType'] == 'Complaint') {
//            $feedback = $responseMessage['complaint'];
//
//            $feedbackLog = new FeedbackLog();
//            $feedbackLog->runtime_message_id = $responseMessage['mail']['messageId'];
//            $feedbackLog->feedback_type = $feedback['complaintFeedbackType'];
//            $feedbackLog->raw_feedback_content = $message['Message'];
//            $feedbackLog->save();
//            // hack MailLog::info('Feedback recorded for message '.$feedbackLog->runtime_message_id);
//            try {
//                // hack MailLog::info('Adding email to abuse list');
//                $feedbackLog->findSubscriberByRuntimeMessageId()->markAsSpamReported();
//            } catch (\Exception $e) {
//                // hack MailLog::warning('Cannot mark subscriber as Abuse-Reported. ' . $e->getMessage());
//            }
//        }

    }
}
