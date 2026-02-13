<?php

namespace Acelle\Http\Controllers\Api;

use Acelle\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Acelle\Library\Log as MailLog;
use Illuminate\Support\Facades\Log as LaravelLog;
use DB;
use Redis;
use Acelle\Library\CloudFlareHelper;
use Acelle\Model\Setting;
use Acelle\Library\StringHelper;
use Acelle\Library\TaskRunner;

class ApiController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        parent::__construct();
//MailLog::info("Campaign controller");
        $this->middleware('api_auth', ['except' => [
            'getapi', 'hardbounce', 'failcampaign', 'postserver','initialize_dns','updatespf','uninitialize_dns','conversion','getexternaltracking','checkifserverexists','getip'
        ]]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
      exit;
    }

    public function getip(Request $request) {
    $location = \Acelle\Model\IpLocation::add($request->ip);
    return json_encode($location);
    }

    public function list_domains(Request $request) {
        if ($request->isMethod('post') && $request->api_key == "1122") {
            $cloudflare = new CloudFlareHelper();
            $doms = $cloudflare->get_domains();
            //$domains = ['domains'];
            foreach ($doms as $domain) {
                $domains[] = [ "name" => $domain->name ];
            }
            return json_encode($domains);
        }
    }


    public function conversion(Request $request) {
        $val = $request->val;
        $currency = $request->currency;
        $datetime = $request->datetime;
        $subid1 = $request->subid1;
        $subid2 = $request->subid2;
        MailLog::info("Got conversion from API: $val $currency $datetime $subid1 $subid2");
        try {
            \DB::insert("INSERT INTO campaigns_conversions (campaign_uid,subscriber_uid,currency,val,datetime) VALUES('$subid2','$subid1','$currency','$val','$datetime')");
        } catch (\Exception $ex) {
            MailLog::info("Failed to insert conversion log to database!");
        }

    }

    public function SimulationTestCampaign(Request $request) {
        if ($request->isMethod('post') && $request->api_key = "1122") {
            $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
            $sending = $campaign->CampaignSimulationSend($request->email);
            return json_encode($sending);
        }
    }


    public function checkifserverexists(Request $request) {
        if ($request->isMethod('post') && $request->api_key == "1122"&&!empty($request->servers)) {
            $deployment = \Config::get('app.deployment');
            try {
                $servers = json_decode($request->servers, true);
                foreach ($servers as &$serv) {
                    $server = \Acelle\Model\SendingServer::getAll()->where('host', $serv["host"])->get();
                    if (count($server) > 0) {
                        //MailLog::info("Found server: ".$serv["host"]." type: ".$serv["type"]." on this deployment!");
                        $serv["deployments"][] = $deployment;
                    }
                }
                return json_encode($servers);

            } catch (Exception $ex) {
                return ($request->servers);
            }
        }
    }



    public function counter(Request $request)
    {
        header('Content-Type: application/json');
        $cnt = Redis::get($request->uid."_counter");
        $pause = 0;
        if (Redis::exists($request->uid."_paused")) $pause = 1;

        if (is_null($cnt)) $cnt = 0;
        $counter = json_encode(array('counteris' => $cnt, 'pause' => $pause));
        return $counter;
    }

    public function getapi(Request $request)
    {
        MailLog::info("Got get method from api");
        MailLog::info("data: ".print_r($request->all(),true));
        if ($request->isMethod('post')) {
            MailLog::info("Got post method from api");
            MailLog::info("Request data: ".print_r($request->getContent(),true));
            MailLog::info("Data: ".print_r($request->all(),true));

        }

        print "OK";

    }

    // adds server to the list
    public function postserver(Request $request) {

        if ($request->isMethod('post') && $request->api_key == "1122") {

            if (is_numeric(substr($request->server_name, 0, 1))) {
                  $server_name = $request->server_name;
            } else {
                try {
                    $result = DB::table('sending_servers')
                        ->select(DB::raw('max(id) as max'))
                        ->first()
                        ->max;
                $server_name = $result . " ". $request->server_name;
                } catch (\Exception $ex) {
                    MailLog::error("Got error on post server when selecting max id for server name prefix $ex");
                    $server_name = $request->server_name;
                }
            }


            $server_host = $request->server_host;
            $server_user = $request->server_user;
            $server_pass = $request->server_pass;
            $server_port = $request->server_port;

            if ($request->sendaddress != "") {
                \Redis::set("server_".$server_host."_sendaddress",$request->sendaddress);
                // add spf
                $send_domain = @explode('@',$request->sendaddress)[1];
                if (\Config::get('app.cloudflare_enabled') == true) {
                    $cloudflare = new CloudFlareHelper();
                    try {
                        if ($send_domain != "") {
                            // set tracking address domain spf records
                            MailLog::info("Send address domain detected: ".$send_domain);
                            $cloudflare->delete_spf_records($send_domain);
                            $cloudflare->add_spf_records($send_domain);
                        }
                    } catch (\Exception $ex) {
                        MailLog::info("We got problems when setting send address for injected domain $send_domain: ".$ex);
                    }
                }
            }
            if ($request->tracking != "") {
                \Redis::set("server_".$server_host."_tracking",$request->tracking);
                // setup proxy
                if (\Redis::exists("proxy_wide") && \Redis::get("proxy_wide") == "1" && \Redis::exists("proxy_default") && \Redis::get("proxy_default") != "") {
                    $proxy_ip = \Redis::get("proxy_default");
                    if (\Config::get('app.cloudflare_enabled') == true) {
                        $cloudflare = new CloudFlareHelper();
                        $cloudflare->manually_set($request->tracking);
                    }
                }
            }

            $server = new \Acelle\Model\SendingServer();
            $server->type = 'smtp';
            $server->status = 'active';
            $server->name = $server_name;
            $server->host = $server_host;
            $server->smtp_username = $server_user;
            $server->smtp_password = $server_pass;
            if ($server_port == 22) $server->smtp_port = 2525;
            else
            $server->smtp_port = $server_port;


            $server->customer_id = 1; // ugly hack
            $server->bounce_handler_id = null;
            $server->feedback_loop_handler_id = null;
            $server->save();

            $objson = (object) array();
            $objson->status = 1;
            $objson->msg = "success";
            return json_encode($objson);

                MailLog::info("got server post from api");
        }


    }

    public function updatespf(Request $request) {
        if ($request->isMethod('post') && $request->api_key == "1122") {
            $domain = $request->domain;
            $cloudflare = new CloudFlareHelper();
            if (!empty($domain)) {
                $cloudflare->add_spf_records($domain);
            }
        }

    }

    public function replace_server(Request $request) {
        if ($request->isMethod('post') && $request->api_key == "1122"&&!empty($request->host)&&!empty($request->oldhost)) {
            $server = \Acelle\Model\SendingServer::getAll()->where('host',$request->host)->get();
            if (is_object($server)) {
                \DB::unprepared("UPDATE sending_servers SET host = '$request->host' where host = '$request->oldhost'");
                $objson = (object) array();
                $objson->status = 1;
                return json_encode($objson);
            }

        }
    }

    public function delete_server(Request $request) {
        if ($request->isMethod('post') && $request->api_key == "1122"&&!empty($request->host)) {
            $server = \Acelle\Model\SendingServer::getAll()->where('host',$request->host)->get();
            //MailLog::info("SERVAS: ".print_r($server,true));
            if (is_object($server)) {
                \DB::unprepared("DELETE FROM sending_servers where host = '$request->host'");
                $objson = (object) array();
                $objson->status = 1;
                return json_encode($objson);
            }

        }

    }


    private function generateRandomString($length = 10) {
        return substr(str_shuffle(str_repeat($x='bcdefghijklmnopqrstuvwxyz', ceil($length/strlen($x)) )),1,$length);
    }

    private function generateRandomId($length = 10) {
        return substr(str_shuffle(str_repeat($x='0123456789', ceil($length/strlen($x)) )),1,$length);
    }

    public function uninitialize_dns(Request $request) {
        if ($request->isMethod('post') && $request->api_key == "1122") {
            if (!empty($request->domain)&&!empty($request->dns)) {
                $cloudflare = new CloudFlareHelper();
                $cloudflare->delete_records($request->domain,$request->dns,'A');
                MailLog::info("Deleting dns record $request->dns from domain $request->domain");

            }
        }
    }

    // initializes the dns on the specified domain with an specified ip (required by the digital ocean addon)
    public function initialize_dns(Request $request) {
        if ($request->isMethod('post') && $request->api_key == "1122") {
            $rand_dns_name = $this->generateRandomString(2);
            $rand_dns_id = $this->generateRandomId(2);
            $random_dns = $rand_dns_name.$rand_dns_id;
            MailLog::info("ApiController::initialize_dns domain: $request->domain ip: $request->ip with random dns: $random_dns.$request->domain");
            $json = (object) array();

            if (!empty($request->domain)&&!empty($request->ip)) {
/*                $cloudflare = new CloudFlareHelper();
                // get the domain id
                $doms = $cloudflare->get_domains($request->domain);
                $id = null;
                foreach ($doms as $dom) {
                    $id = $dom->id;
                }

                if ($cloudflare->createDNSRecord($id,array('type'=>'A','name' => $random_dns, 'content' => $request->ip, 'proxied' => false))) {
                    $json->subdomain = $random_dns.'.'.$request->domain;
                    $json->respond = "We have just finished setting up the sub-domain $random_dns.$request->domain!";
                    $json->status = 1;
                } else {
                    $json->error = "Unable to set the subdomain $random_dns.$request->domain in cloudflare!";
                    $json->respond = "Unable to set the subdomain $random_dns.$request->domain in cloudflare!";
                    $json->status = 0;
                }
*/

	   // fix bet kokiu atveju kad veiktu
           try {
           $cloudflare = new CloudFlareHelper();
	   $doms = $cloudflare->get_domains($request->domain);
           $id = null;
                foreach ($doms as $dom) {
                    $id = $dom->id;
                }
           $cloudflare->createDNSRecord($id,array('type'=>'A','name' => $random_dns, 'content' => $request->ip, 'proxied' => false));
           } catch (\Exception $ex) {
              MailLog::error("error in ApiController::initialize_dns, maybe the domain have'nt been added to cloudflare");
           }
           $json->subdomain = $random_dns.'.'.$request->domain;
           $json->respond = "We have just finished setting up the sub-domain $random_dns.$request->domain!";
           $json->status = 1;
           } else {
                $json->error = 'Bad passed parameters!';
                $json->respond = 'Bad passed parameters!';
                $json->status = 0;
            }

            return json_encode($json);

        }

    }


    public function test_delivery(Request $request) {
        if ($request->isMethod('post')&&$request->api_key == "1122") {
            $subject = "test mail";
            $text = "Just a test email";
            $to_email = $request->email;
            $server = (object)json_decode($request->serv);
            $randid = rand(33334234,2343245890435345);
            $html = "<html><content>$text</content></html>";
            $deployment = \Config::get('app.deployment');
            $global_tracking = Setting::get('global_tracking');
            $from_email = 'info@'.$global_tracking;

            $taskrunner = New TaskRunner();
            $customer = $request->user()->customer->id;
            $camp_uid = \Config::get('app.default_mail_header');
            $headers = array($camp_uid => "$randid [$deployment]");
            $send_data = array('server_ip' => $server->hostname, 'port' => (int)2525, 'from_email' => $from_email,'to_email' => $to_email, 'subject' => $subject, 'body' => $html, 'headers' => $headers);
            $smtp_request = json_encode($send_data);
            $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_SMTP_SEND,$customer,$taskrunner::PRIORITY_HIGH,$smtp_request);
            MailLog::info('Sending test mail via: ' . $server->hostname);
            MailLog::info('Sleeping well for a second...');
            sleep(4);
            MailLog::info("Gathering information from the server...");
            exec('$HOME/public_html/tools/check_server_deliverability --host '.$server->hostname.' --user '.$server->user.' --pass '.$server->password.' --port '.$server->port.' --uid '.$randid.' 2>&1',$retArr, $retVal);
            foreach ($retArr as $output) {
                if (strpos($output,"JSON: ") > -1) {
                    $output =  preg_replace('/JSON: /','',$output);
                    MailLog::info("Deliverability script returned: $output");
                    $json = @json_decode($output);
                    return $output;
                }

            }

            MailLog::info('sito teksto neturetu matytis');


            $resp = (object) array();
            $resp->status = 0;
            $resp->respond = "Something go wrong and very seriously...";
            $resp->msg = "very bad day";
            return json_encode($resp);

        }

    }

    public function hardbounce(Request $request) {
        if (isset($request->email) && $request->email != "") {
         /*   $blacklist = new \Acelle\Model\Blacklist();
            $blacklist->fill($request->all());
            $blacklist->email = $request->email;
            $blacklist->reason = "Catched in parser";
            $blacklist->customer_id = 1;
            $blacklist->save(); */
         // new, fast implementatin using raw unprepared injection
            $sql = "INSERT IGNORE INTO blacklists (email,created_at,updated_at,reason,customer_id,external) VALUES('$request->email', now(), now(), 'Catched in parser', 1,1)";
            \DB::unprepared($sql);
          //  MailLog::info("API Hardbounce: $request->email to the blacklist!");
        }
    }


    public function blacklist(Request $request) {
        if ($request->isMethod('post') && $request->api_key == 1122 && !empty($request->email)) {
            MailLog::info("Got new blacklist item from api: ".$request->email." status: ".$request->status);

            $email = trim(strtolower($request->email));
            $pieces = explode("@", $email);
            if (\Acelle\Library\Tool::isValidEmail($email)) {
                // it is an email
                try {
                    $sql = "INSERT IGNORE INTO blacklists (email,created_at,updated_at,reason,customer_id) VALUES('$email', now(), now(), '$request->status', 1)";
                    \DB::unprepared($sql);
                } catch (\Exception $ex) {
                    MailLog::error("Problem when adding email to blacklist from api, maybe record $request->email already exists...");
                } finally {
                    if ($request->fast == 1 && !Redis::hexists('blacklists_fast', $request->email)) {
                        Redis::hset('blacklists_fast', $request->email, json_encode(['status' => $request->status]));
                    }
                }
            } elseif (isset($pieces[0])&&isset($pieces[1]) && strlen($pieces[0]) == 0 && strlen($pieces[1]) >0) {
                // only domains
                try {
                    $sql = "INSERT IGNORE INTO blacklist_domains (domain,created_at,updated_at,reason,customer_id) VALUES('$pieces[1]', now(), now(), '$request->status', 1)";
                    \DB::unprepared($sql);
                } catch (\Exception $ex) {
                 MailLog::error("Unable to add domain from blacklist API, value given: $request->email error: ".$ex);
                }
            } elseif (isset($pieces[0])&&isset($pieces[1]) && strlen($pieces[1]) == 0 && strlen($pieces[0]) >0) {
                // only names
                try {
                    $sql = "INSERT IGNORE INTO blacklist_names (name,created_at,updated_at,reason,customer_id) VALUES('$pieces[0]', now(), now(), '$request->status', 1)";
                    \DB::unprepared($sql);
                } catch (\Exception $ex) {
                    MailLog::error("Unable to add domain from blacklist API, value given: $request->email error: ".$ex);
                }
            } elseif (substr($email, 0, 1) === '+') {
                // phone number, we wont do anything to it
            }
        }
    }

    public function failcampaign(Request $request) {
        //select campaigns.uid from subscribers inner join campaigns_lists_segments on subscribers.mail_list_id = campaigns_lists_segments.mail_list_id inner join campaigns on campaigns_lists_segments.campaign_id = campaigns.id where subscribers.email = ?"
      $camps_running =  \DB::table('subscribers')
          ->join('campaigns_lists_segments', 'subscribers.mail_list_id', '=', 'campaigns_lists_segments.mail_list_id')
          ->join('campaigns', 'campaigns_lists_segments.campaign_id', '=', 'campaigns.id')
          ->where('subscribers.email',$request->email)->get();
      MailLog::info("API Got mail $request->email for campaign suspension...");
      if (count($camps_running) > 0) {
          foreach ($camps_running as $camp) {
              if (Redis::exists($camp->uid) && !Redis::exists($camp->uid.'_paused')) {
                  // check campaign status if it's sending or not
                  $status = json_decode(Redis::get($camp->uid))->status;
                  if (isset($status) && $status == "sending") {
                      MailLog::info("API Suspending Campaign $camp->uid because it have email: $request->email");
                      Redis::set($camp->uid . '_paused', 1);
                  } else {
                      MailLog::info("API It seems that campaign: $camp->uid are new or it have been done already ($request->email) !");
                  }
              } else {
                  MailLog::info("API Campaign $camp->uid does not set on the redis backend or already paused, skipping...");
              }
          }
      } else {
          MailLog::info("API No campaign with email: $request->email found for suspension !");
      }
      //insert(['email' => $request->email,'reason' => $reason,'admin_id' => 1, 'customer_id' => 1,'created_at' => \date('Y-m-d G:i:s'),'updated_at' => \date('Y-m-d G:i:s')]);

    }
    // new api to the external links
    public function getexternaltracking(Request $request) {
        if ($request->isMethod('post')) {
            $logger = MailLog::create(storage_path('logs/external.log'));
                $data = \json_decode(file_get_contents('php://input'));
                if (is_object($data) && $data->just_key == "1122") {
                    if ($data->test == 1) {
                        $logger->info("Test external tracking link: " . print_r($data, true));
                    } else {
                        // classification by tracking type
                        switch ($data->link_type) {
                            case 0:
                                // open
                                $logger->info("The tracking log is open click");
                                $log = new \Acelle\Model\OpenLog();
                                $log->message_id = $data->message_id;
                                $log->ip_address = $data->ip;
                                $log->user_agent = $data->useragent;
                                $already_exists = 0;
                                $old_track = \Acelle\Model\OpenLog::where('message_id', $log->message_id)->first();
                                if (is_object($old_track)) {
                                    $already_exists = 1;
                                    $logger->warning('Open is not unique because message_id: '.$log->message_id.' for that open is already registered in open_logs');
                                } else {
                                    $logger->info("Open is unique because we don't have $log->message_id in open_logs yet!");
                                }
                                try {
                                    $log->save();
                                } catch (\Exception $ex) {
                                    $logger->error("Unable to save click tracking log for message_id: ".$data->message_id);
                                }
                                if ($already_exists == 0) Redis::incr($data->campaign_uid.'_openers');
                                $db_user = "ses_remote";
                                $db_host = "78.46.73.84";
                                $db_db = "trackingas";
                                $db_pass = "bGh9CaF897q";
                                $db = new \mysqli($db_host, $db_user, $db_pass, $db_db);
                                $reverse = "";
                                if (strpos($data->server, '.') !== false) {
                                    $reverse = @gethostbyaddr($data->server);
                                }
                                $depl = \Config::get('app.deployment');
                                try {
                                    $db->query("INSERT IGNORE INTO app_openai (email,ip_address,server_ip,server_ptr,user_agent,location, deployment, domain,campaign,maillist) VALUES('$data->email','$data->ip','$data->server','$reverse','$data->useragent','$data->location','$depl','$data->trackdomain','$data->campaign_uid','$data->mail_list_name')");
                                } catch (\Exception $ex) {
                                    $logger->error("Unable to transfer click log to trackingas: ".$data->message_id);
                                }
                                break;
                            case 1:
                                // click
                                $logger->info("The tracking log is link click");
                                $log = new \Acelle\Model\ClickLog();
                                $log->message_id = $data->message_id;
                                $log->ip_address = $data->ip;
                                $log->user_agent = $data->useragent;
                                $log->url = $data->url;
                                $logger->info("Link click detected on message id: ".$log->message_id. " ip: ".$log->ip_address." location: ".$data->location." url: ".$log->url);
                                $already_exists = 0;
                                $old_track = \Acelle\Model\ClickLog::where('message_id', $log->message_id)->first();
                                if (is_object($old_track)) {
                                    $already_exists = 1;
                                    $logger->warning('click is not unique because message_id: '.$log->message_id.' for that click is already registered in click_logs');
                                } else {
                                    $logger->info("click is unique because we don't have $log->message_id in click_logs yet!");
                                }
                                if ($already_exists == 0) {
                                    Redis::incr($data->campaign_uid . '_clickers');
                                }
                                try {
                                    $log->save();
                                } catch (\Exception $ex) {
                                    $logger->error("Unable to save tracking log with message id: ".$data->message_id);
                                }
                                $db_user = "ses_remote";
                                $db_host = "78.46.73.84";
                                $db_db = "trackingas";
                                $db_pass = "bGh9CaF897q";
                                $db = new \mysqli($db_host, $db_user, $db_pass, $db_db);
                                try {
                                    $db->query("INSERT IGNORE INTO app_clickai (email,ip_address,user_agent,location,campaign) VALUES('$data->email','$data->ip','$data->useragent','$data->location','$data->campaign_name')");
                                } catch (\Exception $ex) {
                                    $logger->error("Unable to transfer click log to the trackingas");
                                }
                                break;
                            case 2:
                                // unsubscribe
                                $logger->info("External unsubscribe url is clicked, msgid: " . $data->message_id);
                                $tracking_log = \Acelle\Model\TrackingLog::where('message_id', '=', $data->message_id)->first();
                                $location = \Acelle\Model\IpLocation::add($data->ip);
                                if (is_object($tracking_log)) {
                                    $subscriber = $tracking_log->subscriber;
                                    if ($subscriber->status != 'unsubscribed') {
                                        $subscriber->status = 'unsubscribed';
                                        $subscriber->save();
                                        $log = new \Acelle\Model\UnsubscribeLog();
                                        $log->message_id = $data->message_id;
                                        $log->ip_address = $location->ip_address;
                                        $log->user_agent = $data->useragent;
                                        $log->save();
                                    }

                                }
                                break;
                        }
                    } // test else
                }

        }
    }


}
