<?php

namespace Acelle\Http\Controllers\Admin;

use Acelle\Library\CloudFlareHelper;
use Acelle\Library\StorageHelper;
use Acelle\Library\TaskRunner;
use Acelle\Model\MailList;
use Acelle\Model\Subscriber;
use Illuminate\Http\Request;
use Acelle\Http\Controllers\Controller;
use Acelle\Library\UpgradeManager;
use Illuminate\Support\Facades\Log;
use Acelle\Library\Log as MailLog;
use DB;
use Illuminate\Support\Facades\Log as LaravelLog;
use function PMA\Util\get;
use Redis;
use \Acelle\Jobs\UpdateCachesJob;
use Acelle\Library\DNSHelper;
//use \Acelle\Jobs\RestartProcessesJob;
use Acelle\Model\Campaign;
use Acelle\Model\Setting;
use Acelle\Model\Nustatymai;
use Acelle\Model\Warmups;
use Acelle\Model\WarmupsPhrases;
use Acelle\Library\StringHelper;
use Acelle\Library\IpLocator;


class SettingController extends Controller
{

    const SNS_TOPIC = 'ESPHANDLER';
    const SNS_TYPE = 'amazon'; // @TODO

    public $notificationTypes = array('Bounce', 'Complaint');
    public static $snsClient = null;
    public static $sesClient = null;
    public static $isSnsSetup = false;
    public $aws_access_key_id = '';
    public $aws_secret_access_key = '';
    public $aws_region = 'us-west-2';

    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        parent::__construct();

        $this->middleware('auth', ['except' => [
        'initialize_dns'
    ]]);
    }

    /**
     * Display and update all settings.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
//        if ($request->user()->admin->getPermission('setting_general') == 'yes') {
//            return redirect()->action('Admin\SettingController@general');
        //} elseif ($request->user()->admin->getPermission('setting_sending') == 'yes') {
        //   return redirect()->action('Admin\SettingController@sending');
       // } else
            if ($request->user()->admin->getPermission('setting_system_urls') == 'yes') {
            return redirect()->action('Admin\SettingController@urls');
        } elseif ($request->user()->admin->getPermission('setting_background_job') == 'yes') {
           return redirect()->action('Admin\SettingController@cronjob');
        }

    }



   public function proxies(Request $request) {
        $proxies = array();
        $failas = "/etc/nginx/real_ip.conf";
        $proxy_is_enabled = 0;
        $proxy_ip = "";
        if (\Redis::exists("proxy_wide") && \Redis::get("proxy_wide") == "1") {
        $proxy_is_enabled = 1;
        }
        if (\Redis::exists("proxy_default") && \Redis::get("proxy_default") != "") {
        $proxy_ip = \Redis::get("proxy_default");
        }
        try {
        if (file_exists($failas)) {
             MailLog::info("proxies(): reading $failas");
             if ($file = fopen($failas, "r")) {
                while(!feof($file)) {
                    $line = fgets($file);
                    if (!empty($line) && trim($line) != "") {
                      if (preg_match('/set_real_ip_from (.*);/', $line, $matches)) {
                          $proxies[] = $matches[1];
                      } else {
                          MailLog::info("proxies(): skipping non-matching line: " . trim($line));
                      }
                    }
                 }
                fclose($file);
             }
             MailLog::info("proxies(): loaded " . count($proxies) . " proxy IPs");
        } else {
             MailLog::warning("proxies(): $failas does not exist");
        }
        } catch (\Exception $e) {
            MailLog::error("proxies(): cannot read real_ip.conf: ".$e->getMessage());
        }
        if ($request->isMethod('post')) {
        if ($request->type == "1") {
// add proxy
        MailLog::info("proxies(): === ADD PROXY START === server: ".$request->serv);
        $deployment = \Config::get('app.url');
        $errors = 0;
        $str = '';
        if ($request->serv != "") {
                $server = (object) array();
                $server->port = 22;
                $server->username = 'root';

                if ((strpos($request->serv, "@") > -1))
                {
                   $dmp = explode("@", $request->serv);
                   if (strpos($dmp[0], ":") > -1) {
                       $dmp2 = explode(":", $dmp[0]);
                       $server->username = $dmp2[0];
                       $server->password = $dmp2[1];
                   }
                   if (strpos($dmp[1], ":") > -1) {
                      $dmp3 = explode(":",$dmp[1]);
                      $server->hostname = $dmp3[0];
                      $server->port = $dmp3[1];
                   } else {
                       $server->hostname = $dmp[1];
                   }
               }

                if ((strpos($request->input('server'), ":") > -1)) {
                    $dmp = explode(":", $request->input('server'));
                    if(isset($dmp[2])) {
                        $server->port = $dmp[2];
                    }
                } else {
                    $server->port = 22;
                }

         $cmd = '$HOME/public_html/tools/debian_proxy new ' . $server->hostname . ' ' . $deployment . ' ' . $server->username . ' ' . $server->password . ' 1 2>&1';
         MailLog::info("proxies(): executing: ".$cmd);
         $start_time = microtime(true);
         exec($cmd, $retArr, $retVal);
         $elapsed = round(microtime(true) - $start_time, 2);
         MailLog::info("proxies(): proxy setup completed in {$elapsed}s, exit code: $retVal");
         if ($retVal == 0) {
         MailLog::info("proxies(): SUCCESS - adding {$server->hostname} to real_ip.conf");
         // add to real_ip.conf and rehash nginx
         $fp = fopen($failas, 'a');
         fwrite($fp, "set_real_ip_from ".$server->hostname.";\n");
         fclose($fp);
         exec("sudo service nginx reload");
         MailLog::info("proxies(): nginx reloaded");
         }
         if ($retVal != 0) {
             MailLog::error("proxies(): FAILED with exit code $retVal");
             $errors = 1;
         }
                foreach ($retArr as $output) {
                    $str .= $output . "\n";
                }
            }
         MailLog::info("proxies(): === ADD PROXY END === output: ".$str);
            header('Content-Type: application/json');
            $data = json_encode(array('error' => $errors,'respond' => $str));
            return $data;
        }
        if ($request->type == "2" && $request->serv != "") {
// remove proxy
           MailLog::info("proxies(): === REMOVE PROXY === server: ".$request->serv);
           $term = $request->serv;
           $arr = file($failas);
           foreach ($arr as $key=> $line) {
              //removing the line
              if(stristr($line,$term)!== false){unset($arr[$key]);break;}
           }
            //reindexing array
            $arr = array_values($arr);
            //writing to file
            file_put_contents($failas, implode($arr));
            exec("sudo service nginx reload");
            header('Content-Type: application/json');
            $data = json_encode(array('error' => 0,'respond' => ''));
            return $data;
           }
        if ($request->type == "3") {
           $status = $request->status;
           MailLog::info("Changing status of proxy: ".$status);
           \Redis::set("proxy_wide",$status);
           }
        if ($request->type == "4") {
           $servas = $request->serv;
           MailLog::info("Default proxy system wide have been set to: ".$servas);
           \Redis::set("proxy_default",$servas);
        }
        }
        return view('admin.settings.proxies', [
                  'proxies' => $proxies,
                  'enabled' => $proxy_is_enabled,
                  'proxy_ip' => $proxy_ip,
        ]);

    }


    /**
     * Initiate a AWS SNS session and return the session object (snsClient).
     *
     * @return mixed
     */
    public function snsClient()
    {
        if (!self::$snsClient) {
            self::$snsClient = \Aws\Sns\SnsClient::factory(array(
                'credentials' => array(
                    'key' => trim($this->aws_access_key_id),
                    'secret' => trim($this->aws_secret_access_key),
                ),
                'region' => $this->aws_region,
                'version' => '2010-03-31'
            ));
        }

        return self::$snsClient;
    }

    /**
     * Initiate a AWS SES session and return the session object (snsClient).
     *
     * @return mixed
     */
    public function sesClient()
    {
        if (!self::$sesClient) {
            self::$sesClient = \Aws\Ses\SesClient::factory(array(
                'credentials' => array(
                    'key' => trim($this->aws_access_key_id),
                    'secret' => trim($this->aws_secret_access_key),
                ),
                'region' => $this->aws_region,
                'version' => '2010-12-01'
            ));
        }

        return self::$sesClient;
    }

    /**
     * Setup AWS SNS for bounce and feedback loop.
     *
     * @return mixed
     */
    public function setupSns($fromEmail)
    {
        if (!self::$sesClient) {
            self::$sesClient = \Aws\Ses\SesClient::factory(array(
                'credentials' => array(
                    'key' => trim($this->aws_access_key_id),
                    'secret' => trim($this->aws_secret_access_key),
                ),
                'region' => $this->aws_region,
                'version' => '2010-12-01'
            ));
        }

        try {
            $this->sesClient()->setIdentityFeedbackForwardingEnabled(array(
                'Identity' => $fromEmail,
                'ForwardingEnabled' => true,
            ));
        } catch (\Exception $e) {
            $verifyByDomain = true;
            MailLog::warning("From Email address {$fromEmail} not verified by Amazon SES, using domain instead");
        }

        if ($verifyByDomain) {
            // Use domain name as Aws Identity
            $awsIdentity = substr(strrchr($fromEmail, '@'), 1); // extract domain from email
            $this->sesClient()->setIdentityFeedbackForwardingEnabled(array(
                'Identity' => $awsIdentity, // extract domain from email
                'ForwardingEnabled' => true,
            ));
        }

        $topicResponse = $this->snsClient()->createTopic(array('Name' => self::SNS_TOPIC));
        $subscribeUrl = StringHelper::joinUrl(Setting::get('url_delivery_handler'), self::SNS_TYPE);
        MailLog::info("got from ses: ".print_r($topicResponse,true));
    }


    public function amazonses(Request $request)
    {
     //   $settings['speed'] = DB::table('nustatymai')->where('id', 2)->first()->reiksm;
        // setup handler here
        if ($request->isMethod('post')) {
            MailLog::info("Doing setup of amazon sns with $request->amazonkey $request->amazonsecret");
            $this->aws_access_key_id = $request->amazonkey;
            $this->aws_secret_access_key = $request->amazonsecret;
            $this->setupSns($request->domain);
        }

$settings = null;

        return view('admin.settings.amazonses', [
            'settings' => $settings,
        ]);
    }





    /**
     * General settings.
     *
     * @return \Illuminate\Http\Response
     */
   public function speed(Request $request)
{
        $settings = \Acelle\Model\Setting::getAll();
        if ($request->isMethod('post')) {

if ($request->input('unlimited') == "on") {
// set unlimited limits
//DB::table('sending_servers')->update(['quota_value' => -1, 'quota_base' => -1, 'quota_unit' => 'hour']);
    DB::table('nustatymai')->where('id', 2)->update(['reiksm' => 0]);
} else {
// very limited
    $speed = $request->input('quota_value');
    DB::table('nustatymai')->where('id', 2)->update(['reiksm' => $speed]);
//$send_limit = $request->input('quota_value');
//$time_base = $request->input('quota_base');
//$time_unit = $request->input('quota_unit');
//DB::table('sending_servers')->update(['quota_value' => $send_limit, 'quota_base' => $time_base, 'quota_unit' => $time_unit]);
}

}

$settings['speed'] = DB::table('nustatymai')->where('id', 2)->first()->reiksm;


     return view('admin.settings.speed', [
            'settings' => $settings,
        ]);
}

    public function warmup(Request $request) {
       $pools = \Acelle\Model\Warmups::getAll()->get();

        return view('admin.settings.warmup', [
            'pools' => $pools,
        ]);
    }

    public function warmup_settings(Request $request) {

        if ($request->isMethod('post')) {
            Nustatymai::set('warmup_default_tracking',$request->tracking);
            Nustatymai::set('warmup_default_name',$request->name);
            Nustatymai::set('warmup_default_subject',$request->subject);
            Nustatymai::set('warmup_default_text',$request->text);
        }

        $data['tracking'] = Nustatymai::get('warmup_default_tracking');
        $data['name'] = Nustatymai::get('warmup_default_name');
        $data['subject'] = Nustatymai::get('warmup_default_subject');
        $data['text'] = Nustatymai::get('warmup_default_text');

        return view('admin.settings.warmup_settings', [
            'data' => $data,
        ]);
    }

    public function warmup_del(Request $request) {
        if ($request->isMethod('post')) {
            try {
                $uid = $request->uid;
                MailLog::info("Deleting warmup pool: " . $uid);
                Warmups::findByUid($uid)->delete();
            } catch (Exception $ex) {
                MailLog::error("SettingController:warmup_del error: ".$ex);
            }
            header('Content-Type: application/json');
            return json_encode(array('status' => 1));
        }
    }

    public function warmup_server_production(Request $request) {
       $uid = $request->uid;
        if ($request->isMethod('post')) {
            MailLog::info("Got command to move warmup pool server ip to the production, uid: $uid");
            $pool = Warmups::findByUid($uid);
            $pst_url = \Config::get('app.url')."/api/postserver";
            try {
                foreach ($pool->ips()->get() as $ip) {
                    //MailLog::info("Pool: $uid has ip: $ip->ip_address");
                    $data = ['api_key' => 1122, 'server_name' => "Pool: $pool->name $ip->ip_address", 'server_host' => $ip->ip_address, 'server_user' => "root", 'server_pass' => $pool->server_pass, 'server_port' => 2525];
                    $ch = \curl_init($pst_url);
                    \curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json'
                    ));
                    $data_string = json_encode($data);
                    \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    \curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    $response = curl_exec($ch);
                    //MailLog::info("Got response: ".print_r($response,true));
                    \curl_close($ch);
                }
                header('Content-Type: application/json');
                return json_encode(array('status' => 1));
            } catch (Exception $ex) {
                MailLog::error("Got Problem in SettingController:warmup_server_production $ex");
                header('Content-Type: application/json');
                return json_encode(array('status' => 0));
            }
        }
    }

    public function warmup_test_test(Request $request) {
        MailLog::info("just a one more debug");
        MailLog::info("DEBUG FROM DEBUG: ".print_r($request->request->all(),true));
    }

    public function readlog(Request $request)
    {
        try {
            $stricted_logs = array('open.log','click.log','mail.log','laravel.log');
         //   MailLog::info("We are trying to read log: ".$request->log);
            if (isset($request->log)&&in_array($request->log,$stricted_logs)) {
                session_start();
                $handle = fopen('./storage/logs/'.$request->log, 'rb');
                if (isset($_SESSION[$request->log]['offset'])) {
                    $data = stream_get_contents($handle, -1, $_SESSION[$request->log]['offset']);
                    $_SESSION[$request->log]['offset'] += strlen($data);
                    echo nl2br($data);
                } else {
                    fseek($handle, 0, SEEK_END);
                    $_SESSION[$request->log]['offset'] = ftell($handle);
                }
                exit();
            } else {
                MailLog::error("Tried to read unauthorized log :D ".$request->log);
                //$_SESSION['offset'] = null;
            }
        } catch (\Exception $ex) {
            MailLog::error("We got problem in SettingController.php:readlog function: ".$ex);
        }
    }

   public function taskrunner_respond(Request  $request) {
        if ($request->isMethod('post')) {
//            MailLog::info("Requested direct call to taskrunner api");
//            MailLog::info("Received data: ".print_r($request->data,true));
            $runner = New TaskRunner();
            $resp = $runner->respond((int)$request->type,(int)$request->user()->customer->id,$request->data);
//MailLog::info("response: ".$resp);
            header('Content-Type: application/json');
            return $resp;
        }
    }


    public function taskrunner(Request $request)
    {
        if ($request->isMethod('post')) {
            $runner = New TaskRunner();
            $runner->send_queue((int)$request->type,$request->user()->customer->id,(int)$request->priority,$request->val);
            if (isset($request->raw) && $request->raw == "direct") {
                $objson = (object) array();
                $objson->status = 1;
                header('Content-Type: application/json');
                return json_encode($objson);
            }
        }

        $campaigns = Campaign::getAll()->whereNull('del_date')->whereNull('archived')->get();
        $lists = MailList::getAll()->get();
        $settings = null;
        return view('admin.settings.taskrunner', [
            'settings' => $settings,
            'lists' => $lists,
            'campaigns' => $campaigns,
        ]);
    }


    public function checkstorage_availability(Request $request)
    {
        $stor = new StorageHelper();
        //$ret = json_decode($stor->pingpond());
       // MailLog::info("DEBUG: ".print_r($ret,true));
        return $stor->pingpond();
    }

    public function storage(Request $request)
    {
        $responder = "";
        if ($request->isMethod('post')&& $request->records_list != "") {
            $stor = new StorageHelper();
            $responder = $stor->AddToSorage($request->records_list,$request->submit_type,$request->reason);
            MailLog::info('Just test storage function post');
        }
        return view('admin.settings.storage', [
            'responder' => $responder,
//            'lists' => $lists,
        ]);
    }

    public function load_mta_servers(Request $request) {
        $mta = \Config::get('app.mta');

        $postdata = http_build_query(
            array()
        );
        $opts = array('http' =>
            array(
                'timeout' => 30,
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $postdata
            )
        );
        $context  = stream_context_create($opts);
        $result = file_get_contents($mta."/api.php?checkservers", false, $context);
        if (!empty($result)) {
            $servres = json_decode($result);
            //MailLog::info("Got result: ".$result);
            return $servres;
        } else {
            MailLog::info("checkservers from mta returned empty result");
        }
        return;
    }

    public function checkmta(Request $request) {
        $mta = \Config::get('app.mta');
        $json = file_get_contents($mta."/api.php?watcherrunning");
        return $json;
    }

    public function checkpmta(Request $request) {
        $mta = \Config::get('app.mta');
        $json = file_get_contents($mta."/api.php?pmtawatcherrunning");
        return $json;
    }

    public function delfrommta(Request $request) {
        $mta = \Config::get('app.mta');
        $host = $request->host;
        file_get_contents($mta."/api.php?addserver=1&delete=$host");
        return;
    }

    public function mta(Request $request)
    {
        $responder = "";
        return view('admin.settings.mta', [
            'responder' => $responder,
        ]);
    }

    public function hardbounces(Request $request)
    {
        $settings = \Acelle\Model\Setting::getAll();
        if ($request->isMethod('post')) {


                $val = $request->input('regexp');
                DB::table('nustatymai')->where('id', 3)->update(['reiksm' => $val]);
// sitoj vietoj reikia daryti daemonu restarta
        //     exec("\$HOME/public_html/tools/batch stop");
             sleep(2);
        //     exec("nohup \$HOME/public_html/tools/batch > /dev/null 2>&1 &");
        }

        $settings['regexp'] = DB::table('nustatymai')->where('id', 3)->first()->reiksm;


        return view('admin.settings.hardbounces', [
            'settings' => $settings,
        ]);
    }




    public function rotator_reset(Request $request)
    {
       if (Redis::exists('domains_rotator_timebomb')) Redis::del('domains_rotator_timebomb');
        if (Redis::exists('domains_rotator_current')) Redis::del('domains_rotator_current');
        $request->session()->flash('alert-success', 'Operation complete!');
        return redirect()->action('Admin\SettingController@rotator');
    }

    public function rotator_perk_reset(Request $request)
    {
//        if (Redis::exists('domains_rotator_timebomb')) Redis::del('domains_rotator_timebomb');
//        if (Redis::exists('domains_rotator_current')) Redis::del('domains_rotator_current');
        $request->session()->flash('alert-success', 'Operation complete!');
        return redirect()->action('Admin\SettingController@rotator_perk');
    }

    public function rotator_perk(Request $request)
    {
        if ($request->isMethod('post')) {
            try {
                $store['domains'] = $request->input('domains');
                $store['enabled'] = $request->input('enabled');
                $store['interval'] = $request->input('interval');
                $data = json_encode($store);
                DB::table('nustatymai')->where('pavad', 'domains_rotator_perk')->update(['reiksm' => $data]);
                Redis::set('domains_rotator_perk', $data);
                $request->session()->flash('alert-success', 'Rotator settings saved!');
            } catch (\Exception $ex) {
                MailLog::error("Problems in saving data from function rotator_perk: ".$ex);
            }
        }


        $current = null;

        try {
            $settings = json_decode(DB::table('nustatymai')->where('pavad', 'domains_rotator_perk')->first()->reiksm ?? null) ?? null;
        } catch (\Exception $ex) {
            MailLog::error("Unable to load rotator_perk settings from mysql: ".$ex);
            $settings = null;
        }

        $current = null;

//        if (Redis::exists('domains_rotator_perk_current')) {
//            try {
//                $last_no = Redis::get('domains_rotator_perk_current') ?? 0;
//                $domains = @(array)explode(PHP_EOL, trim($settings->domains));
//                $current = $domains[$last_no];
//            } catch (\Exception $ex) {
//                $current = null;
//            }
//            //    MailLog::info('current domain: '.$current);
//        } else {
//            try {
//                $domains = (array)explode(PHP_EOL, trim($settings->domains));
//                if (isset($domains[0])) $current = $domains[0];
//                $current = $domains[0];
//            } catch (\Exception $ex) {
//                MailLog::error("Unable to fetch domains for rotator_perk, non existen? :D ". $ex);
//            }
//        }

        if ($settings != null)
            $settings->current = $current ?? null;
        return view('admin.settings.rotator_perk', [ 'settings' => $settings ]);
    }

    public function rotator(Request $request)
    {
        if ($request->isMethod('post')) {
            $store['domains'] = $request->input('domains');
            $store['enabled'] = $request->input('enabled');
            $store['interval'] = $request->input('interval');
            $data = json_encode($store);
            DB::table('nustatymai')->where('pavad','domains_rotator')->update(['reiksm' => $data]);
            Redis::set('domains_rotator',$data);
            $request->session()->flash('alert-success', 'Rotator settings saved!');
        }

        $current = null;


        $settings = json_decode(DB::table('nustatymai')->where('pavad','domains_rotator')->first()->reiksm ?? null) ?? null;


        if (Redis::exists('domains_rotator_current')) {
            try {
                $last_no = Redis::get('domains_rotator_current');
                $domains = @(array)explode('\n', trim($settings->domains));
                 //     MailLog::info('aaaa: '.print_r($domains,true));
                $current = $domains[$last_no];
            } catch (\Exception $ex) {
                $current = null;
            }
        //    MailLog::info('current domain: '.$current);
        } else {
            $domains = (array)explode('\n', trim($settings->domains));
            if (isset($domains[0])) $current = $domains[0];
        }

        if ($settings != null)
        $settings->current = $current ?? null;

        return view('admin.settings.rotator', [ 'settings' => $settings ]);
    }


    public function controller(Request $request)
    {
        $settings = \Acelle\Model\Setting::getAll();
        if ($request->isMethod('post')) {


            //$val = $request->input('regexp');
            DB::table('nustatymai')->where('id', 4)->update(['reiksm' => $request->input('a_keyword')]);
            DB::table('nustatymai')->where('id', 5)->update(['reiksm' => $request->input('b_keyword')]);
            DB::table('nustatymai')->where('id', 6)->update(['reiksm' => $request->input('domains')]);
// sitoj vietoj reikia daryti daemonu restarta
            exec("\$HOME/public_html/tools/batch stop");
            sleep(2);
            exec("nohup \$HOME/public_html/tools/batch > /dev/null 2>&1 &");
        }

        $settings['a_keyword'] = DB::table('nustatymai')->where('id', 4)->first()->reiksm;
        $settings['b_keyword'] = DB::table('nustatymai')->where('id', 5)->first()->reiksm;
        $settings['domains'] = DB::table('nustatymai')->where('id', 6)->first()->reiksm;

        return view('admin.settings.controller', [
            'settings' => $settings,
        ]);
    }


    public function check_proc_server($uid,$host) {
        $exists= false;
        exec("ps -C gosender -o args| grep \"$uid\" | grep \"$host\" ", $pids);
        if (count($pids) > 1) {
            $exists = true;
        }
        return $exists;
    }


    public function ViewCampaignProcess(Request $request)
    {
        $time_start = microtime(true);
        header('Content-Type: application/json');
        if (Redis::exists($request->uid)&&(json_decode(Redis::get($request->uid))->status == "sending" || json_decode(Redis::get($request->uid))->status == "paused")) {
            $campaign = json_decode(Redis::get($request->uid));
            unset($campaign->html);
            unset($campaign->plain);
            $cnt = Redis::get($request->uid . '_counter');
            $total = Redis::get($request->uid . '_total');
            $openers = Redis::get($request->uid . '_openers');
            $clickers = Redis::get($request->uid . '_clickers');
            $status = $campaign->status;
            if (Redis::exists($request->uid . '_paused')) $status = "paused";
            if (is_null($cnt)) $cnt = 0;
            if (is_null($total)) $total = 0;
            if (is_null($openers)) $openers = 0;
            if (is_null($clickers)) $clickers = 0;
            //$servers = array();
            if (Redis::exists($request->uid.'_servers')) $servers = json_decode(Redis::get($request->uid.'_servers'));
            if (isset($servers)) {
                exec('ps -C gosender -o args|grep "'.$request->uid.'"|cut -d \' \' -f 8', $a);
                foreach ($servers as $servas) {
                    if(in_array($servas->host, $a)){
                        $servas->running=1;
                    } else {
                        // running
                        $servas->running=0;
                    }
                }
            } else {
                $servers = array();
            }


            $datas = json_encode(array('campaign' => $campaign, 'status' => $status, 'total' => $total, 'openers' => $openers, 'clickers' => $clickers, 'status' => $status, 'counter' => $cnt, 'servers' => $servers, 'took' => (microtime(true) - $time_start)));
            return $datas;
        } else {
            return json_encode(array('status' => 'Not sending'));
        }
    }

    public function ViewRedisQueue(Request $request)
    {
        $items_count = Redis::llen("queues:queue"); // queues:high:delayed TODO
        $list_items = array();
        for ($i = 0; $i < $items_count; ++$i) {
            $list_items[] = json_decode(Redis::lIndex('queues:queue', $i));
        }
        if (Redis::exists('queues:high:delayed')) {
            foreach (Redis::zrange("queues:high:delayed", 0, -1) as $com)
                $list_items[] = json_decode($com);
        }



      header('Content-Type: application/json');
      return json_encode($list_items);
}

    private function getOSInformation()
    {
        if (false == function_exists("shell_exec") || false == is_readable("/etc/os-release")) {
            return null;
        }

        $os         = shell_exec('cat /etc/os-release');
        $listIds    = preg_match_all('/.*=/', $os, $matchListIds);
        $listIds    = $matchListIds[0];

        $listVal    = preg_match_all('/=.*/', $os, $matchListVal);
        $listVal    = $matchListVal[0];

        array_walk($listIds, function(&$v, $k){
            $v = strtolower(str_replace('=', '', $v));
        });

        array_walk($listVal, function(&$v, $k){
            $v = preg_replace('/=|"/', '', $v);
        });

        return array_combine($listIds, $listVal);
    }

public function ver(Request $request) {
    $data["php"] = phpversion();
    $data["os"] = $this->getOSInformation();
       return view('admin.settings.ver', [
           'data' => $data,
       ]);
}

public function FindContactByUid(Request $request) {
    return view('admin.settings.findbyuid', [
    ]);
}

public function FindContactUidFunc(Request $request, $uid) {
       MailLog::info("Queried for uid $uid");
       $subscriber = Subscriber::findByUid($uid);
       if (is_object($subscriber)) {
           $subscriber->succeed = 1;
       } else {
           $subscriber = new \stdClass();
           $subscriber->succeed = 0;
       }
       header('Content-Type: application/json');
       return json_encode($subscriber);
}

public function SetServerInfo(Request $request) {
    if ($request->isMethod('post')) {
        $ip = $request->ip;
        $type = $request->type;
        $val = $request->val;
        MailLog::info("Got set server request with info $ip $type $val");
        if ($ip != "" && $val != "" && $type != "") {
            \Redis::set("server_".$ip."_".$type,$val);
        }
    }
    header('Content-Type: application/json');
    return json_encode(array());
}



    public function maintenance(Request $request)
    {
        $settings = \Acelle\Model\Setting::getAll();

        if ($request->isMethod('post')) {
            if ($request->input('mysql') !== null) {
               // do background task
                Log::Info('Memory now at: ' . memory_get_peak_usage());

                $kill_pids = DB::select(DB::raw("SELECT * FROM information_schema.processlist WHERE STATE != '' AND COMMAND != 'Sleep' ORDER BY TIME DESC;"));
                foreach ($kill_pids as $pid) {
                    // don't kill self query
                    if (!preg_match("/processlist/",$pid->INFO)) {
                        // echo "killing: " . $pid->ID;
                        DB::select(DB::raw("kill " . $pid->ID));
                    }
                }

                Log::Info('After mysql kill, Memory now at: ' . memory_get_peak_usage());
                $request->session()->flash('alert-success', 'Operation complete!');
                return redirect()->action('Admin\SettingController@maintenance');

            }
elseif ($request->input('poststop') !== null)
{
    // do background task
    exec("nohup \$HOME/public_html/tools/batch stop > /dev/null 2>&1 &");
    $request->session()->flash('alert-success', 'Operation complete!');
    return redirect()->action('Admin\SettingController@maintenance');
}
            elseif ($request->input('poststart') !== null)
            {
                // do background task
                exec("nohup \$HOME/public_html/tools/batch > /dev/null 2>&1 &");
                $request->session()->flash('alert-success', 'Operation complete!');
                return redirect()->action('Admin\SettingController@maintenance');
            }
            elseif ($request->input('postrestart') !== null)
            {
                $headers = array(
                    'Content-Type: text/xml',
                );

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "http://mta.parkagency.org/api.php?cmd=restart&api_key=1122");
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $result = curl_exec($ch);
                curl_close ($ch);


/*
                // do background task
                $opts = array('http' =>
                    array(
                        'method'  => 'GET',
                        'header'  => "Content-Type: text/xml\r\n",
                        'timeout' => 60
                    )
                );
                $context  = stream_context_create($opts);
                $url = 'http://mta.parkagency.org/api.php?cmd=restart&api_key=1122';
                $result = file_get_contents($url, false, $context, -1, 40000);
*/

                MailLog::info("Restart postfix parsers: ".print_r($result,true));

                $request->session()->flash('alert-success', 'Operation complete!');
                return redirect()->action('Admin\SettingController@maintenance');
            }
            elseif ($request->input('sendstop') !== null)
            {
                // do background task
                exec("kill `ps x|grep -v grep|grep gosender|awk '{ print \$1}'` > /dev/null 2>&1 &");
                $request->session()->flash('alert-success', 'Operation complete!');
                return redirect()->action('Admin\SettingController@maintenance');
            } elseif ($request->input('cleanredis') !== null) {
                Redis::del("queues:queue");
                $request->session()->flash('alert-success', 'Operation complete!');
                return redirect()->action('Admin\SettingController@maintenance');
            }
            elseif ($request->input('sendrestart') !== null)
            {
                // here to foreach all the mail lists
                MailLog::info("MAINTENANCE BACKGROUND SENDING PROCESSES RESTART IS ISSUED ! ! !");
                $campaigns = \DB::table('campaigns')->select('campaigns.uid')
                            ->where('campaigns.status', 'sending')
                            ->get();
                if (count($campaigns) > 0) {
                $campaigns_fixed = array();
                foreach ($campaigns as $key=>$value)
                {
                    $campaigns_fixed[] = $value->uid;
                }

                    MailLog::info("Making queue for campaign restart for these uids: " . print_r($campaigns_fixed, true));
                    $job = (new \Acelle\Jobs\RestartProcessesJob($campaigns_fixed))->delay(0);
                    $this->dispatch($job);
                } else {
                    MailLog::info("NO CAMPAIGN HAS BEEN FOUND WITH STATUS=SENDING");
                }

//                exec("kill `ps x|grep -v grep|grep gosender|awk '{ print \$1}'` > /dev/null 2>&1 &");
//                $default_fitness = \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
//                $maillists = \Acelle\Model\MailList::all();
//                foreach ($maillists as $list) {
//                    MailLog::info("Got maillist: " . $list->name);
//                    if ($list->all_sending_servers == 1) {
//                        MailLog::info("All sending servers are enabled");
//                        $compaigns = \DB::table('mail_lists')->select('campaigns.uid')
//                            ->join('campaigns_lists_segments', 'mail_lists.id', '=', 'campaigns_lists_segments.mail_list_id')
//                            ->join('campaigns', 'campaigns_lists_segments.campaign_id', '=', 'campaigns.id')
//                            ->where('mail_lists.uid', $list->uid)
//                            ->where('campaigns.status', 'sending')
//                            ->get();
//                        foreach ($compaigns as $c) {
//                            MailLog::info("Targeting campaign: " . $c->uid . " with all servers");
//                            $servers = \DB::table('sending_servers')->select('host', 'smtp_port')
//                                ->where('status', 'active')->where('sending_servers.id', '>', 1)->get();
//                            foreach ($servers as $servas) {
//                                if (!isset($servas->fitness)) $servas->fitness = $default_fitness;
//                                MailLog::info("\$HOME/gosender --send --campuid $c->uid --smtphost $servas->host --smtpport $servas->smtp_port --smtpspeed $servas->fitness  > /dev/null 2>&1 &");
//                                exec("\$HOME/gosender --send --campuid $c->uid --smtphost $servas->host --smtpport $servas->smtp_port --smtpspeed $servas->fitness  > /dev/null 2>&1 &");
//                            }
//                        }
//
//
//                    } else {
//                           MailLog::info("Only specified servers are enabled");
//                        // update all the servers that are
//                        $compaigns = \DB::table('mail_lists')->select('campaigns.uid')
//                            ->join('campaigns_lists_segments', 'mail_lists.id', '=', 'campaigns_lists_segments.mail_list_id')
//                            ->join('campaigns', 'campaigns_lists_segments.campaign_id', '=', 'campaigns.id')
//                            ->where('mail_lists.uid', $list->uid)
//                            ->where('campaigns.status', 'sending')
//                            ->get();
//                        foreach ($compaigns as $c) {
//                            MailLog::info("Targeting campaign: " . $c->uid . " with selected servers");
//                            $servers = \DB::table('campaigns')->select('host', 'smtp_port', 'fitness')
//                                ->join('campaigns_lists_segments', 'campaigns.id', '=', 'campaigns_lists_segments.campaign_id')
//                                ->join('mail_lists_sending_servers', 'campaigns_lists_segments.mail_list_id', '=', 'mail_lists_sending_servers.mail_list_id')
//                                ->join('sending_servers', 'mail_lists_sending_servers.sending_server_id', '=', 'sending_servers.id')
//                                ->where('campaigns.uid', $c->uid)->get();
//                            foreach ($servers as $servas) {
//                                if (!isset($servas->fitness)) $servas->fitness = $default_fitness;
//                                MailLog::info("\$HOME/gosender --send --campuid $c->uid --smtphost " . $servas->host . " --smtpport " . $servas->smtp_port . " --smtpspeed " . $servas->fitness . "  > /dev/null 2>&1 &");
//                                exec("\$HOME/gosender --send --campuid $c->uid --smtphost " . $servas->host . " --smtpport " . $servas->smtp_port . " --smtpspeed " . $servas->fitness . "  > /dev/null 2>&1 &");
//                            }
//                        }
//
//
//                    }
//
//
//                }
                MailLog::info("MAINTENANCE DONE RESTARTING BACKGROUND SENDING PROCESSES ! ! !");
                $request->session()->flash('alert-success', 'Operation complete!');
                return redirect()->action('Admin\SettingController@maintenance');
            }
            elseif ($request->input('sendmaint') !== null)
            {
                // do background task
                exec("nohup \$HOME/public_html/tools/maitenance > /dev/null 2>&1 &");
                $request->session()->flash('alert-success', 'Operation complete!');
                return redirect()->action('Admin\SettingController@maintenance');
            }
            elseif ($request->input('killcache') !== null) {
                MailLog::info("Maintenance: kill the cache manager");
                exec("pkill taskrunner");
                $request->session()->flash('alert-success', 'Operation complete!');
                return redirect()->action('Admin\SettingController@maintenance');
            }
            elseif ($request->input('sendtestjob') !== null)
            {
            MailLog::info('just a test background job');
            $caches = array('vilnius','kaunas','klaipeda');
                $job = (new UpdateCachesJob($caches))->delay(360);
                $this->dispatch($job);
                $request->session()->flash('alert-success', 'Operation complete!');
                return redirect()->action('Admin\SettingController@maintenance');
            }


        }


// collect running processes count them and return to the view
        $mysqls = DB::select(DB::raw("SELECT * FROM information_schema.processlist WHERE STATE != '' AND COMMAND != 'Sleep' ORDER BY TIME DESC;"));
        $senders = exec("ps x |grep -v grep|grep gosender|wc -l");
        $parsers = exec("ps x |grep -v grep|grep parse_postfix|wc -l");
        $maint = exec("ps x |grep -v grep|grep maitenance|wc -l");
        if ($maint >0) $settings['running'] = 1;
        else $settings['running'] = 0;
        $settings['senders'] = $senders;
        $settings['parsers'] = $parsers;
        $settings['mysqls'] = count($mysqls);
        $settings['redisqueue'] = Redis::llen("queues:queue");

        return view('admin.settings.maintenance', [
            'settings' => $settings,
        ]);
    }

    public function monitoring(Request $request) {

       $campaigns = \Acelle\Model\Campaign::getAll()->get();
       $settings = array();


        return view('admin.settings.monitoring', [
            'campaigns' => $campaigns,
            'settings' => $settings,
        ]);
    }

    public function general(Request $request)
    {
        if ($request->user()->admin->getPermission('setting_general') != 'yes') {
            return $this->notAuthorized();
        }

        // \Acelle\Model\Setting::updateAll();
        $settings = \Acelle\Model\Setting::getAll();
        if (null !== $request->old()) {
            foreach ($request->old() as $name => $value) {
                if (isset($settings[$name])) {
                    $settings[$name]['value'] = $value;
                }
            }
        }

        // validate and save posted data
        if ($request->isMethod('post')) {

            if($this->isDemoMode()) {
                return $this->notAuthorized();
            }

            $rules = [
                'site_name' => 'required',
                'site_keyword' => 'required',
                'site_online' => 'required',
                'site_offline_message' => 'required',
                'site_description' => 'required',
                'frontend_scheme' => 'required',
                'backend_scheme' => 'required',
                'license' => 'license',
            ];
            $this->validate($request, $rules);

            // Save settings
            foreach ($request->all() as $name => $value) {
                if ($name != '_token' && isset($settings[$name])) {
                    // Upload and save image
                    if ($name == 'site_logo_small' || $name == 'site_logo_big') {
                        if ($request->hasFile($name) && $request->file($name)->isValid()) {
                            \Acelle\Model\Setting::uploadSiteLogo($request->file($name), $name);
                        }
                    } else {
                        if ($settings[$name]['cat'] == 'general' && $request->user()->admin->getPermission('setting_general') == 'yes') {
                            \Acelle\Model\Setting::set($name, $value);
                        }
                    }
                }
            }

            // Redirect to my lists page
            $request->session()->flash('alert-success', trans('messages.setting.updated'));
            return redirect()->action('Admin\SettingController@general');
        }

        return view('admin.settings.general', [
            'settings' => $settings,
        ]);
    }

    /**
     * Sending settings.
     *
     * @return \Illuminate\Http\Response
     */
    public function sending(Request $request)
    {
        if ($request->user()->admin->getPermission('setting_sending') != 'yes') {
            return $this->notAuthorized();
        }

        // \Acelle\Model\Setting::updateAll();
        $settings = \Acelle\Model\Setting::getAll();
        if (null !== $request->old()) {
            foreach ($request->old() as $name => $value) {
                if (isset($settings[$name])) {
                    $settings[$name]['value'] = $value;
                }
            }
        }

        // validate and save posted data
        if ($request->isMethod('post')) {

            if($this->isDemoMode()) {
                return $this->notAuthorized();
            }

            $rules = [
                'sending_campaigns_at_once' => 'required',
                'sending_change_server_time' => 'required',
                'sending_emails_per_minute' => 'required',
                'sending_pause' => 'required',
                'sending_at_once' => 'required',
                'sending_subscribers_at_once' => 'required',
            ];
            $this->validate($request, $rules);

            // Save settings
            foreach ($request->all() as $name => $value) {
                if ($name != '_token' && isset($settings[$name])) {
                    if ($settings[$name]['cat'] == 'sending' && $request->user()->admin->getPermission('setting_sending') == 'yes') {
                        \Acelle\Model\Setting::set($name, $value);
                    }
                }
            }

            // Redirect to my lists page
            $request->session()->flash('alert-success', trans('messages.setting.updated'));
            return redirect()->action('Admin\SettingController@sending');
        }

        return view('admin.settings.sending', [
            'settings' => $settings,
        ]);
    }

    /**
     * Url settings.
     *
     * @return \Illuminate\Http\Response
     */
    public function urls(Request $request)
    {
        if ($request->user()->admin->getPermission('setting_system_urls') != 'yes') {
            return $this->notAuthorized();
        }

        $dns = new DNSHelper();
        if($dns->Is_Enabled()) {
            $domains = $dns->get_domains();
            if (!is_array($domains)) {
                $domains = array('0' => (object)array('name' => 'Domains cannot be detected on the dns system!'));
            }
        } else {
            $domains = array('0' => (object)array('name' => 'DNS feature not enabled in this deployment!'));
        }

        $settings = \Acelle\Model\Setting::getAll();
             $proxy_ip = "...";
             if (\Redis::exists("proxy_wide") && \Redis::get("proxy_wide") == "1" && \Redis::exists("proxy_default") && \Redis::get("proxy_default") != "") {
                $proxy_ip = \Redis::get("proxy_default");
             }
             $settings["proxy_ip"]["value"] = $proxy_ip;
        return view('admin.settings.urls', [
            'settings' => $settings,
            'domains' => $domains,
        ]);
    }

// custom urls
    public function customurls(Request $request)
    {
        if ($request->user()->admin->getPermission('setting_system_urls') != 'yes') {
            return $this->notAuthorized();
        }

        $unsubscribepart = array();
        $openpart = array();
        $clickpart = array();
        $profilepart = array();
        $sourcepart = array();
        if (Redis::sMembers('unsubscribepart')) $unsubscribepart=Redis::sMembers('unsubscribepart');
        if (Redis::sMembers('openpart')) $openpart=Redis::sMembers('openpart');
        if (Redis::sMembers('clickpart')) $clickpart=Redis::sMembers('clickpart');
        if (Redis::sMembers('profilepart')) $profilepart=Redis::sMembers('profilepart');
        if (Redis::sMembers('sourcepart')) $sourcepart=Redis::sMembers('sourcepart');
        return view('admin.settings.customurls', [
        'unsubscribepart' => $unsubscribepart,
            'openpart' => $openpart,
            'clickpart' => $clickpart,
            'profilepart' => $profilepart,
            'sourcepart' => $sourcepart,
        ]);
    }

    public function setcustomurl(Request $request)
    {
        $post_type = $request->type;
        $post_action = $request->action;
        $post_item = $request->item;
        if ($post_type != "" && $post_item != "") {
            switch ($post_action) {
                case "add":
                    Redis::sAdd($post_type, $post_item);
                    break;
                case "del":
                    Redis::sRem($post_type, $post_item);
                    break;
            }
        }
        $request->session()->flash('alert-success', trans('messages.setting.updated'));
        return redirect()->action('Admin\SettingController@customurls');
    }

    /**
     * Cronjob list.
     *
     * @return \Illuminate\Http\Response
     */
    public function cronjob(Request $request)
    {
        if ($request->user()->admin->getPermission('setting_background_job') != 'yes') {
            return $this->notAuthorized();
        }

        // Re-generate remote job url
        if($request->re_generate_remote_job_url) {
            $remote_job_token = str_random(60);
            \Acelle\Model\Setting::set('remote_job_token', $remote_job_token);
            echo action('Controller@remoteJob', ['remote_job_token' => $remote_job_token]);
            return;
        }

        $respone = \Acelle\Library\Tool::cronjobUpdateController($request, $this);
        if($respone == 'done' || $respone['valid'] == true) {
            $next = action('Admin\SettingController@cronjob').'#result_box';
            artisan_config_cache();
            return redirect()->away($next);
        }

        return view('admin.settings.cronjob', $respone);
    }

    /**
     * Mailer settings.
     *
     * @return \Illuminate\Http\Response
     */
    public function mailer(Request $request)
    {
        if ($request->user()->admin->getPermission('setting_general') != 'yes') {
            return $this->notAuthorized();
        }

        // SMTP
        $env = [
            'MAIL_DRIVER' => config("mail.driver"),
            'MAIL_HOST' => config("mail.host"),
            'MAIL_PORT' => config("mail.port"),
            'MAIL_USERNAME' => config("mail.username"),
            'MAIL_PASSWORD' => config("mail.password"),
            'MAIL_ENCRYPTION' => config("mail.encryption"),
            'MAIL_FROM_EMAIL' => config("mail.from")["address"],
            'MAIL_FROM_NAME' => config("mail.from")["name"],
        ];

        if (null !== $request->old() && isset($request->old()["env"])) {
            foreach ($request->old()["env"] as $name => $value) {
                $env[$name] = $value;
            }
        }

        $env_rules = [
            'env.MAIL_DRIVER' => 'required',
            'env.MAIL_HOST' => 'required',
            'env.MAIL_PORT' => 'required',
            'env.MAIL_USERNAME' => 'required',
            'env.MAIL_PASSWORD' => 'required',
            'env.MAIL_FROM_EMAIL' => 'required|email',
            'env.MAIL_FROM_NAME' => 'required',
        ];

        // validate and save posted data
        if ($request->isMethod('post')) {

            if($this->isDemoMode()) {
                return $this->notAuthorized();
            }

            $env = $request->env;

            if ($env["MAIL_DRIVER"] == 'smtp') {
                $this->validate($request, $env_rules);
            }

            // Check SMTP connection
            $site_info = $request->all();
            if ($env["MAIL_DRIVER"] == 'smtp') {
                $rules = [];
                try {
                    $transport = \Swift_SmtpTransport::newInstance($env["MAIL_HOST"], $env["MAIL_PORT"], $env["MAIL_ENCRYPTION"]);
                    $transport->setUsername($env["MAIL_USERNAME"]);
                    $transport->setPassword($env["MAIL_PASSWORD"]);
                    $mailer = \Swift_Mailer::newInstance($transport);
                    $mailer->getTransport()->start();
                } catch (\Swift_TransportException $e) {
                    $rules['smtp_valid'] = 'required';
                } catch (Exception $e) {
                    $rules['smtp_valid'] = 'required';
                }
                $this->validate($request, $rules);
            }

            foreach($env as $key => $value) {
                if(empty($value)) {
                    $value = "null";
                }
                \Acelle\Model\Setting::setEnv($key, $value);
            }

            // Redirect to my lists page
            $next = action('Admin\SettingController@mailer');
            \Artisan::call('config:cache');
            $request->session()->flash('alert-success', trans('messages.setting.updated'));
            sleep(3);
            return redirect()->away($next);
        }

        return view('admin.settings.mailer', [
            'env_rules' => $env_rules,
            'env' => $env,
        ]);
    }
 // cloudflare standard A
    public function updatecfamass(Request $request, $ip) {
        $cloudflare = new CloudFlareHelper();
        if (\Config::get('app.cloudflare_enabled') == true) {
            $domains = $cloudflare->get_domains();
            if (isset($domains)) {
                foreach ($domains as $domain) {
                    foreach($cloudflare->ListAllDNSRecords($domain) as $dnsrecord) {
                        if ($dnsrecord->name == $domain->name) {
                            if ($dnsrecord->type == "A" || $dnsrecord->type == "CNAME") {
                                MailLog::info("setting default ip: $ip for domain $domain->name as dns A record");
                                // delete normal A record
                                $cloudflare->deleteDNSRecord($dnsrecord);
                                MailLog::info("Found A @ record for domain $domain->name");
                            }
                                $dnsas = array('type' => 'A', 'name' => $domain->name, 'content' => $ip, 'proxied' => false);
                                $cloudflare->createDNSRecord($domain->id,$dnsas);
                        }
                    }
                }
           }

        }
        $request->session()->flash('alert-success', 'Ip address migration done!');
        return redirect()->action('Admin\SettingController@dns');
    }
   
 // cloudflare proxied A
    public function updatecfmass(Request $request, $ip) {
        $cloudflare = new CloudFlareHelper();
        if (\Config::get('app.cloudflare_enabled') == true) {
            $domains = $cloudflare->get_domains();
            if (isset($domains)) {
                foreach ($domains as $domain) {
                    //MailLog::info("Got domain: ".print_r($domain,true));
                    $found_proxy = 0;
                    foreach($cloudflare->ListAllDNSRecords($domain) as $dnsrecord) {
                        if ($dnsrecord->name == $domain->name && ($dnsrecord->type == "A" || $dnsrecord->type == "CNAME")) {
                            // found record that needs to be updated
                            if ($dnsrecord->proxied == 1) {
                                $found_proxy = 1;
                                if ($dnsrecord->content != $ip) {
                                    MailLog::info("Found proxied record: " . $dnsrecord->name . " content: " . $dnsrecord->content . " proxied: " . $dnsrecord->proxied);
                                    $dnsrecord->content = $ip;
                                    $cloudflare->UpdateDnsRecordv3($domain,$dnsrecord);
                                } else {
                                    MailLog::info("Record: ".$dnsrecord->name." seems to be already proxied for domain $domain->name");
                                }
                            } else {
                                // delete normal A record
                                $cloudflare->deleteDNSRecord($dnsrecord);
                                MailLog::info("Found a normal A @ record for domain $domain->name");
                            }
                        }
                    }
                    if ($found_proxy == 0) {
                        MailLog::info("We do not found any proxy for domain: ".$domain->name);
                        $dnsas = array('type' => 'A', 'name' => $domain->name, 'content' => $ip, 'proxied' => "1");
                        $cloudflare->createDNSRecord($domain->id,$dnsas);
                    }
                }
            }
        }



        $request->session()->flash('alert-success', 'Ip address migration done!');
        return redirect()->action('Admin\SettingController@dns');
    }


    public function updateProxy(Request $request, $proxy) {

        if ($proxy != "") {
            \DB::table('settings')->where('id',24)->update(['value' => $proxy]);
            \Redis::set('proxyip',$proxy);
        }

        $request->session()->flash('alert-success', trans('messages.setting.updated'));
        return redirect()->action('Admin\SettingController@urls');

    }

    public function delete_domain(Request $request, $domain) {
        $dns = new DNSHelper();
        if($dns->Is_Enabled()) {
            $dns->delete_domain($domain);
            $request->session()->flash('alert-success', trans('messages.setting.updated'));
        } else {
            $request->session()->flash('alert-success', 'DNS feature is not enabled in this deployment!');
        }
        return redirect()->action('Admin\SettingController@dns');

    }


    public function servers(Request $request) {
        // Initialize MailLog for HTTP context
        if (!MailLog::$logger) {
            MailLog::configure(storage_path().'/logs/setup_server.log');
        }
        $error = 0;
        $error_msg = null;
        $server = (object) array();
        $appliances = [];

        if ($request->isMethod('post') && $request->input('check') == 1) {
            // check compatibility
            if ($request->input('server') != "") {
                $server->port = 22;
                $server->username = 'root';

                if ((strpos($request->input('server'), "@") > -1))
                {
                   $dmp = explode("@", $request->input('server'));
                   if (strpos($dmp[0], ":") > -1) {
                       $dmp2 = explode(":", $dmp[0]);
                       $server->username = $dmp2[0];
                       $server->password = $dmp2[1];
                   }
                   if (strpos($dmp[1], ":") > -1) {
                      $dmp3 = explode(":",$dmp[1]);
                      $server->hostname = $dmp3[0];
                      $server->port = $dmp3[1];
                   } else {
                       $server->hostname = $dmp[1];
                   }

                }

                if ((strpos($request->input('server'), ":") > -1)) {
                    $dmp = explode(":", $request->input('server'));
                    if(isset($dmp[2])) {
                        $server->port = $dmp[2];
                    }
                } else {
                    $server->port = 22;
                }

                MailLog::info('$HOME/public_html/tools/check_server_compatibility --host '.$server->hostname.' --user '.$server->username.' --pass '.$server->password.' --port '.$server->port.' 2>&1');
               exec('$HOME/public_html/tools/check_server_compatibility --host '.$server->hostname.' --user '.$server->username.' --pass '.$server->password.' --port '.$server->port.' 2>&1',$retArr, $retVal);
               foreach ($retArr as $output) {
                   if (strpos($output,"JSON: ") > -1) {
                       $output =  preg_replace('/JSON: /','',$output);
                       MailLog::info("Server detection script: $output");
                       $json = @json_decode($output);
                       if (is_object($json)&&$json->valid == 1) {
// valid server detected
                        $server = $json;
                       } else {
                           $error = 1;
                           if (is_object($json)) {
                               $error_msg = $json->msg;
                           }
                       }


                       MailLog::info("Got output json: ".$output);
                   }

               }

               if (\Redis::exists('appliances')) {
                   $appln = \Redis::hgetall('appliances');
                   $appliances = [];
                   foreach ($appln as $key => $value) {
                       $appliances[] = $key;
                   }
               }
                  //  $server->verified = 1;
                  //  $server->msg = print_r($retArr,true);

                //MailLog::info('Run check: '.$server->msg);

                MailLog::info("We got server $server->hostname with username $server->username password: $server->password and port: $server->port");

            } else {
                $error = 1;
                $error_msg = "Badly written server syntax!";
            }

        }

        return view('admin.settings.servers', [ 'error' => $error, 'error_msg' => $error_msg, 'server' => $server, 'appliances' => $appliances ]);
    }

    private function generateRandomString($length = 10) {
        return substr(str_shuffle(str_repeat($x='bcdefghijklmnopqrstuvwxyz', ceil($length/strlen($x)) )),1,$length);
    }

    private function generateRandomId($length = 10) {
        return substr(str_shuffle(str_repeat($x='0123456789', ceil($length/strlen($x)) )),1,$length);
    }

    public function initialize_dns(Request $request) {

        $rand_dns_name = $this->generateRandomString(2);
        $rand_dns_id = $this->generateRandomId(2);
        $random_dns = $rand_dns_name.$rand_dns_id;
        MailLog::info("SettingController::initialize_dns domain: $request->domain domain_id: $request->domain_id ip: $request->ip with random dns: $random_dns.$request->domain");
        $json = (object) array();

        if (!empty($request->domain)&&!empty($request->domain_id)&&!empty($request->ip)) {
            /*
            $cloudflare = new CloudFlareHelper();
            if ($cloudflare->createDNSRecord($request->domain_id,array('type'=>'A','name' => $random_dns, 'content' => $request->ip, 'proxied' => false))) {
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
	   $cloudflare->createDNSRecord($request->domain_id,array('type'=>'A','name' => $random_dns, 'content' => $request->ip, 'proxied' => false));
           } catch (Exception $ex) {
              MailLog::error("error in SettingController::initialize_dns, maybe the domain have'nt been added to cloudflare");
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

    private function warmup_server_test($customer,$serv,$email) {
        $subject = Nustatymai::get('warmup_default_subject') ?? "test mail";
        $text = Nustatymai::get('warmup_default_text') ?? "Just a test email";
        $to_email = $email;
        $server = $serv;
        $randid = rand(33334234,2343245890435345);
        $html = "<html><content>$text</content></html>";
        $deployment = \Config::get('app.deployment');
        $global_tracking = Nustatymai::get('warmup_default_tracking') ?? Setting::get('global_tracking');
        $from_email = 'info@'.$global_tracking;
        $subject = $subject." ".base64_encode($server->hostname);

        $taskrunner = New TaskRunner();
        $camp_uid = \Config::get('app.default_mail_header');
        $headers = array($camp_uid => "$randid [$deployment]");
        $send_data = array('server_ip' => $server->hostname, 'port' => (int)2525, 'from_email' => $from_email,'to_email' => $to_email, 'subject' => $subject, 'body' => $html, 'headers' => $headers);
        $smtp_request = json_encode($send_data);
        $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_SMTP_SEND,$customer,$taskrunner::PRIORITY_HIGH,$smtp_request);

        MailLog::info('Sending test mail via: ' . $server->hostname);
        MailLog::info('Sleeping well for a second...');
        sleep(4);
        MailLog::info("Gathering information from the server...");
        $cmd = '$HOME/public_html/tools/check_server_deliverability_v2 --host '.$server->hostname.' --user '.$server->user.' --pass '.$server->password.' --port '.$server->port.' --uid '.$randid.' 2>&1';
        MailLog::info("Executing: $cmd");
        $retArr = null;
        $retVal = null;
        exec($cmd,$retArr, $retVal);
        foreach ($retArr as $output) {
            if (strpos($output,"JSON: ") > -1) {
                $output =  preg_replace('/JSON: /','',$output);
                MailLog::info("Deliverability script returned: $output");
                $json = @json_decode($output);
                if (isset($json->status) && $json->status > 0) {
                    return true;
                }
            }

        }

        return false;
    }

    public function test_server(Request $request) {
        if ($request->isMethod('post')) {
            $subject = "test mail";
            $text = "Just a test email";
            $to_email = $request->email;
            //MailLog::info("got server: ".print_r($request->serv,true));
            $server = (object)$request->serv;
            $randid = rand(33334234,2343245890435345);
            $html = "<html><content>$text</content></html>";
            $deployment = \Config::get('app.deployment');
            $global_tracking = Setting::get('global_tracking');
            $from_email = 'info@'.$global_tracking;
            $subject = $subject." ".base64_encode($server->hostname);

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

    public function inject_server(Request $request) {
        if ($request->isMethod('post')) {
            $server = (object)$request->serv;
            $deployment = $request->depl;
            if (\Redis::hexists('appliances',$deployment)) {
              $deployment_url = \Redis::hget('appliances',$deployment);
                // Normalize URL: tolerate missing trailing slash. Path must be /api/ (see Route::group prefix).
                $target_url = rtrim($deployment_url, '/') . '/postserver';
                MailLog::info("inject_server(): POST to ".$target_url);
                $ch = curl_init($target_url);

                if ($request->pmta > 0) {
                    $post = [
                        'tracking' => $server->tracking,
                        'sendaddress' => $server->sendaddress,
                        'server_name' => "$server->dns $server->hostname",
                        'server_host' => $server->hostname,
                        'server_user' => 'usr',
                        'server_pass' => '1235asdf421',
                        'server_port' => 2525,
                        'api_key' => 1122,
                    ];
                } else {
                    $post = [
                        'tracking' => $server->tracking,
                        'sendaddress' => $server->sendaddress,
                        'server_name' => "$server->dns $server->hostname",
                        'server_host' => $server->hostname,
                        'server_user' => $server->user,
                        'server_pass' => $server->password,
                        'server_port' => 2525,
                        'api_key' => 1122,
                    ];
                }

                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                $response = curl_exec($ch);
                $curl_err = curl_error($ch);
                $curl_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                MailLog::info("inject_server(): HTTP $curl_http, error='$curl_err', response: ".$response);
                curl_close($ch);

                // add server to the mta api
                try {
                    $url = \Config::get('app.mta');
                    if ($url != "") {
                    }
                } catch (\Exception $ex) {
                    MailLog::error("Cannot add server to the mta api...");
                }

                return $response;
            }

            MailLog::info('Inject_server to '.$deployment.' function got parameters: '.print_r($server,true));
        }
    }




    public function setup_server(Request $request) {
        // Initialize MailLog for HTTP context (normally only configured for queue workers)
        if (!MailLog::$logger) {
            MailLog::configure(storage_path().'/logs/setup_server.log');
        }
        $server = (object)$request->serv;
        MailLog::info('setup_server(): === SERVER SETUP START === params: '.print_r($server,true));
        $multi = $server->multi;
        $customer = $request->user()->customer->id;
        $warmup = $request->warmup ?? false;
        $warmup_name = $request->warmupname;
        $warmup_email = $request->warmupemail;
        if ($request->pmta > 0) {
            if (isset($request->domens)) $domens = $request->domens;
            else
                $domens = "";
            MailLog::info("setup_server(): PowerMTA mode - host: $server->hostname, domains: $domens, os: $server->os");
            if (preg_match('/redhat/', $server->os)) {
                MailLog::info("setup_server(): CentOS detected, running redhat_powermta...");
                $start_time = microtime(true);
                exec('$HOME/public_html/tools/redhat_powermta ' . $server->hostname . ' ' . $server->user . ' ' . $server->password . ' 1 2>&1', $retArr, $retVal);
                $elapsed = round(microtime(true) - $start_time, 2);
                MailLog::info("setup_server(): redhat_powermta completed in {$elapsed}s, exit: $retVal");
            } elseif (preg_match('/debian/', $server->os)) {
                MailLog::info("setup_server(): Debian detected, running debian_powermta...");
                $cmd = '$HOME/public_html/tools/debian_powermta ' . $server->hostname . ' ' . $server->user . ' ' . $server->password . ' ' . $domens . ' 2>&1';
                MailLog::info("setup_server(): cmd: $cmd");
                $start_time = microtime(true);
                exec($cmd, $retArr, $retVal);
                $elapsed = round(microtime(true) - $start_time, 2);
                MailLog::info("setup_server(): debian_powermta completed in {$elapsed}s, exit: $retVal");
            } else {
                $data = (object)array();
                $data->respond = "Not supported OS";
                $data->error = 1;
            }
            $data = (object)array();
            $data->warmupmsg = "";
            $error = 0;

            $str = '';
            foreach ($retArr as $output) {
                //$output = preg_replace('/\n/','',$output);
                $str .= $output . "\n";
            }
            $data->respond = $str;

            foreach ($retArr as $output) {
                if (strpos($output, "JSON: ") > -1) {
                    $output = preg_replace('/JSON: /', '', $output);
                    MailLog::info("Powermta install script returned: $output");
                    $json = @json_decode($output);
                    if (is_object($json)) {
                        // got some data
                        $data->reverses = $json;
                        $data->error = 0;
                        MailLog::info("Got some data from script: " . $output);

                    } else {
                        $error = 1;
                    }


                    MailLog::info("Got output json: " . $output);
                }
            }
//            $str = '';
//            foreach ($retArr as $output) {
//                //$output = preg_replace('/\n/','',$output);
//                $str .= $output."\n";
//            }
            if ($error > 0) {
                $data->repsond = $retArr;
                $data->error = 1;
            }
            $json = @json_encode($data);
            MailLog::info("Respond from powermta server setup is: " . $json);
            return $json;
        } else {
            if ($multi == 0) {
                MailLog::info("setup_server(): Single Postfix mode - host: $server->hostname, dns: $server->dns, os: $server->os");
                if (preg_match('/redhat/', $server->os)) {
                    MailLog::info("setup_server(): CentOS detected, running redhat_postfix...");
                    $start_time = microtime(true);
                    exec('$HOME/public_html/tools/redhat_postfix new ' . $server->hostname . ' ' . $server->dns . ' ' . $server->user . ' ' . $server->password . ' 1 2>&1', $retArr, $retVal);
                    $elapsed = round(microtime(true) - $start_time, 2);
                    MailLog::info("setup_server(): redhat_postfix completed in {$elapsed}s, exit: $retVal");
                } elseif (preg_match('/debian/', $server->os)) {
                    MailLog::info("setup_server(): Debian detected, running debian_postfix...");
                    $cmd = '$HOME/public_html/tools/debian_postfix new ' . $server->hostname . ' ' . $server->dns . ' ' . $server->user . ' ' . $server->password . ' 1 2>&1';
                    MailLog::info("setup_server(): cmd: $cmd");
                    $start_time = microtime(true);
                    exec($cmd, $retArr, $retVal);
                    $elapsed = round(microtime(true) - $start_time, 2);
                    MailLog::info("setup_server(): debian_postfix completed in {$elapsed}s, exit: $retVal");
                } else {
                    $data = (object)array();
                    $data->respond = "Not supported OS";
                    $data->error = 1;
                }
                $str = '';
                foreach ($retArr as $output) {
                    //$output = preg_replace('/\n/','',$output);
                    $str .= $output . "\n";
                }
                $data = (object)array();
                $data->respond = $str;
                $data->error = 0;
                $json = @json_encode($data);
                MailLog::info("setup_server(): === SINGLE POSTFIX SETUP END === response: " . $json);
                return $json;
            } else {
                // multiple ips server setup using postfix
                MailLog::info("setup_server(): Multi-Postfix mode starting...");
                /*$warmobj = new \Acelle\Model\Warmups();
                $warmobj->uid = uniqid();
                $warmobj->name = $warmup_name;
                $warmobj->email = $warmup_email;
                $warmobj->save();

                $warmobj->ips()->saveMany([
                    new \Acelle\Model\WarmupsIps(['uid'=>uniqid(),'ip_address' => '127.0.0.1']),
                    new \Acelle\Model\WarmupsIps(['uid'=>uniqid(),'ip_address' => '127.0.0.2']),
                ]);
                $ipasai = array();
                $ipasai[] = new \Acelle\Model\WarmupsIps(['uid' => uniqid(), 'ip_address' => '127.0.0.1']);
                $ipasai[] = new \Acelle\Model\WarmupsIps(['uid'=> uniqid(), 'ip_address' => '127.0.0.1']);
                $warmobj->ips()->saveMany($ipasai);

                die;
                */

                if (isset($request->domens)) $domens = $request->domens;
                else
                    $domens = "";
                MailLog::info("setup_server(): Multi-Postfix - host: $server->hostname, domains: $domens, os: $server->os, warmup: " . var_export($warmup, true));
                $retArr = array();
                $retVal = -1;
                if (preg_match('/redhat/', $server->os)) {
                    MailLog::info("setup_server(): CentOS detected, running redhat_multipostfix...");
                    $start_time = microtime(true);
                    exec('$HOME/public_html/tools/redhat_multipostfix ' . $server->hostname . ' ' . $server->user . ' ' . $server->password . ' 1 2>&1', $retArr, $retVal);
                    $elapsed = round(microtime(true) - $start_time, 2);
                    MailLog::info("setup_server(): redhat_multipostfix completed in {$elapsed}s, exit: $retVal, lines: " . count($retArr));
                } elseif (preg_match('/debian/', $server->os)) {
                    MailLog::info("setup_server(): Debian detected, running debian_multipostfix...");
                    $cmd = '$HOME/public_html/tools/debian_multipostfix ' . $server->hostname . ' ' . $server->user . ' ' . $server->password . ' ' . $domens . ' 2>&1';
                    MailLog::info("setup_server(): cmd: $cmd");
                    $start_time = microtime(true);
                    exec($cmd, $retArr, $retVal);
                    $elapsed = round(microtime(true) - $start_time, 2);
                    MailLog::info("setup_server(): debian_multipostfix completed in {$elapsed}s, exit: $retVal, lines: " . count($retArr));
                } else {
                    $data = (object)array();
                    $data->respond = "Not supported OS";
                    $data->error = 1;
                    MailLog::info("setup_server(): Unsupported OS: $server->os");
                }
                $data = (object)array();
                $error = 0;
                $str = '';
                foreach ($retArr as $output) {
                    $str .= $output . "\n";
                }
                MailLog::info("setup_server(): Script raw output:\n$str");
                $data->respond = $str;

                foreach ($retArr as $output) {
                    if (strpos($output, "JSON: ") > -1) {
                        $output = preg_replace('/JSON: /', '', $output);
                        MailLog::info("setup_server(): Multi postfix script returned JSON: $output");
                        $json = @json_decode($output);
                        if (is_object($json)) {
                            $data->reverses = $json;
                            $data->error = 0;
                            MailLog::info("setup_server(): Got reverses data from script: " . $output);
                        } else {
                            $error = 1;
                            MailLog::info("setup_server(): JSON decode FAILED for: $output");
                        }
                    }
                }
                if ($error > 0) {
                    $data->respond = $retArr;
                    $data->error = 1;
                    MailLog::info("setup_server(): Multi-Postfix FAILED - no valid JSON in output");
                }
                // here we should implement warmup
                // Fix: JS sends "false" as string, PHP "false" == true is true, use strict filter
                $warmup = filter_var($warmup, FILTER_VALIDATE_BOOLEAN);
                MailLog::info("setup_server(): warmup after filter: " . var_export($warmup, true) . ", reverses set: " . (isset($data->reverses) ? "yes" : "no"));
                if ($warmup === true && isset($data->reverses)) {
                    MailLog::info("warmup have been enabled for this setup");
                    // test phrase
                    $ipasai = array();
                    foreach ($data->reverses as $ipz => $reverse) {
                        $server->hostname = $ipz;
                        if ($this->warmup_server_test($customer,$server,$warmup_email)) {
                            $ipasai[] = new \Acelle\Model\WarmupsIps(['uid' => uniqid(), 'ip_address' => $ipz]);
                        }

                    }
                    // phrases
                    $phrases = array();
                    $phase_tmp = json_decode($request->phrases);
                    foreach ($phase_tmp as $id => $phrase) {
                        $phrases[] = new \Acelle\Model\WarmupsPhrases(['uid' => uniqid(), 'perh' => $phrase->perh, 'total' => $phrase->total, 'phrase' => $id]);
                    }
                    if (count($ipasai) > 0) {
                        $warmobj = new \Acelle\Model\Warmups();
                        $warmobj->uid = uniqid();
                        $warmobj->name = $warmup_name;
                        $warmobj->email = $warmup_email;
                        $warmobj->server_ip = $server->hostname;
                        $warmobj->server_pass = $server->password;
                        //$warmobj->status = "new";
                        $warmobj->save();
                        // assign ip's to the object
                        $warmobj->ips()->saveMany($ipasai);
                        // assiggn phrases to the object
                        $warmobj->phrases()->saveMany($phrases);
                        $data->warmupmsg = "Warmup pool with name $warmup_name have been setup with ".count($ipasai). " ip addresses";
                        // start the warmup process
                        $runner = New TaskRunner();
                        $runner->send_queue((int)501,$request->user()->customer->id,(int)1,json_encode(array('uid' => $warmobj->uid, 'type' => 1)));
                    } else {
                        MailLog::info("All ips for the warmup pool $warmup_name does not pass the tests... Aborting all warmup pool addition");
                        $data->warmupmsg = "All ips for the warmup pool $warmup_name does not pass the tests... Aborting all warmup pool addition";
                    }

                }


                $json = @json_encode($data);
                MailLog::info("Respond from multi server setup is: " . $json);
                return $json;
            }
        }
    }

    function setup_ips(Request $request) {
        $server = (object) array();
        $srv = $request->serv ?? "";
        $ips = $request->ipai ?? "";
        $data = (object)array();
        // extract server/user/password/port
        // the defaults
        $server->port = 22;
        $server->username = 'root';

        if ((strpos($srv, "@") > -1))
        {
            $dmp = explode("@", $srv);
            if (strpos($dmp[0], ":") > -1) {
                $dmp2 = explode(":", $dmp[0]);
                $server->username = $dmp2[0];
                $server->password = $dmp2[1];
            }
            if (strpos($dmp[1], ":") > -1) {
                $dmp3 = explode(":",$dmp[1]);
                $server->hostname = $dmp3[0];
                $server->port = $dmp3[1];
            } else {
                $server->hostname = $dmp[1];
            }

        }

        if ((strpos($srv, ":") > -1)) {
            $dmp = explode(":", $srv);
            if(isset($dmp[2])) {
                $server->port = $dmp[2];
            }
        } else {
            $server->port = 22;
        }
        exec('$HOME/public_html/tools/addmoreips --host '.$server->hostname.' --user '.$server->username.' --pass '.$server->password.' --port '.$server->port.' --ips "'.$ips.'"2>&1',$retArr, $retVal);
        foreach ($retArr as $output) {
            if (strpos($output, "JSON: ") > -1) {
                $output = preg_replace('/JSON: /', '', $output);
                MailLog::info("Server ip addition script: $output");
                $json = @json_decode($output);
                $data->respond = $json->respond ?? "";
                $data->error = $json->error ?? 1;
                MailLog::info("Got output json: " . $output);
            }
        }
        //$data->respond = "everything is ok";
        //$data->error = 0;
        $json = @json_encode($data);
        return $json;
    }


    function isDemoMode()
    {
        return parent::isDemoMode(); // TODO: Change the autogenerated stub
    }

    public function debug(Request $request) {

        return view('admin.settings.debug', [
        ]);
    }

    public function debug2(Request $request) {

        return view('admin.settings.debug2', [
        ]);
    }

    public function dns(Request $request) {

        $dns = new DNSHelper();
        $cloudflare = new CloudFlareHelper();
        $proxyip = $dns->get_proxyip();

        if (\Config::get('app.cloudflare_enabled') == true) {

            if ($request->isMethod('post') && strlen($request->input('domain_list')) > 1) {
                // darom domenu importa
                $domains2 = explode("\n", str_replace("\r", "", $request->input('domain_list')));
                MailLog::info("Mass insert dns received with: ".count($domains2)." records, processing... ".print_r($domains2,true));
                $cloudflare->import_domains($domains2);
            }

            $domains = "";
//            $domains = $cloudflare->get_domains();
//            if (isset($domains)) {
//                foreach ($domains as $domain) {
//                    $domain->dns = gethostbyname($domain->name);
//
//                }
//            }


        } else {

            if ($dns->Is_Enabled()) {

                if ($request->isMethod('post') && strlen($request->input('domain_list')) > 1) {
                    // darom domenu importa
                    $domains = explode("\n", str_replace("\r", "", $request->input('domain_list')));
                    $dns->import_domains($domains);
                }


                // nuskaitom jau sudetus domenus
                $domains = $dns->get_domains();
                if (isset($domains)) {
                    foreach ($domains as $domain) {
                        $domain->dns = gethostbyname($domain->name);

                    }
                }
            } else {
                $domains = null;
            }
        }

        return view('admin.settings.dns', [
            'domains' => $domains,
            'proxyip' => $proxyip,
        ]);
    }

    /**
     * Update all urls.
     *
     * @return \Illuminate\Http\Response
     */
    public function updateUrls(Request $request, $track)
    {
//echo $_GET['trackurl'];
//echo $track;
//print_r($_GET);
// update stack
/*$db = "mailsendas_test";
$dbuser = "ems";
$dbpass = "bGh9CaF897q";
$dbhost = "127.0.0.1";
$dbport = "3306";
$db = new \mysqli($dbhost, $dbuser, $dbpass, $db, $dbport);
$sql = "SELECT * from campaigns";
*/
if ($track != "") {
//$result = $db->query($sql);
//    $result = DB::table('campaigns')->get();
//    foreach ($result as $it) {
//        if (strpos($it->from_email, '@') !== false && strpos($it->reply_to, '@') !== false) { // check if string contains @
//            list($usr, $domain) = explode('@', $it->from_email, 2);
//            list($usr2, $domain2) = explode('@', $it->reply_to, 2);
//            $from = $usr . "@" . $track;
//            $reply = $usr2 . "@" . $track;
//            $htmlas = $it->html;
//            $plainas = $it->plain;
//            $htmlas = preg_replace("/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/css?/", 'http://' . $track . '/css', $htmlas);
//            $htmlas = preg_replace("/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/click?/", 'http://' . $track . '/click', $htmlas);
//            $htmlas = preg_replace("/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/source?/", 'http://' . $track . '/source', $htmlas);
//            $htmlas = preg_replace("/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/images?/", 'http://' . $track . '/images', $htmlas);
//            $htmlas = preg_replace("/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/?/", 'http://'.$track.'/', $htmlas);
//            $plainas = preg_replace("/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/css?/", 'http://' . $track . '/css', $plainas);
//            $plainas = preg_replace("/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/click?/", 'http://' . $track . '/click', $plainas);
//            $plainas = preg_replace("/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/source?/", 'http://' . $track . '/source', $plainas);
//            $plainas = preg_replace("/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/images?/", 'http://' . $track . '/images', $plainas);
//            // global replace
//            $plainas = preg_replace("/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/?/", 'http://'.$track.'/', $plainas);
//            $html = $htmlas;
//            $plain = $plainas;
 //           \DB::table('campaigns')->where('uid', $it->uid)->where('trackurl', '=', '')->orWhereNull('trackurl')->update(['html' => $html, 'plain' => $plain, 'from_email' => $from, 'reply_to' => $reply]);
//        \DB::table('campaigns')->where('uid',$it->uid)->update(['html' => $html, 'plain' => $plain]);
 //       } // end str pos
//}
    $error = 0;
    try {
        if (\Config::get('app.dns_enabled') == true) {
            $dns = new DNSHelper();
            $domain = $track;
            trim($domain);
            if (substr($domain, -1) !== '.') $domain = $domain . ".";
            if ($dns->Is_Enabled() && $dns->domain_exists($domain) == false) {
                $dns->import_domains(["$domain"]);

            }
            if ($dns->Is_Enabled()) $dns->set_tracking_domain($track);
        }
    } catch (\Exception $ex) {
        MailLog::error("Got error in setting dns records for domain $track");
    }





     // remove that point from the end of the tracking domain
        if(substr($track, -1) === '.') $track = substr($track, 0, -1);
        trim($track);
        if (!isset($domain)) $domain = $track;

try {
    if (\Config::get('app.cloudflare_enabled') == true) {
        $cloudflare = new CloudFlareHelper();
        if ($cloudflare->import_domains(array($track))) $error = 0;
        else $error = 1;
        // proxy support
        if (\Redis::exists("proxy_wide") && \Redis::get("proxy_wide") == "1" && \Redis::exists("proxy_default") && \Redis::get("proxy_default") != "") {
            $proxy_ip = \Redis::get("proxy_default");
            MailLog::info("Proxy have been enabled, setting manual ip: $proxy_ip for domain: $track");
            if (\Config::get('app.cloudflare_enabled') == true) {
               $cloudflare = new CloudFlareHelper();
               $cloudflare->manually_set($track,$proxy_ip);
            }
        }
    }

} catch (\Exception $ex ) {
    MailLog::error("Got error in setting cloudflare records for domain $track");
}

        \DB::table('nustatymai')->where('id', 1)->update(['reiksm' => $track]);
        \DB::table('settings')->where('id', 23)->update(['value' => $track]);
        \Redis::set('tracking_url',$domain);
    // pakeiciam tracking info visuose campaigns
        $result = DB::table('campaigns')->get();
        foreach ($result as $it) {
            $campaign = \Acelle\Model\Campaign::findByUid($it->uid);
            @list($usr, $domn) = explode('@', $it->from_email, 2);
            $domn = $track;
            $campaign->from_email = $usr . '@' . $domn;
            if ($campaign->trackurl != "") $campaign->trackurl = $domn;
            $campaign->save();
            // update redis
            if (Redis::exists($it->uid)) {
                $json = json_decode(Redis::get($it->uid));
                $json->from_email = $usr . '@' . $domn;
                if ($json->trackurl != "") $json->trackurl = $domn;
                Redis::set($it->uid, json_encode($json));
            }
        }
        if ($error == 0) $request->session()->flash('alert-success', trans('messages.setting.updated'));
        else $request->session()->flash('alert-success', "Got some error then updating the DNS entries!");

}

//die;
        if ($request->user()->admin->getPermission('setting_system_urls') != 'yes') {
 //           return $this->notAuthorized();
        }
        // Redirect to my lists page





        return redirect()->action('Admin\SettingController@urls');
    }

    /**
     * View system logs.
     *
     * @return \Illuminate\Http\Response
     */
    public function logs(Request $request)
    {
        $path = base_path("artisan");
        $lines = 300;

        $error_logs = "";
        $file = file($path);
        for ($i = max(0, count($file)-$lines); $i < count($file); $i++) {
          $error_logs .= $file[$i];
        }

        return view('admin.settings.logs', [
            'error_logs' => $error_logs,
        ]);
    }

    /**
     * View system logs.
     *
     * @return \Illuminate\Http\Response
     */
    public function download_log(Request $request)
    {
        $path = storage_path("logs/" . $request->file);

        return response()->download($path);
    }

    /**
     * License settings.
     *
     * @return \Illuminate\Http\Response
     */
    public function license(Request $request)
    {
        if ($request->user()->admin->getPermission('setting_general') != 'yes') {
            return $this->notAuthorized();
        }

        // \Acelle\Model\Setting::updateAll();
        $settings = \Acelle\Model\Setting::getAll();
        if (null !== $request->old()) {
            foreach ($request->old() as $name => $value) {
                if (isset($settings[$name])) {
                    $settings[$name]['value'] = $value;
                }
            }
        }

        // validate and save posted data
        if ($request->isMethod('post')) {

            if($this->isDemoMode()) {
                return $this->notAuthorized();
            }

            try {
                // Update license type
                \Acelle\Model\Setting::updateLicense($request->license);

                // Save settings
                foreach ($request->all() as $name => $value) {
                    if ($name != '_token' && isset($settings[$name])) {
                        \Acelle\Model\Setting::set($name, $value);
                    }
                }

                // Redirect to my lists page
                $request->session()->flash('alert-success', trans('messages.license.updated'));
                return redirect()->action('Admin\SettingController@license');
            } catch (\Exception $ex) {
                $license_error = trans("messages.something_wrong_with_license_check", ['error' => $ex->getMessage()]);
            }
        }

        return view('admin.settings.license', [
            'settings' => $settings,
            'current_license' => \Acelle\Model\Setting::get('license'),
            'license_error' => isset($license_error) ? $license_error : '',
        ]);
    }

    /**
     * Upgrade manager page
     *
     * @return \Illuminate\Http\Response
     */
    public function upgrade(Request $request)
    {
        if ($request->user()->admin->getPermission('setting_upgrade_manager') != 'yes') {
            return $this->notAuthorized();
        }

        $manager = new UpgradeManager();
        return view('admin.settings.upgrade', [
            'any' => 'any',
            'manager' => $manager,
        ]);
    }

    /**
     * Upgrade manager page
     *
     * @return \Illuminate\Http\Response
     */
    public function doUpgrade(Request $request)
    {
        if ($request->user()->admin->getPermission('setting_upgrade_manager') != 'yes') {
            return $this->notAuthorized();
        }

        $manager = new UpgradeManager();
        $failed = $manager->test();
        if (empty($failed)) {
            $pageUrl = action('Admin\SettingController@upgrade');
            $manager->run();
            $request->session()->flash('alert-success', trans('messages.upgrade.alert.upgrade_success'));
            Log::info('System successfully upgraded to the new version');
            return redirect()->away($pageUrl);
        } else {
            Log::warning('Cannot upgrade, certain files are not writable');
            return view('admin.settings.upgrade', [
                'any' => 'any',
                'manager' => $manager,
                'failed' => $failed,
            ]);
        }
    }

    /**
     * Cancel upgrade and delete the uploaded file
     *
     * @return \Illuminate\Http\Response
     */
    public function cancelUpgrade(Request $request)
    {
        if ($request->user()->admin->getPermission('setting_upgrade_manager') != 'yes') {
            return $this->notAuthorized();
        }

        try {
            $manager = new UpgradeManager();
            $manager->cleanup();
            $request->session()->flash('alert-info', trans('messages.upgrade.alert.cancel_success'));
        } catch (\Exception $e) {
            Log::info("Something went wrong while cancelling upgrade. " . $e->getMessage());
            $request->session()->flash('alert-error', $e->getMessage());
        }

        return redirect()->action('Admin\SettingController@upgrade');
    }

    /**
     * Upload the application patch
     *
     * @return \Illuminate\Http\Response
     */
    public function uploadApplicationPatch(Request $request)
    {
        if ($request->user()->admin->getPermission('setting_upgrade_manager') != 'yes') {
            return $this->notAuthorized();
        }

        try {
            $manager = new UpgradeManager();
            $manager->load($request->file('file')->path());
            $request->session()->flash('alert-success', trans('messages.upgrade.alert.upload_success'));
        } catch (\Exception $e) {
            Log::info("Upgrade failed. " . $e->getMessage());
            $request->session()->flash('alert-error', $e->getMessage());
        }

        return redirect()->action('Admin\SettingController@upgrade');
    }
}
