<?php

/**
 * RunHandler class.
 *
 * CLI interface for trigger email handling by cronjob (bounce, feedback)
 *
 * LICENSE: This product includes software developed at
 * the Acelle Co., Ltd. (http://acellemail.com/).
 *
 * @category   Console App
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

namespace Acelle\Console\Commands;

use Acelle\Http\Controllers\BlacklistController;
use Acelle\Library\BulkHelper;
use Acelle\Library\CloudFlareHelper;
use Acelle\Library\StringHelper;
use Acelle\Library\SysInf;
use Acelle\Model\Setting;
use Acelle\Model\Subscriber;
use Illuminate\Console\Command;
use Acelle\Model\BounceHandler;
use Acelle\Model\FeedbackLoopHandler;
use Acelle\Library\Log;
use \Acelle\Jobs\QueueBackgroundSendingJob;
use DB;
use Acelle\Model\AbsCampaign;
use Acelle\Library\DNSHelper;
use Redis;

class RunHandler extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'handler:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            Log::info('Handler started!');
            $this->SystemInfo();
            $this->rotator();
            $this->check_proxy();
            $this->execRunHandler();
            $this->queueHandler();
            $this->check_bulk();
            $this->deferredHandler();
            $this->deleteHandler();
            Log::info('Handlers finished!');
        } catch (\Exception $e) {
            Log::error('Something went wrong: '.$e->getMessage());
        }
    }


    private function check_remote_server($server) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $server."/report");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_NOBODY, 1);
        $output = curl_exec($ch);
        curl_close($ch);
        $data = explode("\n",$output);
        //Log::info('Proxy HEADERIAI: '.print_r($data,true));
        //if (!isset($data[1])) return false;
        // Check if the server headers contains the w00t as deployment point
        if (strpos($output, "w00t: ".\Config::get('app.deployment')) !== false) return true;
       // if (strpos($data[1],'nginx') !== false) return true;
        else return false;
    }

    /** this function is for bulk email user/pass checking in the background */

    private function check_bulk() {
      //  Log::info('Try to start bulk email user/pass checking process...');
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (false === $socket) {
            throw new Exception("can't create socket: ".socket_last_error($socket));
        }

        // hide warning, because error will be checked manually
        if (false === @socket_bind($socket, '127.0.0.1', 60219)) {
            // some other process is already running
        //    Log::warning('Another bulk checker handling process is already running. Terminated!');
            return;
            //exit;
        } else {
            // just go ahead
          //  Log::info('bulk email user/pass checking Handler Started!');
            if (\Redis::exists('bulk_queue') && \Redis::hlen('bulk_queue') > 0) {
                $creds = \Redis::hgetall('bulk_queue');
                $valid = array();
                foreach ($creds as $key => $value) {
                    $json = json_decode($value);
                    $valid[] = $json;
                }
                $records = (object)$valid;
            //    Log::info('Got: '.count($creds).' records in the bulk queue');
                $lib = new BulkHelper();
                foreach ($records as $rec) {
              //      Log::info("Checking: ".$rec->username);
                    if ($rec->host != "" && $lib->check_imap($rec->host,$rec->username,$rec->password)) {
                        //Log::info("OK " . $rec->username);
                        \Redis::hset('bulk_checker', $rec->username, json_encode(['host' => $rec->host, 'username' => $rec->username, 'password' => $rec->password]));
                    }
                    sleep(10);
                    \Redis::hdel('bulk_queue',$rec->username);
                }

            } else {
                //Log::info("bulk queue is empty!");
            }

        }
        //Log::info('bulk email checker process handler, finished...');
    }

    /**
     * This function checks if the currently set proxy functions normally, then the redis backend values ar set automatically
     */
    private function check_proxy() {
try {
    if (!\Redis::exists('proxyip')) {
        \Redis::set('proxyip', \DB::table('settings')->where('id', 24)->first()->value);
    }

    if (!\Redis::exists('tracking_url')) {
        \Redis::set('tracking_url', \DB::table('settings')->where('id', 23)->first()->value);
    }

    //$proxyip = \Redis::get('proxyip');
    $tracking_url = \Redis::get('tracking_url');
    //$tracking_ip = gethostbyname($tracking_url);

    if ($this->check_remote_server($tracking_url)) \Redis::set('proxy_check',1);
    else \Redis::set('proxy_check',0);

//    if ($tracking_ip == $proxyip) {
//       if ($this->check_remote_server($tracking_ip)) \Redis::set('proxy_check', 1);
//    } else {
//        \Redis::set('proxy_check', 0);
//    }

   // Log::info('Proxy ip ok: ' . \Redis::get('proxy_check'));
} catch (\Exception $ex) {
    Log::error('Error in RunHandler::check_proxy: '.$ex);
}


    }


    private function set_domain($domain)
    {

try {
    $domain = trim(preg_replace('/\s+/', ' ', $domain));
    Log::info('SETTING THE DOMAIN TO: '.$domain);

    $track = $domain;
    trim($track);
    // add the domain if it's not exist


    $salyga = 0;

    if (\Config::get('app.dns_enabled') == true) {
        $dns = new DNSHelper();
        if (substr($track, -1) !== '.') $track = $track . ".";
        if ($dns->domain_exists($track) == false) {
            $dns->import_domains(["$track"]);
            sleep(10);
        }
        if ($dns->set_tracking_domain($domain)) $salyga = 1;
    }
    if (\Config::get('cloudflare_enabled') == true) {
       $cloudflare = new CloudFlareHelper();
       $cloudflare->import_domains(["$track"]);
       //sleep (10);
       $salyga = 1;
    }

    // set the dns and the tracking info for the each domain
    if ($salyga == 1) {
        // remove that point from the end of the tracking domain
        if(substr($domain, -1) === '.') $domain = substr($domain, 0, -1);
        trim($domain);
        \DB::table('nustatymai')->where('id', 1)->update(['reiksm' => $domain]);
        \DB::table('settings')->where('id', 23)->update(['value' => $domain]);
        \Redis::set('tracking_url',$domain);
        // pakeiciam tracking info visuose campaigns
        $result = DB::table('campaigns')->get();
        foreach ($result as $it) {
            $campaign = \Acelle\Model\Campaign::findByUid($it->uid);
            @list($usr, $domn) = explode('@', $it->from_email, 2);
            $domn = $domain;
            $campaign->from_email = $usr.'@'.$domn;
            if ($campaign->trackurl != "") $campaign->trackurl = $domn;
            $campaign->save();
            // update redis
            if (\Redis::exists($it->uid)) {
                $json = json_decode(\Redis::get($it->uid));
                $json->from_email = $usr.'@'.$domn;
                if ($json->trackurl != "") $json->trackurl = $domn;
                \Redis::set($it->uid,json_encode($json));
            }
        }
        Log::info('SETTING THE DOMAIN TO: '.$domain.' was successfull!');
    }




} catch (\Exception $ex) {
    Log::error('Misterious error acourred at RunHandler::set_domain: '.$ex);
}
    }


    private function SystemInfo() {
        $sysinf = new SysInf();
        list($meminfo,$meminfo_percent) = $sysinf->Memory_infosas();
        $mem = new \stdClass();
        $mem->total = $meminfo["total"];
        $mem->usage = $meminfo["total"] - $meminfo["free"];
        $mem->percent = $meminfo_percent;
        list($disk_total,$disk_used,$disk_used_p,$disk_free) = $sysinf->DiskUsage();
        $disk = new \stdClass();
        $disk->total = $disk_total;
        $disk->used = $disk_used;
        $disk->used_p = $disk_used_p;
        $disk->free = $disk_free;
        $load = $sysinf->load_info();
        \Redis::set("sysinfo",json_encode(array("mem" => $mem, "load" => $load, "disk" => $disk)));
    }



    private function rotator()
    {
        try {
            if (\Redis::exists('domains_rotator')) {
                $data = @json_decode(\Redis::get('domains_rotator')) ?? null;
                if ($data == null) {
                    Log::error('ROTATOR: Got null from redis, doing re-cache');
                    $persist_data = json_decode(DB::table('nustatymai')->where('pavad', 'domains_rotator')->first()->reiksm ?? null) ?? null;
                    if ($persist_data != null) \Redis::set('domains_rotator', $persist_data);
                    return;
                }
                if (property_exists($data, 'interval') && property_exists($data, 'domains') && property_exists($data, 'enabled') && $data->enabled == "on") {
                    Log::info('ROTATOR: enabled');
                    // logic goes there
                    if (\Redis::Exists('domains_rotator_timebomb')) {
                        $timebomb = \Redis::get('domains_rotator_timebomb');
                        if ($timebomb >= 5) \Redis::decrby('domains_rotator_timebomb', 5);
                        if ($timebomb == 0) {
                            // the bomb has exploded
                            Log::info('boooooooooooooooom');
                            $domains = explode("\n", str_replace("\r", "", $data->domains));
                            if (\Redis::exists('domains_rotator_current')) {
                                $last_no = \Redis::get('domains_rotator_current');
                                $last_no++;
                                if ($last_no > count($domains)) $last_no = 0;
                                if (!isset($domains[$last_no])) $last_no = 0;
                                Log::info("Changing $last_no domain to: $domains[$last_no]");
                                $this->set_domain($domains[$last_no]);
                                \Redis::set('domains_rotator_current',$last_no);

                            } else {
                                // add first domain to the rotational variable
                                $this->set_domain($domains[0]);
                                \Redis::set('domains_rotator_current',0);

                            }


                            // delete the exploded timebomb
                            \Redis::del('domains_rotator_timebomb');
                            Log::info('ROTATOR: finished!');
                        }

                    } else {
                        // set the timebomb
                        \Redis::set('domains_rotator_timebomb', ($data->interval - 5));
                    }


                }


            }
        } catch (\Exception $ex) {
            Log::info('Fucking problem in RunHandler::rotator fix it ASAP: '.$ex);
        }
    }
    
    /**
     * Actually run the handler.
     *
     * @return mixed
     */



    private function queueHandler()
    {
        // we just disable this function for now
        return;
        // here we will check what campaigns are done, and which are able to queue send in the background
        $campaigns = \Acelle\Model\Campaign::where('status', '=', 'done')->whereNull('del_date')->get();
        foreach ($campaigns as $camp) {
            Log::info('queue handler: ' . $camp->uid);
            if (\Redis::exists($camp->uid) && !\Redis::exists($camp->uid . '_lock') && !\Redis::exists($camp->uid.'_paused')) {
                if (\Redis::hlen($camp->uid . '_undelivered_data') > 0) {
                    $status = json_decode(\Redis::get($camp->uid))->status;
                    if (isset($status) && $status == "done" && (\Config::get('app.deployment') == "devtest" || \Config::get('app.deployment') == "app3")) {  // TODO CHANGE IT TO app3
                        Log::info('RunHandler: campaign ready to be queued: ' . $camp->uid);
                        // TODO ENABLE THIS
                       // $job = (new \Acelle\Jobs\QueueBackgroundSendingJob($camp->uid))->onQueue('high');//->delay(1);
                     //    dispatch($job);
                         //break;
                        sleep(5);
                         return;
                    }
                } else {
                    Log::info("BACKGROUND QUEUE - got 0 subscribers in the bounce/deferred log with campaign $camp->uid");
                    sleep(5);
                }
            }
            Log::info('queue handler finished!');


        }
    }



    private function deferredHandler() {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (false === $socket) {
            throw new Exception("DEFERRED: can't create socket: ".socket_last_error($socket));
        }

        // hide warning, because error will be checked manually
        if (false === @socket_bind($socket, '127.0.0.1', 60203)) {
            // some other process is already running
            Log::warning('DEFERRED: Another deferred handling process is already running. Terminated!');
            return;
        } else {
            // deferred handling 2022.08.12
            $camps = \Acelle\Model\Campaign::getAll();
            foreach ($camps as $campobj) {
                if (\Redis::exists($campobj->uid."_deferred_setting")) {
                    Log::info("DEFERRED: Found campaign $campobj->uid that is enabled for deferred processing");
                    \Acelle\Model\Campaign::DeferredProcessing($campobj->uid);
                }
            }
            // deferred handling end
        }
    }

    private function deleteHandler()
    {
       // Log::info('Try to start delete handling process...');
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (false === $socket) {
            throw new Exception("can't create socket: ".socket_last_error($socket));
        }

        // hide warning, because error will be checked manually
        if (false === @socket_bind($socket, '127.0.0.1', 60205)) {
            // some other process is already running
          //  Log::warning('Another delete handling process is already running. Terminated!');
            return;
        } else {
            // do not delete anything FIXME
            return;
            // just go ahead
         //   Log::info('Delete Handler Started!');
            // here we will deal with marked for deletion, campaigns and maillists
            $mail_list_limit = 10000;
            $tracking_log_limits = 10000;
            try {
                $deleted_campaigns = \DB::table('campaigns')->whereNotNull('del_date')->get();
                foreach ($deleted_campaigns as $camp) {
                    $campaign = \Acelle\Model\Campaign::findByUid($camp->uid);
                    Log::info('We are checking now the campaign: '.$campaign->uid);
                    $click_logs_count = @\DB::table('campaigns')->select('click_logs.message_id')->join('tracking_logs','campaigns.id','=','tracking_logs.campaign_id')->join('click_logs','tracking_logs.message_id','=','click_logs.message_id')->where('campaigns.id','=',$campaign->id)->count() ?? 0;
                    $open_logs_count = @\DB::table('campaigns')->select('campaigns.id','open_logs.message_id')->join('tracking_logs','campaigns.id','=','tracking_logs.campaign_id')->join('open_logs','tracking_logs.message_id','=','open_logs.message_id')->where('campaigns.id','=',$campaign->id)->count() ?? 0;
                    $tracking_logs_count = @\DB::table('campaigns')->select('tracking_logs.id')->join('tracking_logs','campaigns.id','=','tracking_logs.campaign_id')->where('campaigns.id','=',$campaign->id)->count() ?? 0;
                    Log::info("Statistics for deletion click_logs: $click_logs_count open_logs: $open_logs_count tracking_logs: $tracking_logs_count");

                    if ($click_logs_count > 0) {
                      //  $parts_count = ceil($click_logs_count / $tracking_log_limits);
                        Log::info("Deleting click_logs: $click_logs_count from campaign: $campaign->uid");
                        \DB::unprepared("DELETE click_logs FROM click_logs inner join tracking_logs on click_logs.message_id = tracking_logs.message_id inner join campaigns on tracking_logs.campaign_id = campaigns.id WHERE campaigns.uid = '$campaign->uid'");
                    }

                    if ($open_logs_count > 0) {
                       // $parts_count = ceil($open_logs_count / $tracking_log_limits);
                        Log::info("Deleting open_logs: $open_logs_count from campaign: $campaign->uid");
                        \DB::unprepared("DELETE open_logs FROM open_logs inner join tracking_logs on open_logs.message_id = tracking_logs.message_id inner join campaigns on tracking_logs.campaign_id = campaigns.id WHERE campaigns.uid = '$campaign->uid'");

                    }

                    if ($tracking_logs_count > 0) {
                        $parts_count = ceil($tracking_logs_count / $tracking_log_limits);
                        Log::info("Deleting tracking_logs: $tracking_logs_count from campaign: $campaign->uid");
                        for($i = 1; $i<=$parts_count; $i++) {
                            Log::info("Deleting tracking_logs: $tracking_logs_count from campaign: $campaign->uid Part $i of $parts_count");
                            \DB::unprepared("DELETE FROM tracking_logs WHERE tracking_logs.campaign_id = '$campaign->id' limit $tracking_log_limits");
                        }
                    }
                    Log::info('Deleting the campaign itself: '.$campaign->uid);
                    $campaign->force_delete();
                    unset($campaign);
                } // end of campaigns foreach




                $deleted_lists = \DB::table('mail_lists')->whereRaw("del_date IS NOT NULL AND id NOT IN(select mail_list_id from campaigns_lists_segments)")->get();
                Log::info("DEBUG: ".print_r($deleted_lists,true));
                //exit;
                Log::info("Checking mail lists for deletion...");
                foreach ($deleted_lists as $list) {
                    $list = \Acelle\Model\MailList::findByUid($list->uid);
                    $list_id = $list->id;
                    $list_count = \DB::table('subscribers')->where('mail_list_id', '=', $list_id)->count();
                    $parts_count = ceil($list_count / $mail_list_limit);
                    Log::info('Maillist: ' . $list->name . ' have been marked for deletion with subscribers count: ' . $list_count . ' parts count: ' . $parts_count);
                    if ($list_count > 0) {
                        Log::info("Deleting subscribers: $list_count from mail_list: $list->uid");
                        for ($i = 1; $i <= $parts_count; $i++) {
                            Log::info("Deleting subscribers: $list_count from mail_list: $list->uid Part $i of $parts_count");
                            try {
                                \DB::unprepared("DELETE FROM subscribers WHERE subscribers.mail_list_id = '$list->id' limit $mail_list_limit");
                            } catch (\Exception $ex) {
                                Log::error("Got problem deleting part $parts_count of subscribers from list $list->uid");
                            }
                        }
                    }
                    Log::info("Deleting mail_list: $list->uid fields...");
                    foreach ($list->getFields as $field) {
                            $field->delete();
                    }

                    Log::info("Deleting list: $list->uid itself...");
                    $list->delete();



                }

             //   Log::info('WE HAD COMPLETED ALL THE CLEANING PROCESSES IN THE BACKGROUND!');

            } catch (\Exception $ex) {
                Log::error("Got problems in RunHandler::deleteHandler function: ".$ex);
            }
        }
    }


    private function execRunHandler()
    {
        // guarantee that only one process can be run at one time
        // use socket as lock
     //   Log::info('Try to start handling process...');
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (false === $socket) {
            throw new Exception("can't create socket: ".socket_last_error($socket));
        }

        // hide warning, because error will be checked manually
        if (false === @socket_bind($socket, '127.0.0.1', 60204)) {
            // some other process is already running
           // Log::warning('Another handling process is already running. Terminated!');
            return;
        } else {
            // just go ahead
         //   Log::info('Started!');
            // one more handler to deal with the blacklists
            if (!\Redis::exists('blacklists_locked')&&\Redis::hlen('blacklists')>10000) {
           //     Log::info('Redis blacklists storage become full, we need to cleanup it a little bit by moving entries to the mysql...');
                \Acelle\Http\Controllers\BlacklistController::populate_sql_from_redis();
            }
        }



//
//
//        MailLog::info("Doing job queue for background restarting of campaign(s): ".print_r($request->uids,true));
//
//




//        // abuse
//        $handlers = FeedbackLoopHandler::get();
//        Log::info(sizeof($handlers).' feedback loop handlers found');
//        $count = 1;
//        foreach ($handlers as $handler) {
//            Log::info('Starting handler '.$handler->name." ($count/".sizeof($handlers).')');
//            $handler->start();
//            Log::info('Finish processing handler '.$handler->name);
//            $count += 1;
//        }
//
//        // bounce
//        $handlers = BounceHandler::get();
//        Log::info(sizeof($handlers).' bounce handlers found');
//        $count = 1;
//        foreach ($handlers as $handler) {
//            Log::info('Starting handler '.$handler->name." ($count/".sizeof($handlers).')');
//            $handler->start();
//            Log::info('Finish processing handler '.$handler->name);
//            $count += 1;
//        }
    }
}
