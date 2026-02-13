<?php

/**
 * Campaign class.
 *
 * Model class for campaigns related functionalities.
 * This is the center of the application
 *
 * LICENSE: This product includes software developed at
 * the Acelle Co., Ltd. (http://acellemail.com/).
 *
 * @category   MVC Model
 *
 * @author     N. Pham <n.pham@acellemail.com>
 * @author     L. Pham <l.pham@acellemail.com>
 * @copyright  Acelle Co., Ltd
 * @license    Acelle Co., Ltd
 *
 * @version    1.0
 *
 * @link       http://acellemail.com
 */

namespace Acelle\Model;

use Acelle\Library\RusysHelper;
use GuzzleHttp\Promise\CancellationException;
use Illuminate\Database\Eloquent\Model;
use Acelle\Library\Log as MailLog;
use Acelle\Library\StringHelper;
use SendGrid\Mail;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;
use DB;
use Carbon\Carbon;
use Acelle\Model\SystemJob;
use Acelle\Exceptions\CampaignPausedException;
use Acelle\Exceptions\CampaignErrorException;
use Acelle\Exceptions\AbortCampaignException;
use Illuminate\Support\Facades\Storage;
use Redis;

class Campaign extends AbsCampaign
{
    /**
     * Start the campaign
     *
     */
    public function start($trigger = null) {
        try {
            // New implementation 2018.09.16
            // updated in 2020.10.06 to support multiple fields in maillists

            // patikrinam ar statusas paruostas siuntimui
            if (!$this->refreshStatus()->isReady()) {
                MailLog::info("Campaign ID: {$this->id} is not ready or already started");
                return;
            }

            if (strtotime($this->run_at) < date("Y-m-d H:i:s")) {
                MailLog::info("Campaign ID: {$this->id} has launch time: " . $this->run_at . " and is not yet ready (scheduled, because now is " . date("Y-m-d H:i:s") . "), doing new job queue...");
                // clear the old jobs ;-)
                $this->clearAllJobs();
                $job = (new \Acelle\Jobs\RunCampaignJob($this))->delay($this->getDelayInSeconds());
                dispatch($job);
                return;
            }
            if (Redis::exists($this->uid.'_canceled')) {
                throw new AbortCampaignException();
            }

            // markinam statusa kaip pradeta siusti
            $this->preparing();
            MailLog::info("Starting the campaign: " . $this->name. " uid: $this->uid");
            $notopeners = $this->notopeners ?? 0;

            \DB::connection()->disableQueryLog();
            MailLog::info("Detecting custom fields in maillist assigned to this campaign");
            $customFieldsEnabled = false;
            $customFields = $this->GetMailListFields($this->id);
            MailLog::info("Custom fields debug: ".print_r($customFields,true));
            if (count($customFields) > 1) {
                MailLog::info("We detected custom mail fields for this campaign assigned maillist, enabling custom fields in campaign sending...");
                $customFieldsEnabled = true;
            }


            MailLog::info("Selecting the subscribers for campaign $this->uid");
            // old deprecated code, should be deleted ASAP
       //     $builder = $this->subscribers([], [])
//                ->whereRaw(sprintf(table('subscribers') . '.id NOT IN (SELECT subscriber_id FROM %s WHERE campaign_id = %s)', table('tracking_logs'), $this->id))
//                ->whereRaw(DB::getTablePrefix() . "subscribers.email NOT IN (select email from blacklists)")
//                ->whereRaw(DB::getTablePrefix() . "subscribers.status = '" . Subscriber::STATUS_SUBSCRIBED . "'");
            //$subscribers_raw = $builder->get();
            // NEW implementation
            $builder = Subscriber::select("subscribers.*");//->leftJoin('tracking_logs', 'tracking_logs.subscriber_id', '=', 'subscribers.id');
            // support multiple fields
            if ($customFieldsEnabled) {
                $builder = $builder->with(['mailList','subscriberFields']);
                //$builder = $builder->select(DB::raw("SELECT GROUP_CONCAT(subscriber_fields.field_id SEPARATOR ', '"))->join('subscriber_fields', 'subscribers.id', '=', 'subscriber_fields.subscriber_id');
                 //   "subscriber_fields.field_id","subscriber_fields.value")->leftJoin('subscriber_fields', 'subscribers.id', '=', 'subscriber_fields.subscriber_id'));
            }

            $builder = $builder->join('campaigns_lists_segments', 'subscribers.mail_list_id', '=', 'campaigns_lists_segments.mail_list_id');
            $builder = $builder->join('campaigns','campaigns_lists_segments.campaign_id','=','campaigns.id');
            // this shoud be adapted in the near future
        // fix    $builder = $builder->whereRaw(sprintf(table('subscribers') . '.id NOT IN (SELECT subscriber_id FROM %s WHERE campaign_id = %s)', table('tracking_logs'), $this->id))
              $builder = $builder->whereRaw(DB::getTablePrefix() . "subscribers.email NOT IN (select email from blacklists)")
                ->whereRaw(DB::getTablePrefix() . "subscribers.status = '" . Subscriber::STATUS_SUBSCRIBED . "'");
            $builder = $builder->where('campaigns.id','=',$this->id);

            // cia daryti counta tiktai jeigu maillist cache nera
            $temp_tot = $this->get_mailist_cached_totals($this->defaultMailList->id ?? null);
            if ($temp_tot != null) $subscribers_total = $temp_tot;
            else $subscribers_total = $builder->count();
            // check if campaign was queued again (Requeue)
            if (!Redis::exists($this->uid.'_requeue')) {
                Redis::set($this->uid . '_total', $subscribers_total);
            }
            // if the campaign that are not started is already queued, then the total count will not be set, FIX for this below
            if (!Redis::exists($this->uid."_total")) {
                Redis::set($this->uid . '_total', $subscribers_total);
            }
            if (Redis::exists('campaign_'.$this->uid.'_trackingdone')) Redis::del('campaign_'.$this->uid.'_trackingdone');

            MailLog::info('Got subscribers count: ' . $subscribers_total. " for campaign $this->uid");


            if ($subscribers_total == 0) {
                $this->error("You got 0 subscribers for campaign $this->uid, how this happend ?");
                throw new CampaignErrorException();
            }
            

            if (Redis::exists($this->uid.'_canceled')) {
                throw new AbortCampaignException();
            }

            MailLog::info("Detecting sending servers for campaign $this->uid...");
            // get all assigned servers
            if (DB::table('campaigns')->select('all_sending_servers')
                    ->join('campaigns_lists_segments', 'campaigns.id', '=', 'campaigns_lists_segments.campaign_id')
                    ->join('mail_lists', 'campaigns_lists_segments.mail_list_id', '=', 'mail_lists.id')->where('mail_lists.id', $this->defaultMailList->id)->first()->all_sending_servers == 1) {
                $servers = \DB::table('sending_servers')->select('sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port','smtp_protocol','smtp_username','smtp_password','threads')
                    ->where('status', 'active')->where('sending_servers.id', '>', 1)->get();
                MailLog::info("all servers assigend for campaign $this->uid");


            } else {
                $servers = \DB::table('campaigns')->select('sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port', 'fitness','smtp_protocol','smtp_username','smtp_password','threads')
                    ->join('campaigns_lists_segments', 'campaigns.id', '=', 'campaigns_lists_segments.campaign_id')
                    ->join('mail_lists_sending_servers', 'campaigns_lists_segments.mail_list_id', '=', 'mail_lists_sending_servers.mail_list_id')
                    ->join('sending_servers', 'mail_lists_sending_servers.sending_server_id', '=', 'sending_servers.id')
                    ->where('campaigns.uid', $this->uid)->get();
                MailLog::info("servers assigned by hand for campaign $this->uid");
            }

            if (Redis::exists($this->uid.'_canceled')) {
                throw new AbortCampaignException();
            }

            MailLog::info("Servers detected: " . count($servers));

            if (count($servers) == 0) {
                // MailLog::error("Campaign does not have any assigned servers at all!");
                $this->error("Campaign $this->uid does not have any assigned servers at all!");
                throw new CampaignErrorException();
            }


            $tracking_log = array();
            MailLog::info("Preparing the subscribers array");
            // FIXME speed fix to get some variables, instead of doing it in the foreach cycle
            $global_tracking = Setting::get('global_tracking');
            $url_open_track = Setting::get('url_open_track');
            $url_unsubscribe = Setting::get('url_unsubscribe');
            $url_update_profile = Setting::get('url_update_profile');
            $url_click_track = Setting::get('url_click_track');

            if (Redis::exists("campaign_" . $this->uid . "_subscribers")) {
                if (!Redis::exists($this->uid.'_requeue')) Redis::del("campaign_" . $this->uid . "_subscribers");
            }
            if (Redis::exists('campaign_' . $this->uid . "_static")) {
                if (!Redis::exists($this->uid.'_requeue')) Redis::del('campaign_' . $this->uid . "_static");
            }

            if (Redis::exists($this->uid.'_canceled')) {
                throw new AbortCampaignException();
            }


            $actual_subscribers_count = 0;
            $was_openers = 0;
            $date = date("Y-m-d H:i:s");

            MailLog::info("NOT OPENERS VALUE: ".$notopeners);
            \DB::Reconnect();
            $max = 5000; // kiek recordu imti per viena karta
            $total = $builder->count();
            MailLog::info("Total subscribers count fetched: ".$total);

            if ($total == 0) {
                $this->error("Some error may have caused that your campaign have 0 subscribers, check your Mail List first!");
                throw new CampaignErrorException();
            }

            $pages = ceil($total / $max); // kiek puslapiu bus
            MailLog::info("It will be ".$pages." pages");

            for ($i = 1; $i < ($pages + 1); $i++) {
                $offset = (($i - 1)  * $max);
                $start = ($offset == 0 ? 0 : ($offset + 1));
                $subscribers = $builder->skip($start)->take($max)->get();


                if (Redis::exists($this->uid.'_canceled')) {
                    throw new AbortCampaignException();
                }

                if ($notopeners == 1) {
                    MailLog::info("Before withoutopeners function: ".count($subscribers));
                    $subscribers = $this->WithoutOpeners($subscribers);
                    // just some counting
                    $countas = count($subscribers);
                    MailLog::info("After withoutopeners function: ".$countas);
                    if ($countas != $max) {
                        $was_openers = $was_openers + ($max - $countas);
                    }
                }
                $subscriberiu_countas = count($subscribers);
                $tracking_log = [];


                foreach ($subscribers as $subas) {
//                    if (!Redis::hexists('blacklists', $subas->email)&&!Redis::hexists('blacklists_fast',$subas->email)&&$can_continue == 1) {
                        if (Redis::exists($this->uid.'_canceled')) {
                            throw new AbortCampaignException();
                        }
                        $message_id = StringHelper::generateMessageId("");
                        $subas->open_tracking = $message_id ."{SRVBL}";
                        $message_id = str_replace('{SRVBL}','.',$subas->open_tracking);

                        $tracking_log[] = "('$message_id','$message_id',$this->customer_id,1,$this->id,$subas->id,'sent','$date','$date')";
                        $subas->message_id = $message_id;
                        $subas->track_url = $global_tracking;
                        //        sudedam linkus ir t.t.
                        //$subas->tracking_url = str_replace('{CAMPAIGN}', StringHelper::generateCampaignName(), str_replace('MESSAGE_ID', StringHelper::base64UrlEncode($message_id), $url_open_track));
                        $subas->tracking_url = str_replace('{CAMPAIGN}', StringHelper::generateCampaignName(), $url_open_track);
                        $subas->unsubscribe_url = str_replace('{CAMPAIGN}', StringHelper::generateUnsubscribeUrl(), str_replace('MESSAGE_ID', StringHelper::base64UrlEncode2($message_id), $url_unsubscribe));
                        $subas->update_url = str_replace('{RANDOM}', StringHelper::generateCampaignName(), str_replace('LIST_UID', $this->defaultMailList->uid, str_replace('SUBSCRIBER_UID', $subas->uid, str_replace('SECURE_CODE', $this->getSecKey($subas->email, 'update-profile'), $url_update_profile))));
                        $subas->msgid1 = StringHelper::base64UrlEncode($message_id);
                        $subas->msgid2 = StringHelper::base64UrlEncode3($message_id);
                        // custom fields
                        $subas->CustomFieldsEnabled = $customFieldsEnabled;
                        if ($customFieldsEnabled) {
                            // add custom fields to array here
                            //MailLog::info("DEBUG OF SUBSCRIBER FIELDS ================ for subscriber: ".$subas->email);
                            $fields = array();
                            foreach ($subas->subscriberFields as $field) {
                               // MailLog::info("Turim custom field $field");
                                $fieldraw = json_decode($field);
                                $tag = $this->ReturnTag($customFields,$fieldraw->field_id);
                                if ($tag != "EMAIL") {
                                    $fields[] = ['tag' => $tag, 'value' => $fieldraw->value];
                                }
                            }
                            $subas->subscriberFields = $fields;
                           // MailLog::info("Got fields: ".print_r($fields,true));
                           // MailLog::info("END OF DEBUG OF SUBSCRIBER FIELDS ========== for subscriber: ".$subas->email);
                        }

                        //        sudedam i array subscriberi
                        Redis::sAdd("campaign_" . $this->uid . "_subscribers", \json_encode($subas));
                        // some value that need for resending method
                        Redis::hSet('campaign_' . $this->uid . "_static", $subas->email, \json_encode($subas));

                        // RESEND SIMULATION
                   // Redis::hset($this->uid . "_undelivered_data", $subas->email, 0);
                   // Redis::rpush($this->uid . '_undelivered_val', $subas->email);
                        // we will write some test file to check the subscribers, if all are unique and so on
                   //     file_put_contents("/tmp/".$this->uid."_subscribers.txt",$subas->email, FILE_APPEND);
                        $actual_subscribers_count++;
  //                  }
                }


                if (Redis::exists($this->uid.'_canceled')) {
                    throw new AbortCampaignException();
                }
                MailLog::info("Inserting tracking logs $i of $pages for campaign $this->uid currently got: $subscriberiu_countas subscribers");
                if (count($tracking_log) > 0) {
                    try {
                        \DB::unprepared("INSERT IGNORE INTO tracking_logs (runtime_message_id,message_id,customer_id,sending_server_id,campaign_id,subscriber_id,status,created_at,updated_at) VALUES " . implode(',', $tracking_log));
                    } catch (\Exception $ex) {
                        // FIXME neveikia reinsertas
                        MailLog::error("Got error with campaign $this->uid then inserting the batch of tracking logs at position $i of $pages but we will try to insert it second time after 10s for campaign $this->uid error: " . $ex);
                        // throw new AbortCampaignException();
                        sleep(10);
                        try {
                            \DB::Reconnect();
                            \DB::unprepared("INSERT IGNORE INTO tracking_logs (runtime_message_id,message_id,customer_id,sending_server_id,campaign_id,subscriber_id,status,created_at,updated_at) VALUES " . implode(',', $tracking_log));
                        } catch (\Exception $ex) {
                            MailLog::error("After 10s, second time insert didn't helped too :-( for campaign $this->uid error: " . $ex);
                        }
                    }

                } else {
                    MailLog::info("We detected empty tracking logs at this point we won't make any insert and keep going...");
                }
                if (Redis::exists($this->uid.'_canceled')) {
                    throw new AbortCampaignException();
                }
                // always check if the sender processes are running, if not then run them
                if ($this->check_if_sending($this->uid) == false) {
                    if ($i == $pages) $this->initiate_sending($servers);
                    else $this->initiate_sending($servers);
                }

                unset($tracking_log);
                MailLog::info("Got finished another insert circle");

//!!!!!!!!!
            } // WHILE SUBSCRIBERS END HERE
// !!!!!!!!!


            if (Redis::exists($this->uid.'_canceled')) {
                throw new AbortCampaignException();
            }

            // $this->initiate_sending($servers,1);
            MailLog::info('We got just finished to preparing ALL of the tracking logs for the campaign: ' . $this->uid);
            // actual count, depends on blacklists ;-)
            if (!Redis::exists($this->uid.'_requeue')) Redis::set($this->uid . '_total', $actual_subscribers_count);
            MailLog::info('Re-Counted subscribers count: ' . $actual_subscribers_count);
            MailLog::info('There was: '.$was_openers.' openers detected during subscribers counting');


            // check if all senders are down and there any members left to send
            if ($this->check_if_sending($this->uid) == false) {
                $this->initiate_sending($servers, 1);
            } else {
            // killall current sending processes and run the new
            MailLog::info("Detected running sending processes for campaign: ".$this->uid);
                exec("kill `ps x|grep -v grep|grep ".$this->uid."|awk '{ print \$1}'` > /dev/null 2>&1 &");
                sleep(1);
            MailLog::info("Restarting processes for campaign: ".$this->uid." without --nodone flag");
            $this->initiate_sending($servers, 1);
            }
            if (Redis::exists($this->uid.'_canceled')) {
                throw new AbortCampaignException();
            }

            if (Redis::exists($this->uid.'_requeue')) Redis::del($this->uid.'_requeue');
           // while (true) {
                if (Redis::exists($this->uid.'_canceled')) {
                    throw new AbortCampaignException();
                }
                // cia dar galima papildomai tikrinti jeigu nesiuncia, paleisti senderi
//                $cnt = Redis::get($this->uid . "_counter") ?? null;
//                if ($cnt == null) break;
//                if ($cnt == $actual_subscribers_count) {
//                    $this->done();
//                    break;
//                }
//
//                MailLog::info('Waiting for senders to finish job for campaign ' . $this->uid);
//                sleep(30);
            //}
            Redis::set('campaign_'.$this->uid.'_trackingdone',1);

            MailLog::info("We are completely done with the campaign: $this->uid");
        } catch (AbortCampaignException $e) {
            // clean tracking logs, redis storage and set the status = ready
            MailLog::error("Campaign $this->uid with exception AbortCampaign EXITED");
            //\DB::table('tracking_logs')->where('campaign_id','=',$this->id)->delete();
            Redis::del("campaign_" . $this->uid . "_subscribers");
            Redis::del("campaign_" . $this->uid . "_static");
            Redis::set($this->uid . "_paused",1);
            //$job = (new \Acelle\Jobs\RunCampaignJob($this))->delay($this->getDelayInSeconds());
            //dispatch($job);
            return;

        } catch (CampaignErrorException $e) {
            // just finish
            MailLog::warning("Campaign $this->uid terminated: " . $e->getMessage());
        } catch (\Exception $ex) {
            // Set ERROR status
            $this->error($ex->getMessage());
            MailLog::error("Campaign $this->uid send failed. ".$ex->getMessage());
        }
    }

    private function get_mailist_cached_totals($mail_list_id) {
        $totals = null;
        try {
            if ($mail_list_id != null) {
                $json = \DB::table('mail_lists')->where('id', '=', $mail_list_id)->first();
                $data = @json_decode($json->cache);
                if (isset($json)&&isset($data)&& isset($data->SubscriberCount)&& $data->SubscriberCount >0) {
                    MailLog::info('We got a good results from maillist cache for maillist: '.$mail_list_id);
                    return $data->SubscriberCount;
                }
        }
        return $totals;
        } catch (\Exception $ex) {
            MailLog::error("Trouble getting the maillist cache for list $mail_list_id, aborting with answer NULL");
            return null;
        }


    }
    
    private function ReturnTag($customFields,$tag_id) {
    foreach ($customFields as $field) {
        if ($field->id == $tag_id) {
            return $field->tag;
        }
    }
    }

    private function GetMailListFields($campaign_id) {
    $fields = DB::table("campaigns")->select("fields.id","fields.tag")
        ->join('campaigns_lists_segments', 'campaigns.id', '=', 'campaigns_lists_segments.campaign_id')
        ->join('mail_lists', 'campaigns_lists_segments.mail_list_id', '=', 'mail_lists.id')
        ->join("fields","mail_lists.id","=","fields.mail_list_id")->Where("campaigns.id","=",$campaign_id)->get();
    return $fields;
    }


    private function check_if_sending($uid) {
            $exists= false;
            exec("ps -C gosender -o args| grep \"$uid\"", $pids);
            if (count($pids) > 1) {
                $exists = true;
            }
            return $exists;
    }

    private function initiate_sending($servers,$done = 0) {
        if(!Redis::exists($this->uid.'_paused')) {
            $nodone = "--nodone";
            if ($done > 0) $nodone = "";
            MailLog::info("Initiating sending for campaign $this->uid");
            $this->sending();
            $deployment = \Config::get('app.deployment');
            // new implementation allows each time to check for assigned servers
            // 2020.11.18
            MailLog::info("Detecting sending servers for campaign $this->uid...");
            // get all assigned servers
            if (DB::table('campaigns')->select('all_sending_servers')
                    ->join('campaigns_lists_segments', 'campaigns.id', '=', 'campaigns_lists_segments.campaign_id')
                    ->join('mail_lists', 'campaigns_lists_segments.mail_list_id', '=', 'mail_lists.id')->where('mail_lists.id', $this->defaultMailList->id)->first()->all_sending_servers == 1) {
                $servers = \DB::table('sending_servers')->select('sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port','smtp_protocol','smtp_username','smtp_password','threads')
                    ->where('status', 'active')->where('sending_servers.id', '>', 1)->get();
            } else {
                $servers = \DB::table('campaigns')->select('sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port', 'fitness','smtp_protocol','smtp_username','smtp_password','threads')
                    ->join('campaigns_lists_segments', 'campaigns.id', '=', 'campaigns_lists_segments.campaign_id')
                    ->join('mail_lists_sending_servers', 'campaigns_lists_segments.mail_list_id', '=', 'mail_lists_sending_servers.mail_list_id')
                    ->join('sending_servers', 'mail_lists_sending_servers.sending_server_id', '=', 'sending_servers.id')
                    ->where('campaigns.uid', $this->uid)->get();
            }
            Redis::set($this->uid . '_servers', json_encode($servers));
            $default_speed = \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
            foreach ($servers as $serv) {
                $ssl = "";
                if ($serv->smtp_protocol != "") $ssl = "--ssl";
                if ($this->defaultMailList->all_sending_servers == 1) $serv->fitness = $this->defaultMailList->speed ? $serv->fitness = $this->defaultMailList->speed : $default_speed;
                if (!isset($serv->fitness)) $serv->fitness = $default_speed;
                switch ($serv->type) {
                    case "smtp":
                        MailLog::info("./gosender --send --campuid $this->uid --smtphost $serv->host --smtpport $serv->smtp_port --smtpspeed $serv->fitness --username $serv->smtp_username --password $serv->smtp_password $ssl --app $deployment $nodone");
                        exec("\$HOME/gosender --send --campuid $this->uid --smtphost $serv->host --smtpport $serv->smtp_port --smtpspeed $serv->fitness --username $serv->smtp_username --password $serv->smtp_password $ssl --app $deployment $nodone > /dev/null 2>&1 &");
                        break;
                    case "amazon-api":
                        if ($serv->threads > 1) {
                            for($i = 1; $i<=$serv->threads; $i++) {
                                MailLog::info("#THREAD $i Starting amazon-api sender service $serv->aws_access_key_id $serv->aws_secret_access_key for campaign $this->uid");
                                exec("\$HOME/gosender --send --campuid $this->uid --type $serv->type --accesskey $serv->aws_access_key_id --secretkey $serv->aws_secret_access_key --region $serv->aws_region --smtpspeed $serv->fitness --app $deployment $nodone > /dev/null 2>&1 &");
                            }
                        } else {
                            MailLog::info("Starting amazon-api sender service $serv->aws_access_key_id $serv->aws_secret_access_key for campaign $this->uid");
                            exec("\$HOME/gosender --send --campuid $this->uid --type $serv->type --accesskey $serv->aws_access_key_id --secretkey $serv->aws_secret_access_key --region $serv->aws_region --smtpspeed $serv->fitness --app $deployment $nodone > /dev/null 2>&1 &");
                        }
                        break;
                    case "sendgrid-api":
                        // api_key
                        MailLog::info("Restarting sendgrid-api sender service $serv->api_key for campaign $this->uid");
                        exec("\$HOME/gosender --send --campuid $this->uid --type $serv->type --accesskey $serv->api_key --smtpspeed $serv->fitness --app $deployment $nodone > /dev/null 2>&1 &");
                        break;
                }

                //  break; // FIXME uzkomentuoti
            }
        }
    }


    // check if email exists on PMA openai table, if it exists returns TRUE if not FALSE
    // FIXME this function is deprecated and nowhere used, please remove it ASAP
    private function check_pma($email) {
        try {
            $json = file_get_contents('http://pma.parkagency.net/api.php?sub='.$email);
            $data = json_decode($json);
            if ($data->status == 1) return true;
            else
                return false;
        } catch (\Exception $ex) {
            MailLog::info("Unable to check pma api for $email!");
            return false;
        }
    }

    // function that accepts array of emails, process them under PMA and outputs the clean array of emails
    // that are excluded from openers
    private function WithoutOpeners($emails) {
//        if (is_object($emails)) {
//            $emails = get_object_vars($emails);
//        }
try {
    $apis = 'http://pma.parkagency.net/multi_api.php';
    $ch = curl_init($apis);
    curl_setopt_array($ch, array(
        CURLOPT_POST => TRUE,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_HTTPHEADER => array(
            'Authorization: 1122',
            'Content-Type: application/json'
        ),
        CURLOPT_POSTFIELDS => json_encode($emails)
    ));
    $response = curl_exec($ch);
    if ($response === FALSE) {
        MailLog::info(curl_error($ch));
    }

    $responseData = json_decode($response, false);
    MailLog::info("WithoutOpeners returned: " . count($responseData) . " items");
    return $responseData;
} catch (\Exception $ex) {
    MailLog::error("Got errors while trying to get subscribers without openers: ".$ex);
}
    }

    /**
     * Start the campaign, using PHP fork() to launch multiple processes.
     *
     * @return mixed
     */
    public function runMultiProcesses()
        // FIXME need to use this function for running perl in the background
    {
        // processes to fork
// TESTASSS
        $count = (int) $this->customer->getOption('max_process');
// hackas
//$count = 10;
        // hack MailLog::info("Forking {$count} process(es)");
        $parentPid = getmypid();
        $children = [];
        for ($i = 0; $i < $count; $i += 1) {
            $pid = pcntl_fork();

            // for child process only
            if (!$pid) {
                // Reconnect to the DB to prevent connection closed issue when using fork
                DB::reconnect('mysql');

                // Re-initialize logging to capture the child process' PID
                // hack MailLog::fork();

                // hack MailLog::info(sprintf("Start child process %s of %s (forked from %s)", $i + 1, $count, $parentPid));
                sleep(self::WORKER_DELAY);
                $partition = [$i, $count];
                $this->run($partition);

                exit($i + 1);
                // end child process
            } else {
                $children[] = $pid;
            }
        }

        // wait for child processes to finish
        foreach($children as $child) {
            $pid = pcntl_wait($status);
            if (pcntl_wifexited($status)) {
                $code = pcntl_wexitstatus($status);
                // hack MailLog::info("Child process $pid finished, status code: $code");
            } else {
                // hack MailLog::warning("Child process $pid did not normally exit");
                $this->error("Child process $pid did not normally exit");
            }
        }

        // after all child processes are done
        $this->refreshStatus();

        // If all child processes finish sucessfully, just mark campaign as done
        // Otherwise, mark campaign with error status (by child process)
        //
        // There are conventions here:
        //   + Child process does not update status from SENDING to DONE, it is left for the parent process
        //   + Child process only updates status from SENDING to ERROR
        //   + Parent process only updates status to DONE when current status is SENDING
        //     indicating that all child processes finish sucessfully
        //   + In case one child process update the status from SENDING to ERROR, it is left as the final status
        //     the latest error is kept
        if ($this->status == self::STATUS_SENDING) {
            $this->done();
        } else {
            // leave the latest error reported by child process
        }
    }



    private function getSecKey($mail,$action) {
        $string = $mail . $action . config('app.key');

        return md5($string);
    }

    public static function DeferredProcessing($uid) {
        $campobj = \Acelle\Model\Campaign::findByUid($uid)->get();
        // look at he campaign we choose
        $status = json_decode(Redis::get($campobj->uid))->status;
        $wait = json_decode(Redis::get($campobj->uid,'_deferred_setting'))->wait;
        // jeigu status done, pakeiciam statusa i preparing
//        if ($status == "done") {
//            $tmpjson = json_decode(Redis::get($uid));
//            $tmpjson->status = "preparing";
//            Redis::set($uid,json_encode($tmpjson));
//        }
        $resend_count = Redis::llen($uid.'_undelivered_val');
        if ($resend_count > 0) {
            MailLog::info("DEFERRED: Starting deferred RETRY send procedure for campaign: ".$campobj->name);
            //\DB::connection()->disableQueryLog();
            $builder = $campobj->subscribers([], [])
                ->select('subscribers.id','subscribers.uid','subscribers.mail_list_id','subscribers.email','subscribers.status','tracking_logs.message_id')
                ->whereRaw(sprintf(table('subscribers').'.id IN (SELECT subscriber_id FROM %s WHERE campaign_id = %s)', table('tracking_logs'), $campobj->id))
                ->whereRaw(DB::getTablePrefix()."subscribers.email NOT IN (select email from blacklists)")
                ->whereRaw(DB::getTablePrefix()."subscribers.status = '".Subscriber::STATUS_SUBSCRIBED."'");
            $subscribers_raw = $builder->get();
            $subscribers_total = count($subscribers_raw);
            MailLog::info('DEFERRED: We got selected all subscribers: '.$subscribers_total);
            MailLog::info("DEFERRED: Inserting data to the redis backend...");
            $global_tracking = Setting::get('global_tracking');
            $url_open_track = Setting::get('url_open_track');
            $url_unsubscribe = Setting::get('url_unsubscribe');
            $url_update_profile = Setting::get('url_update_profile');
            $subscribers_final_count = 0;
            $wait_first = 0;
            foreach ($subscribers_raw as $sub ) {
                if (Redis::hexists($campobj->uid . '_undelivered_data', $sub->email) && !Redis::hexists('blacklists', $sub->email)) {
                    $type = "";
                    try {
                        $type = json_decode(Redis::hget($campobj->uid . '_undelivered_data', $sub->email))->type;
                    } catch (\Exception $ex) {

                    }
                    if ($type == "deferred") {
                        // wait time here
                        if ($wait_first == 0) {
                            sleep($wait);
                        }
                        $wait_first=1;

                        $message_id = $sub->message_id;
                        $pieces = explode("@", $message_id);
                        $sub->open_tracking = $pieces[0] . "@{SRVBL}";
                        $sub->track_url = $global_tracking;
                        $sub->tracking_url = str_replace('{CAMPAIGN}', StringHelper::generateCampaignName(), $url_open_track);
                        $sub->unsubscribe_url = str_replace('{CAMPAIGN}', StringHelper::generateUnsubscribeUrl(), str_replace('MESSAGE_ID', StringHelper::base64UrlEncode2($message_id), $url_unsubscribe));
                        $sub->update_url = str_replace('{RANDOM}', StringHelper::generateCampaignName(), str_replace('LIST_UID', $campobj->defaultMailList->uid, str_replace('SUBSCRIBER_UID', $campobj->uid, str_replace('SECURE_CODE', $campobj->getSecKey($sub->email, 'update-profile'), $url_update_profile))));
                        $sub->msgid1 = StringHelper::base64UrlEncode($message_id);
                        $sub->msgid2 = StringHelper::base64UrlEncode3($message_id);
                        Redis::sAdd("campaign_" . $campobj->uid . "_subscribers", \json_encode($sub));
                        Redis::hdel($campobj->uid . '_undelivered_data', $sub->email);
                        Redis::decr($campobj->uid .'_deferred');
                        $subscribers_final_count++;
                    }
                }
            }
            MailLog::info("DEFERRED: Insert to the redis backend DONE!");
            if ($subscribers_final_count > 0) {
                MailLog::info('Got final subscribers count: ' . $subscribers_final_count);
                MailLog::info('BACKGROUND RETRY SENDING PROCESSES - We need to resend to: ' . $subscribers_final_count . ' subscribers from campaign: ' . $uid);
                Redis::del($campobj->uid.'_undelivered_val');
                // Update counter
                if (Redis::exists($campobj->uid.'_counter')) {
                    $counter = Redis::get($campobj->uid.'_counter');
                    $counter = ($counter - $subscribers_final_count);
                    Redis::set($campobj->uid.'_counter',$counter);
                }

                $status = json_decode(Redis::get($uid))->status;
             /*   if ($status == "preparing") {
                    $campobj->sending();
                    $campobj->save();
                    if ($campobj->defaultMailList->all_sending_servers == 1) {
                        MailLog::info("DEFERRED: BACKGROUND RETRY SENDING PROCESSES - All sending servers are enabled for campaign $uid");
                        $servers = \DB::table('sending_servers')->select('sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port','smtp_protocol','smtp_username','smtp_password','threads')->where('status', 'active')->where('sending_servers.id', '>', 1)->get();
                    } else {
                        MailLog::info("DEFERRED: BACKGROUND RETRY SENDING PROCESSES - Only specified servers are enabled for campaign $uid");
                        $servers = \DB::table('campaigns')->select('sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port', 'fitness','smtp_protocol','smtp_username','smtp_password','threads')
                            ->join('campaigns_lists_segments', 'campaigns.id', '=', 'campaigns_lists_segments.campaign_id')
                            ->join('mail_lists_sending_servers', 'campaigns_lists_segments.mail_list_id', '=', 'mail_lists_sending_servers.mail_list_id')
                            ->join('sending_servers', 'mail_lists_sending_servers.sending_server_id', '=', 'sending_servers.id')
                            ->where('campaigns.uid', $uid)->get();
                    }
                    Redis::set($uid.'_servers',json_encode($servers));
                    $deployment = \Config::get('app.deployment');
                    $default_speed = \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
                    foreach ($servers as $serv) {
                        if ($campobj->defaultMailList->all_sending_servers == 1) $serv->fitness = $campobj->defaultMailList->speed ? $serv->fitness = $campobj->defaultMailList->speed : $default_speed;
                        if (!isset($serv->fitness)) $serv->fitness = $default_speed;
                        $ssl = "";
                        if ($serv->smtp_protocol != "") $ssl = "--ssl";
                        switch ($serv->type) {
                            case "smtp":
                                MailLog::info("./gosender --send --campuid $campobj->uid --smtphost $serv->host --smtpport $serv->smtp_port --smtpspeed $serv->fitness --username $serv->smtp_username --password $serv->smtp_password $ssl --app $deployment --nodone");
                                exec("\$HOME/gosender --send --campuid $campobj->uid --smtphost $serv->host --smtpport $serv->smtp_port --smtpspeed $serv->fitness --username $serv->smtp_username --password $serv->smtp_password $ssl --app $deployment --nodone > /dev/null 2>&1 &");
                                break;
                            case "amazon-api":
                                if ($serv->threads > 1) {
                                    for($i = 1; $i<=$serv->threads; $i++) {
                                        MailLog::info("#THREAD $i Restarting amazon-api sender service $serv->aws_access_key_id $serv->aws_secret_access_key for campaign $campobj->uid");
                                        exec("\$HOME/gosender --send --campuid $campobj->uid --type $serv->type --accesskey $serv->aws_access_key_id --secretkey $serv->aws_secret_access_key --region $serv->aws_region --smtpspeed $serv->fitness --app $deployment --nodone > /dev/null 2>&1 &");
                                    }
                                } else {
                                    MailLog::info("Restarting amazon-api sender service $serv->aws_access_key_id $serv->aws_secret_access_key for campaign $campobj->uid");
                                    exec("\$HOME/gosender --send --campuid $campobj->uid --type $serv->type --accesskey $serv->aws_access_key_id --secretkey $serv->aws_secret_access_key --region $serv->aws_region --smtpspeed $serv->fitness --app $deployment --nodone > /dev/null 2>&1 &");
                                }
                                break;
                            case "sendgrid-api":
                                // api_key
                                MailLog::info("Restarting sendgrid-api sender service $serv->api_key for campaign $campobj->uid");
                                exec("\$HOME/gosender --send --campuid $campobj->uid --type $serv->type --accesskey $serv->api_key --smtpspeed $serv->fitness --app $deployment --nodone > /dev/null 2>&1 &");
                                break;
                        }
                    }
                }*/ // if status preparing
            } // if there are some subscribers

        } else {
            MailLog::info("DEFERRED: No deferred entries in table for campaign $campobj->name");
        }

    }

    // RESEND TECHNIQUE
    public static function RetryBackGroundCampaign($uids) {
        $deployment = \Config::get('app.deployment');
        $campaigns = array();
        if(!is_array($uids) && strpos($uids, ',') !== false ) $uids = explode(',', $uids);
        if (is_array($uids)) {
            $campaigns = $uids;
        } else {
            $campaigns[] = $uids;
        }
        foreach ($campaigns as $camp) {
            // ############# BEGIN ################
            MailLog::info("BACKGROUND RETRY SENDING PROCESSES START IS ISSUED FOR CAMPAIGN $camp! ! !");
            if ($camp != "") {
                // TODO MORE BEUTIFULL WAY
                //$default_fitness = \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
                $campobj = \Acelle\Model\Campaign::findByUid($camp);
                if (Redis::exists($camp)) $status = json_decode(Redis::get($camp))->status;
                else $status = "error";
                if (is_object($campobj) && $status == "done") {
                exec("kill `ps x|grep -v grep|grep $camp|awk '{ print \$1}'` > /dev/null 2>&1 &");
// SET LOCK, TODO ant else visur uzdet kad pakeistu statusa atgal koks buvo jeigu kazkas nepavyksta
                $tmpjson = json_decode(Redis::get($camp));
                $tmpjson->status = "preparing";
                Redis::set($camp,json_encode($tmpjson));
               // $campobj->preparing();
               // $campobj->save();
                    if ($campobj->defaultMailList->all_sending_servers == 1) {
                        MailLog::info("BACKGROUND RETRY SENDING PROCESSES - All sending servers are enabled for campaign $camp");
                        $servers = \DB::table('sending_servers')->select('sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port','smtp_protocol','smtp_username','smtp_password','threads')->where('status', 'active')->where('sending_servers.id', '>', 1)->get();
                    } else {
                        MailLog::info("BACKGROUND RETRY SENDING PROCESSES - Only specified servers are enabled for campaign $camp");
                        $servers = \DB::table('campaigns')->select('sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port', 'fitness','smtp_protocol','smtp_username','smtp_password','threads')
                            ->join('campaigns_lists_segments', 'campaigns.id', '=', 'campaigns_lists_segments.campaign_id')
                            ->join('mail_lists_sending_servers', 'campaigns_lists_segments.mail_list_id', '=', 'mail_lists_sending_servers.mail_list_id')
                            ->join('sending_servers', 'mail_lists_sending_servers.sending_server_id', '=', 'sending_servers.id')
                            ->where('campaigns.uid', $campobj->uid)->get();
                    }
                    Redis::set($campobj->uid.'_servers',json_encode($servers));
                    $resend_count = Redis::llen($camp.'_undelivered_val');
if ($resend_count > 0) {
    MailLog::info("Starting subscribers RETRY send procedure for campaign: ".$campobj->name);
    \DB::connection()->disableQueryLog();
    MailLog::info("Selecting the subscribers");
    $builder = $campobj->subscribers([], [])
        ->select('subscribers.id','subscribers.uid','subscribers.mail_list_id','subscribers.email','subscribers.status','tracking_logs.message_id')
        ->whereRaw(sprintf(table('subscribers').'.id IN (SELECT subscriber_id FROM %s WHERE campaign_id = %s)', table('tracking_logs'), $campobj->id))
        ->whereRaw(DB::getTablePrefix()."subscribers.email NOT IN (select email from blacklists)")
        ->whereRaw(DB::getTablePrefix()."subscribers.status = '".Subscriber::STATUS_SUBSCRIBED."'");
    $subscribers_raw = $builder->get();
    $subscribers_total = count($subscribers_raw);

        MailLog::info('We got selected all subscribers: '.$subscribers_total);
 //       $subscribers_final = array();
        MailLog::info("Inserting data to the redis backend...");
        $global_tracking = Setting::get('global_tracking');
        $url_open_track = Setting::get('url_open_track');
        $url_unsubscribe = Setting::get('url_unsubscribe');
        $url_update_profile = Setting::get('url_update_profile');
        $url_click_track = Setting::get('url_click_track');
        if (Redis::exists("campaign_".$campobj->uid."_subscribers")) Redis::del("campaign_".$campobj->uid."_subscribers");
    $subscribers_final_count = 0;
      foreach ($subscribers_raw as $sub ) {
          if (Redis::hexists($campobj->uid.'_undelivered_data',$sub->email)&&!Redis::hexists('blacklists',$sub->email)) {
              $message_id = $sub->message_id;
              $pieces = explode("@", $message_id);
              $sub->open_tracking = $pieces[0] ."@{SRVBL}";
//              $subscribers_final[] = [ "message_id" => $message_id, "customer_id" => $campobj->customer->id, "sending_server_id" => 1, "campaign_id" => $campobj->id, "subscriber_id" => $sub->id, "subscriber_email" => $sub->email, "status" => 'sent' ];
              $sub->track_url = $global_tracking;
              //$sub->tracking_url = str_replace('{CAMPAIGN}', StringHelper::generateCampaignName(), str_replace('MESSAGE_ID', StringHelper::base64UrlEncode($message_id), $url_open_track));
              // FIXME new impmenentation 2018.11.02
              $sub->tracking_url = str_replace('{CAMPAIGN}', StringHelper::generateCampaignName(), $url_open_track);
              $sub->unsubscribe_url = str_replace(   '{CAMPAIGN}', StringHelper::generateUnsubscribeUrl(), str_replace('MESSAGE_ID', StringHelper::base64UrlEncode2($message_id), $url_unsubscribe));
              $sub->update_url = str_replace( '{RANDOM}', StringHelper::generateCampaignName(), str_replace('LIST_UID', $campobj->defaultMailList->uid, str_replace('SUBSCRIBER_UID', $campobj->uid, str_replace('SECURE_CODE', $campobj->getSecKey($sub->email, 'update-profile'), $url_update_profile))));
              $sub->msgid1 = StringHelper::base64UrlEncode($message_id);
              $sub->msgid2 = StringHelper::base64UrlEncode3($message_id);
              Redis::sAdd("campaign_".$campobj->uid."_subscribers",\json_encode($sub));
            //  MailLog::info('Nusiusti sitam is naujo '.json_encode($sub));
              $subscribers_final_count++;
          }

      }
      MailLog::info("Insert to the redis backend DONE!");

      // continue if we got enough subscribers
    if ($subscribers_final_count > 0) {
        MailLog::info('Got final subscribers count: ' . $subscribers_final_count);
        MailLog::info('BACKGROUND RETRY SENDING PROCESSES - We need to resend to: ' . $subscribers_final_count . ' subscribers from campaign: ' . $camp);
        // before the actual send we need to reset statistics ant clean the old data storages
        // redis del val,data
        // cassandra ir mysql redaguoti nereiks, nes ten jau turetu uztekti esamu duomenu apie ta pati trackinga
        Redis::del($campobj->uid.'_undelivered_data');
        Redis::del($campobj->uid.'_undelivered_val');
        Redis::del($campobj->uid.'_deferred');
        Redis::del($campobj->uid.'_bounced');
        // Update counter
        if (Redis::exists($campobj->uid.'_counter')) {
            $counter = Redis::get($campobj->uid.'_counter');
            $counter = ($counter - $subscribers_final_count);
            Redis::set($campobj->uid.'_counter',$counter);
          }
        $campobj->sending();
        $campobj->save();
        $deployment = \Config::get('app.deployment');
        Redis::set($campobj->uid.'_servers',json_encode($servers));
        $default_speed = \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
        foreach ($servers as $serv) {
            if ($campobj->defaultMailList->all_sending_servers == 1) $serv->fitness = $campobj->defaultMailList->speed ? $serv->fitness = $campobj->defaultMailList->speed : $default_speed;
            if (!isset($serv->fitness)) $serv->fitness = $default_speed;
            $ssl = "";
            if ($serv->smtp_protocol != "") $ssl = "--ssl";
            switch ($serv->type) {
                case "smtp":
                    MailLog::info("./gosender --send --campuid $campobj->uid --smtphost $serv->host --smtpport $serv->smtp_port --smtpspeed $serv->fitness --username $serv->smtp_username --password $serv->smtp_password $ssl --app $deployment --nodone");
                    exec("\$HOME/gosender --send --campuid $campobj->uid --smtphost $serv->host --smtpport $serv->smtp_port --smtpspeed $serv->fitness --username $serv->smtp_username --password $serv->smtp_password $ssl --app $deployment --nodone > /dev/null 2>&1 &");
                    break;
                case "amazon-api":
                    if ($serv->threads > 1) {
                        for($i = 1; $i<=$serv->threads; $i++) {
                            MailLog::info("#THREAD $i Restarting amazon-api sender service $serv->aws_access_key_id $serv->aws_secret_access_key for campaign $campobj->uid");
                            exec("\$HOME/gosender --send --campuid $campobj->uid --type $serv->type --accesskey $serv->aws_access_key_id --secretkey $serv->aws_secret_access_key --region $serv->aws_region --smtpspeed $serv->fitness --app $deployment --nodone > /dev/null 2>&1 &");
                        }
                    } else {
                        MailLog::info("Restarting amazon-api sender service $serv->aws_access_key_id $serv->aws_secret_access_key for campaign $campobj->uid");
                        exec("\$HOME/gosender --send --campuid $campobj->uid --type $serv->type --accesskey $serv->aws_access_key_id --secretkey $serv->aws_secret_access_key --region $serv->aws_region --smtpspeed $serv->fitness --app $deployment --nodone > /dev/null 2>&1 &");
                    }
                    break;
                case "sendgrid-api":
                    // api_key
                    MailLog::info("Restarting sendgrid-api sender service $serv->api_key for campaign $campobj->uid");
                    exec("\$HOME/gosender --send --campuid $campobj->uid --type $serv->type --accesskey $serv->api_key --smtpspeed $serv->fitness --app $deployment --nodone > /dev/null 2>&1 &");
                    break;
            }
        }
    } else {
        MailLog::error('BACKGROUND RETRY SENDING PROCESS - Got 0 final subscribers in the final match, skipping ...');
    }
} else {
    MailLog::error('BACKGROUND RETRY SENDING PROCESSES - No data about undelivered subscribers was found, skipping...');
    if (Redis::exists($camp)) {
        $tmpjson = json_decode(Redis::get($camp));
        $tmpjson->status = "done";
        Redis::set($camp,json_encode($tmpjson));
    }

}

                } else {
                    MailLog::error("BACKGROUND RETRY SENDING START FAILED, FOR CAMPAIGN $camp, BECAUSE STATUS IS NOT DONE ! ! !");
                }

                MailLog::info("DONE STARTING BACKGROUND RETRY SENDING PROCESSES OF CAMPAIGN $camp! ! !");


            } else {
                MailLog::error("BACKGROUND RETRY SENDING FAILED BECAUSE SPECIFIED CAMPAIGN $camp UID IS INVALID!");
            }




            // ############ END ##################
        }

    }


/*
 *  Universal PlugNplay function for restarting the campaigns by specifying their uids, it can accept string or variable array with uid or uids
 *  It can be called and is called from SystemJobs, Controllers and Views, so it is more universal than is actually working
 *  impl date: 2018.08.09
 */


    public static function RestartBackroundProcesses($uids) {
        $deployment = \Config::get('app.deployment');
        $campaigns = array();
        if(!is_array($uids) && strpos($uids, ',') !== false ) $uids = explode(',', $uids);
        if (is_array($uids)) {
            $campaigns = $uids;
        } else {
            $campaigns[] = $uids;
        }
        foreach ($campaigns as $camp) {
            // ############# BEGIN ################
            MailLog::info("BACKGROUND SENDING PROCESSES RESTART IS ISSUED FOR CAMPAIGN $camp! ! !");

            if ($camp != "") {
                // TODO MORE BEUTIFULL WAY
                exec("kill `ps x|grep -v grep|grep $camp|awk '{ print \$1}'` > /dev/null 2>&1 &");
                $default_fitness = \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
                $campobj = \Acelle\Model\Campaign::findByUid($camp);
                if (Redis::exists($camp)) $status = json_decode(Redis::get($camp))->status;
                else $status = "error";
                if (is_object($campobj) && $status == "sending") {
                    // we should get the type of sending process are used in the campaign by quering its maillist, then use smtp or ses based sending
                    if ($campobj->defaultMailList->all_sending_servers == 1) {
                        MailLog::info("BACKGROUND SENDING PROCESSES RESTART - All sending servers are enabled for campaign $camp");
                        $servers = \DB::table('sending_servers')->select('sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port', 'smtp_protocol','smtp_username','smtp_password','threads')->where('status', 'active')->where('sending_servers.id', '>', 1)->get();
                    } else {
                        MailLog::info("BACKGROUND SENDING PROCESSES RESTART - Only specified servers are enabled for campaign $camp");
                        $servers = \DB::table('campaigns')->select('sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port', 'fitness','smtp_protocol','smtp_username','smtp_password','threads')
                            ->join('campaigns_lists_segments', 'campaigns.id', '=', 'campaigns_lists_segments.campaign_id')
                            ->join('mail_lists_sending_servers', 'campaigns_lists_segments.mail_list_id', '=', 'mail_lists_sending_servers.mail_list_id')
                            ->join('sending_servers', 'mail_lists_sending_servers.sending_server_id', '=', 'sending_servers.id')
                            ->where('campaigns.uid', $campobj->uid)->get();
                    }
                    Redis::set($campobj->uid.'_servers',json_encode($servers));
                    foreach ($servers as $servas) {
                        $ssl = "";
                        if ($servas->smtp_protocol != "") $ssl = "--ssl";
                        if (!isset($servas->fitness)) $servas->fitness = $default_fitness;
                        if ($campobj->defaultMailList->all_sending_servers == 1) $servas->fitness = $campobj->defaultMailList->speed;
                        switch ($servas->type) {
                            case "smtp":
                                MailLog::info("\$HOME/gosender --send --campuid $campobj->uid --smtphost $servas->host --smtpport $servas->smtp_port --smtpspeed $servas->fitness --username $servas->smtp_username --password $servas->smtp_password $ssl --app $deployment --nodone > /dev/null 2>&1 &");
                                exec("\$HOME/gosender --send --campuid $campobj->uid --smtphost $servas->host --smtpport $servas->smtp_port --smtpspeed $servas->fitness --username $servas->smtp_username --password $servas->smtp_password $ssl --app $deployment --nodone > /dev/null 2>&1 &");
                                break;
                            case "amazon-api":
                                if ($servas->threads > 1) {
                                    for ($i = 1; $i <= $servas->threads; $i++) {
                                        MailLog::info("#THREAD $i  Restarting amazon-api sender service $servas->aws_access_key_id $servas->aws_secret_access_key for campaign $campobj->uid");
                                        exec("\$HOME/gosender --send --campuid $campobj->uid --type $servas->type --accesskey $servas->aws_access_key_id --secretkey $servas->aws_secret_access_key --region $servas->aws_region --smtpspeed $servas->fitness --app $deployment --nodone > /dev/null 2>&1 &");
                                    }
                                } else {
                                    MailLog::info("Restarting amazon-api sender service $servas->aws_access_key_id $servas->aws_secret_access_key for campaign $campobj->uid");
                                    exec("\$HOME/gosender --send --campuid $campobj->uid --type $servas->type --accesskey $servas->aws_access_key_id --secretkey $servas->aws_secret_access_key --region $servas->aws_region --smtpspeed $servas->fitness --app $deployment --nodone > /dev/null 2>&1 &");
                                }
                                break;
                            case "sendgrid-api":
                                // api_key
                                MailLog::info("Restarting sendgrid-api sender service $servas->api_key for campaign $campobj->uid");
                                exec("\$HOME/gosender --send --campuid $campobj->uid --type $servas->type --accesskey $servas->api_key --smtpspeed $servas->fitness --app $deployment --nodone > /dev/null 2>&1 &");
                                break;
                        }

                    }

                } else {
                    MailLog::error("BACKGROUND SENDING RESTART FAILED, BECAUSE THE SPECIFIED CAMPAIGN $camp STATUS IS NOT SENDING OR CURRENT UID DOES NOT EXIST AT ALL ! ! !");
                }

                MailLog::info("DONE RESTARTING BACKGROUND SENDING PROCESSES OF CAMPAIGN $camp! ! !");


            } else {
                MailLog::error("BACKGROUND SENDING RESTART FAILED SPECIFIED CAMPAIGN $camp UID IS INVALID!");
            }




            // ############ END ##################
        }


 }

    public static function QueueBackgroundSending($uid)
    {
        $debug = 0;
        try {
            $json = json_decode(Redis::get($uid));
            $campobj = \Acelle\Model\Campaign::findByUid($uid);
            if (is_object($campobj) && $json->status == 'done' && !Redis::exists($uid.'_canceled' && !Redis::exists($uid.'_locked'))&&Redis::hlen($uid . '_undelivered_data')>0) {
                // locking
                Redis::set($uid.'_locked',1);
                // we set the status to preparing
                // TODO enable these
                $campobj->sending();
                $campobj->save();


                // TODO we should here update mysql status too
                // MYSQL::where uid $uid set status = 'preparing'
                // initialize the required helper classes
                $Rusys = new RusysHelper();

                // we should at first select all this campaign subscribers and group them by domain
                /*
                 * SELECT substring_index(email, '@', -1), COUNT(*) AS Kiekis FROM `subscribers` GROUP BY substring_index(email, '@', -1) ORDER BY Kiekis DESC
                 */
                MailLog::info('BACKGROUND QUEUE - Selecting the domains and grouping them...');
                while (true) {
                    $builder = $campobj->subscribers([], [])
                        ->select(DB::raw("substring_index(email, '@', -1) as domain, count(*) as kiekis"))
                        ->whereRaw(sprintf(table('subscribers') . '.id IN (SELECT subscriber_id FROM %s WHERE campaign_id = %s)', table('tracking_logs'), $campobj->id))
                        ->whereRaw(DB::getTablePrefix() . "subscribers.email NOT IN (select email from blacklists)")
                        ->whereRaw(DB::getTablePrefix() . "subscribers.status = '" . Subscriber::STATUS_SUBSCRIBED . "'")
                        ->groupBy("domain")
                        ->orderBy('kiekis', 'desc');
                    $domains = $builder->get();
                    if (isset($domains) || count($domains) == 0) break;
                    if ($Rusys->check_cancel($uid)) break;
                    MailLog::info('BACKGROUND QUEUE - Query1 mysql does not worked, retrying after some time...');
                    sleep(10);
                }

                if ($Rusys->check_cancel($uid)) throw New CancellationException();

                // clear the bounce logs
                //Redis::del($campobj->uid.'_undelivered_data');
                Redis::del($campobj->uid.'_undelivered_val');
                //Redis::del($campobj->uid.'_deferred');
                //Redis::del($campobj->uid.'_bounced');

                // set the statistics
                $internal_data=array();
                foreach ($domains as $setas) {
                    $internal_data[$setas->domain] = [ 'kiekis' => $setas->kiekis,'current' => 0 ];
                }
                Redis::set($campobj->uid.'_locked',json_encode($internal_data));

                foreach ($domains as $domain) {
                    if ($debug > 0) MailLog::info("BACKGROUND QUEUE - We got $domain->domain with count $domain->kiekis");
                    $json = json_decode(Redis::get($uid));
                    $json->status = 'preparing';
                    Redis::set($uid,json_encode($json));

                    while (true) {
                        $builder = $campobj->subscribers([], [])
                            ->select('subscribers.id', 'subscribers.uid', 'subscribers.mail_list_id', 'subscribers.email', 'subscribers.status')
                            ->whereRaw("substring_index(subscribers.email, '@', -1) = '" . $domain->domain . "'")
                            ->whereRaw(sprintf(table('subscribers') . '.id IN (SELECT subscriber_id FROM %s WHERE campaign_id = %s)', table('tracking_logs'), $campobj->id))
                            ->whereRaw(DB::getTablePrefix() . "subscribers.email NOT IN (select email from blacklists)")
                            ->whereRaw(DB::getTablePrefix() . "subscribers.status = '" . Subscriber::STATUS_SUBSCRIBED . "'");
                        $subscribers_raw = $builder->get();
                        if (isset($domains) || count($domains) == 0) break;
                        if ($Rusys->check_cancel($uid)) break;
                        if ($debug > 0) MailLog::info('BACKGROUND QUEUE - Query2 mysql does not worked, retrying after some time...');
                        sleep(10);
                    }
                    if ($debug > 1) MailLog::info("BACKGROUND QUEUE - got subscribers: " . $subscribers_raw);
                    if (Redis::exists("campaign_" . $campobj->uid . "_subscribers")) Redis::del("campaign_" . $campobj->uid . "_subscribers");
                    $subscribers_final_count = 0;
                    // foreach subscribers and prepare them for sending
                    if (count($subscribers_raw) > 0) {
                        // $global_tracking = Setting::get('global_tracking');
                        // $url_open_track = Setting::get('url_open_track');
                        // $url_unsubscribe = Setting::get('url_unsubscribe');
                        // $url_update_profile = Setting::get('url_update_profile');
                        // $url_click_track = Setting::get('url_click_track');

                        foreach ($subscribers_raw as $sub) {
                            if (Redis::hExists($campobj->uid . '_undelivered_data', $sub->email) && Redis::hExists('campaign_' . $campobj->uid . '_static', $sub->email) && !Redis::hExists('blacklists',$sub->email)) {
                             //   MailLog::info('Preparing for sending subscriber: ' . $sub->email);

                                // turi buti
                                /*
                                 * {\"id\":21482327,\"uid\":\"5b578237e6655\",\"mail_list_id\":\"85\",\"email\":\"testas@test.lt\",\"status\":\"subscribed\",\"opened_at\":\"2018-08-27 06:16:24\",\
                                 * "from\":\"\",\"ip\":\"\",\"created_at\":\"2018-07-24 19:47:04\",\"updated_at\":\"2018-08-27 06:16:24\",\"subscription_type\":null,\
                                 * "message_id\":\"1535706842418128.5b8906daf36b1@.\",\"track_url\":\"trsam.com\\r\",\"tracking_url\":\
                                 * "http:\\/\\/{DOMAIN}\\/6551916c0a0m0p2085829\\/MTUzNTcwNjg0MjQxODEyOC41Yjg5MDZkYWYzNmIxQC4,\\/{OPENPART}\",\"unsubscribe_url\":\
                                 * "http:\\/\\/{DOMAIN}\\/5093162b0d03v91087580\\/MTUzNTcwNjg0MjQxODEyOC41Yjg5MDZkYWYzNmIxQC4\\/{UNSUBSCRIBEPART}\",\"update_url\":\"
                                 * http:\\/\\/{DOMAIN}\\/6676058c0a0m0p2472047\\/5b55d0c820496\\/{PROFILEPART}\\/5b578237e6655\\/7e0eafd50c2203faf09793f625579d03\",\"msgid1\":\"MTUzNTcwNjg0MjQxODEyOC41Yjg5MDZkYWYzNmIxQC4,\",\"
                                 * msgid2\":\"MzQ1NjU3MLMwMTIxtDA0stAzTbKwNDBLSUwzNksydNADAA,,\"}"
                                 */
                                $stored_subscriber = json_decode(Redis::hget('campaign_' . $campobj->uid . '_static', $sub->email));
                                $undelivered_subscriber = json_decode(Redis::hget($campobj->uid . '_undelivered_data', $sub->email));
                                $sub->message_id = $stored_subscriber->message_id;
                                $sub->open_tracking = $stored_subscriber->open_tracking;
                                $sub->track_url = $stored_subscriber->track_url; // can be change using $global_tracking
                                $sub->tracking_url = $stored_subscriber->tracking_url; // can be regenerated as before sending
                                $sub->unsubscribe_url = $stored_subscriber->unsubscribe_url; // can be regenerated as before sending
                                $sub->update_url = $stored_subscriber->update_url; // can be regenerated as before sending
                                $sub->msgid1 = $stored_subscriber->msgid1; // can be regenerated as before sending
                                $sub->msgid2 = $stored_subscriber->msgid2; // can be regenerated as before sending
                                Redis::sAdd("campaign_" . $campobj->uid . "_subscribers", \json_encode($sub));
                                // get the type of undelivered subscriber and decrease the needed key value
                                Redis::hdel($campobj->uid . '_undelivered_data', $sub->email);
                                Redis::decr($campobj->uid . "_" . $undelivered_subscriber->type);
                                $subscribers_final_count++;

                            } // end if redis

                        } // subscribers foreach end
                        // free some resources
                        unset($subscribers_raw);
                        // Update counter
                        if ($subscribers_final_count > 0) {
                        if (Redis::exists($campobj->uid . '_counter')) {
                            $counter = Redis::get($campobj->uid . '_counter');
                            $counter = ($counter - $subscribers_final_count);
                            Redis::set($campobj->uid . '_counter', $counter);
                        }

                        // write some statistics about the current position of sending
                            $internal_data=array();
                            foreach ($domains as $setas) {
                                $current = 0;
                                if ($setas->domain == $domain->domain) $current = 1;
                                $internal_data[$setas->domain] = [ 'kiekis' => $setas->kiekis,'current' => $current ];
                            }
                            Redis::set($campobj->uid.'_locked',json_encode($internal_data));

                        // do the sending here

                        $tmpjson = json_decode(Redis::get($uid));
                        $tmpjson->status = "sending";
                        Redis::set($uid, json_encode($tmpjson));
                        // delivery start
                        $Rusys->deliver_email_from_campaign($uid, $Rusys->getserverbydomain('sent', $domain->domain));
                    } else {
                        if ($debug > 0) MailLog::info("BACKGROUND QUEUE - got 0 subscribers in the bounce/deferred log with $domain->domain moving to the next one...");
                    }

                    } else {
                        if ($debug > 0) MailLog::info("BACKGROUND QUEUE - Subscribers for $domain->domain not found! We will continue with other domains");
                    }
                } // domain foreach end


                // set the campaign status to it's normal
                $tmpjson = json_decode(Redis::get($uid));
                $tmpjson->status = "done";
                Redis::set($uid,json_encode($tmpjson));
                $campobj->done();
                $campobj->save();
                Redis::del($uid.'_locked');
                MailLog::info("BACKGROUND QUEUE - QUEUE BACKROUND SEND IS DONE FOR CAMPAIGN: ".$campobj->name." uid: " . $uid);
            }

        } catch (CancellationException $e) {
            MailLog::error("BACKGROUND QUEUE - Just got cancelled");
            // restore some of variables...
            $tmpjson = json_decode(Redis::get($uid));
            $tmpjson->status = "done";
            Redis::set($uid,json_encode($tmpjson));
            $campobj->done();
            $campobj->save();
            Redis::del($uid.'_locked');
        }

        catch (\Exception $ex) {
            MailLog::error("BACKGROUND QUEUE - GOT SOME SERIOUS ERROR: ".$ex);
            $tmpjson = json_decode(Redis::get($uid));
            $tmpjson->status = "done";
            Redis::set($uid,json_encode($tmpjson));
            $campobj->done();
            $campobj->save();
            Redis::del($uid.'_locked');
    }
    }

    /**
     * Transform Tags
     * Transform tags to actual values before sending.
     */
    public function tagMessage($message, $subscriber, $msgId, $server = null)
    {
        if (!is_null($server) && $server->isElasticEmailServer()) {
// TEST
//            $message = $server->addUnsubscribeUrl($message);
        }

        // @todo consider a solution for UNSUBSCRIBE_URL for test subscriber (also for other tags like: UPDATE_PROFILE_URL)
        $tags = array(
         /*   'SUBSCRIBER_EMAIL' => $subscriber->email,*/
            'CAMPAIGN_NAME' => $this->name,
            'CAMPAIGN_UID' => $this->uid,
            'CAMPAIGN_SUBJECT' => $this->subject,
            'CAMPAIGN_FROM_EMAIL' => $this->from_email,
            'CAMPAIGN_FROM_NAME' => $this->from_name,
            'CAMPAIGN_REPLY_TO' => $this->reply_to,
        /*    'SUBSCRIBER_UID' => $subscriber->uid, */
            'CURRENT_YEAR' => date('Y'),
            'CURRENT_MONTH' => date('m'),
            'CURRENT_DAY' => date('d'),
            'UNSUBSCRIBE_URL' => str_replace('MESSAGE_ID', StringHelper::base64UrlEncode($msgId), Setting::get('url_unsubscribe')),
            'CONTACT_NAME' => $this->defaultMailList->contact->company,
            'CONTACT_COUNTRY' => $this->defaultMailList->contact->country->name,
            'CONTACT_STATE' => $this->defaultMailList->contact->state,
            'CONTACT_CITY' => $this->defaultMailList->contact->city,
            'CONTACT_ADDRESS_1' => $this->defaultMailList->contact->address_1,
            'CONTACT_ADDRESS_2' => $this->defaultMailList->contact->address_2,
            'CONTACT_PHONE' => $this->defaultMailList->contact->phone,
            'CONTACT_URL' => $this->defaultMailList->contact->url,
            'CONTACT_EMAIL' => $this->defaultMailList->contact->email,
            'LIST_NAME' => $this->defaultMailList->name,
            'LIST_SUBJECT' => $this->defaultMailList->default_subject,
            'LIST_FROM_NAME' => $this->defaultMailList->from_name,
            'LIST_FROM_EMAIL' => $this->defaultMailList->from_email,
            'WEB_VIEW_URL' => str_replace('MESSAGE_ID', StringHelper::base64UrlEncode($msgId), Setting::get('url_web_view')),
        );

        // UPDATE_PROFILE_URL
     /*   if(!$this->isStdClassSubscriber($subscriber)) {
            // in case of actually sending campaign
           $tags['UPDATE_PROFILE_URL'] = str_replace('LIST_UID', $this->defaultMailList->uid,
                str_replace('SUBSCRIBER_UID', $subscriber->uid,
                str_replace('SECURE_CODE', $subscriber->getSecurityToken('update-profile'), Setting::get('url_update_profile'))));
        }
    */

        // Update tags layout
        foreach ($tags as $tag => $value) {
            $message = str_replace('{'.$tag.'}', $value, $message);
        }

     /*   if (!$this->isStdClassSubscriber($subscriber)) {
            // in case of actually sending campaign
            foreach ($this->defaultMailList->fields as $field) {
                $message = str_replace('{SUBSCRIBER_'.$field->tag.'}', $subscriber->getValueByField($field), $message);
            }
        } else {
            // in case of sending test email
            // @todo how to manage such tags?
            $message = str_replace('{SUBSCRIBER_EMAIL}', $subscriber->email, $message);
        }
     */
        return $message;
    }


    /**
     * Subscribers.
     *
     * @return collect
     */
    public function subscribers($params = [], $extra = ['email_verifications'])
    {
        // FIXME NEED REWRITE
        // get subscribers from auto campaign or normal campaign
        if($this->isAuto()) {
            // Get subscriber from auto event
            $query = $this->autoEvent()->subscribers();
        } else {
            if($this->listsSegments->isEmpty()) {
                // this is a trick for returning an empty builder
                return Subscriber::limit(0);
            }

            $query = Subscriber::select("subscribers.*");

            // Tell MySQL to use the right index
            // $query->from(\DB::raw(\DB::getTablePrefix() . 'subscribers FORCE INDEX (subscribers_mail_list_id_email_index)'));

            // Email verification join
            if (in_array('email_verifications', $extra)) {
                $query = $query->leftJoin('email_verifications', 'email_verifications.subscriber_id', '=', 'subscribers.id');
            }
//            if (in_array('without_openers', $extra)) {
//                MailLog::info("Without openers value has been passed to subscribers()");
//            }

          //  MailLog::info("QUERY: ".$query);

            // Get subscriber from mailist and segment
            $conditions = [];
            foreach($this->listsSegments as $lists_segment) {
                if(!empty($lists_segment->segment_id)) {
                    $conds = $lists_segment->segment->getSubscribersConditions();

                    // JOINS...
                    if (!empty($conds['joins'])) {
                        foreach ($conds['joins'] as $joining) {
                            $query = $query->leftJoin($joining['table'], function($join) use ($joining)
                            {
                                $join->on($joining['ons'][0][0], '=', $joining['ons'][0][1]);
                                if (isset($joining['ons'][1])) {
                                    $join->on($joining['ons'][1][0], '=', $joining['ons'][1][1]);
                                }
                            });
                        }
                    }

                    // WHERE...
                    if (!empty($conds['conditions'])) {
                        $conditions[] = $conds['conditions'];
                    }

                } else {
                    $conditions[] = '(' . table('subscribers') . '.mail_list_id = ' . $lists_segment->mail_list_id . ')';
                }
            }

            if (!empty($conditions)) {
                $query = $query->whereRaw('(' . implode(' OR ', $conditions) . ')');
            }
        }

        // Filters
        $filters = isset($params["filters"]) ? $params["filters"] : null;

        if((isset($filters) && (isset($filters["open"]) || isset($filters["click"]) || isset($filters["tracking_status"])))
           || $this->status == "done"
        ) {
            $query = $query->leftJoin('tracking_logs', 'tracking_logs.subscriber_id', '=', 'subscribers.id');
            $query = $query->where('tracking_logs.id', "!=", "NULL");
            $query = $query->where('tracking_logs.campaign_id', "=", $this->id);
        }

        if(isset($filters)) {
            if(isset($filters["open"])) {
                $equal = ($filters["open"] == "opened") ? "!=" : "=";
                $query = $query->leftJoin('open_logs', 'tracking_logs.message_id', '=', 'open_logs.message_id')
                    ->where('open_logs.id', $equal, null);
            }
            if(isset($filters["click"])) {
                $equal = ($filters["click"] == "clicked") ? "!=" : "=";
                $query = $query->leftJoin('click_logs', 'tracking_logs.message_id', '=', 'click_logs.message_id')
                    ->where('click_logs.id', $equal, null);
            }
            if(isset($filters["tracking_status"])) {
                $val = ($filters["tracking_status"] == "not_sent") ? null : $filters["tracking_status"];
                $query = $query->where('tracking_logs.status', "=", $val);
            }
        }

        // keyword
        if (isset($params["keyword"]) && !empty(trim($params["keyword"]))) {
            foreach (explode(' ', trim($params["keyword"])) as $keyword) {
                $query = $query->leftJoin('subscriber_fields', 'subscribers.id', '=', 'subscriber_fields.subscriber_id');
                $query = $query->where(function ($q) use ($keyword) {
                    $q->orwhere('subscribers.email', 'like', '%'.$keyword.'%')
                        ->orWhere('subscriber_fields.value', 'like', '%'.$keyword.'%');
                });
            }
        }
        //MailLog::info("Subscribers function generated query: ".$query->toSql());
        return $query;
    }

    /**
     * Get Pending Subscribers
     * Select only subscribers that are ready for sending. Those whose status is `blacklisted`, `pending` or `unconfirmed` are not included.
     */
    public function getPendingSubscribers($partition = null, $callback)
    {

        // FIXME need overhaul
        /* *
         *
         * Using LEFT JOIN is much slower than NOT IN
         *
        $subscribers = $this->subscribers()
                ->leftJoin(DB::raw('(SELECT * FROM '.DB::getTablePrefix().'tracking_logs WHERE campaign_id = '.$this->id.') log'), 'subscribers.id', '=', 'subscriber_id')
                ->whereRaw('log.id IS NULL')
                ->whereRaw(DB::getTablePrefix()."subscribers.status = '".Subscriber::STATUS_SUBSCRIBED."'")
                ->select('subscribers.*')
                ->get();
        */

        $builder = $this->subscribers([], [])
                ->whereRaw(sprintf(table('subscribers').'.id NOT IN (SELECT subscriber_id FROM %s WHERE campaign_id = %s)', table('tracking_logs'), $this->id))
                ->whereRaw(DB::getTablePrefix()."subscribers.email NOT IN (select email from blacklists)")
                ->whereRaw(DB::getTablePrefix()."subscribers.status = '".Subscriber::STATUS_SUBSCRIBED."'");

        if (!is_null($partition)) {
            // retrieve the subscribers list for this partition only
            // partitioning is based on email to prevent one email from being taken by more than one process
            // The idea is to generate a unique integer for an email address, then partition base on the number
            list($partitionId, $count) = $partition;
            $builder = $builder->whereRaw(sprintf('CONV(SUBSTR(MD5(%s),1, 4), 16, 10) MOD %s = %s', table('subscribers.email'), $count, $partitionId));
        }

       // MailLog::info("getPendingSubscribers function generated query: SELECT FROM subscribers where id NOT IN (SELECT subscriber_id FROM ".table('tracking_logs')." WHERE campaign_id = ".$this->id.") and subscribers.status = ".Subscriber::STATUS_SUBSCRIBED);

        // the array_unique_by functions out-perform the Collection::unique of Laravel!!!
        // The following code snipet is no longer needed as the list is already unique by email
        //     $unique = array_unique_by($subscribers, function ($r) {
        //         return $r['email'];
        //     });

        $page = 1;
        $limit = 200;
        while ($total = $builder->count()) {
            // @note eager loading will result in a very long query
            // which could exceed the [max_allowed_packet] setting of MySQL
            // so the value of $limit must be considered carefully
            $subscribers = $builder->limit($limit)->with(['mailList', 'subscriberFields'])->get();
            $callback($subscribers, $page, $total);
            $page += 1;
        }

        /*
         * @todo the paginate helper does not work here since data table will be changed during the retrieving process
        paginate($subscribers, function ($subset, $page) use ($callback, $total) {
            $callback($subset, $page, $total);
        }, ['count' => $total]);
        */
    }

    /**
     * Queue campaign for sending
     *
     */
    public function queue($trigger = null, $subscribers = null, $delay = 0)
    {
        $this->ready();
        $job = (new \Acelle\Jobs\RunCampaignJob($this))->delay($this->getDelayInSeconds());
        dispatch($job);
    }

    /**
     * Queue campaign for sending
     *
     */
    public function clearAllJobs()
    {
        // cleanup jobs and system_jobs
        // @todo data should be a JSON field instead
        $systemJobs = SystemJob::where('name', 'Acelle\Jobs\RunCampaignJob')->where('data', $this->id)->get();
        foreach($systemJobs as $systemJob) {
            // @todo what if jobs were already started? check `reserved` field?
            $systemJob->clear();
        }
    }

    /**
     * Pick up a delivery server for the campaign.
     *
     * @return mixed
     */
    public function pickSendingServer()
    {
        MailLog::info("is campaign model");
        return $this->defaultMailList->pickSendingServer();
    }
}
