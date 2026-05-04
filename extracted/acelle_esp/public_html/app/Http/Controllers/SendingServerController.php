<?php

namespace Acelle\Http\Controllers;

use Acelle\Library\CloudFlareHelper;
use Acelle\Library\TaskRunner;
use Acelle\Model\Log;
use Illuminate\Http\Request;
use Acelle\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Library\StringHelper;
use Acelle\Model\Setting;
use Redis;
use Acelle\Library\Log as MailLog;

class SendingServerController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        parent::__construct();
//LaravelLog::info("sending server controller");
        $this->middleware('auth');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        if (!$request->user()->customer->can('read', new \Acelle\Model\SendingServer())) {
            return $this->notAuthorized();
        }

        $request->merge(array("customer_id" => $request->user()->customer->id));
        $items = \Acelle\Model\SendingServer::search($request);

        return view('sending_servers.index', [
            'items' => $items,
        ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listing(Request $request)
    {
        if (!$request->user()->customer->can('read', new \Acelle\Model\SendingServer())) {
            return $this->notAuthorized();
        }

        $request->merge(array("customer_id" => $request->user()->customer->id));
        $items = \Acelle\Model\SendingServer::search($request)->paginate($request->per_page);

        return view('sending_servers._list', [
            'items' => $items,
        ]);
    }

    /**
     * Select sending server type.
     *
     * @return \Illuminate\Http\Response
     */
    public function select(Request $request)
    {
        return view('sending_servers.select');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $server = new \Acelle\Model\SendingServer();
        $server->status = 'active';
        $server->uid = '0';
        $server->quota_value = '1000';
        $server->quota_base = '1';
        $server->quota_unit = 'hour';
        $server->type = $request->type;
        $server->servgroup = $request->servgroup;
        $server->fill($request->old());

        // authorize
        if (!$request->user()->customer->can('create', $server)) {
            return $this->notAuthorized();
        }

        $tracking = new \stdClass();
        $tracking->sendaddress = "";
        $tracking->tracking = "";
        if ($server->type == "smtp") {
            $ip = $server->host;
            if (\Redis::exists("server_".$ip."_sendaddress")) {
                $tracking->sendaddress = \Redis::get("server_".$ip."_sendaddress");
            }
            if (\Redis::exists("server_".$ip."_tracking")) {
                $tracking->tracking = \Redis::get("server_".$ip."_tracking");
            }
        }



        return view('sending_servers.create', [
            'server' => $server,
            'tracking' => $tracking,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        // Get current user
        $current_user = $request->user();
        $server = new \Acelle\Model\SendingServer();
        $server->type = $request->type;

        // authorize
        if (!$request->user()->customer->can('create', $server)) {
            return $this->notAuthorized();
        }

        // save posted data
        if ($request->isMethod('post')) {
            $this->validate($request, \Acelle\Model\SendingServer::rules($request->type));

            // Save current user info
            $server->fill($request->all());
            $server->customer_id = $request->user()->customer->id;
            $server->status = 'active';

            // bounce / feedback hanlder nullable
            if (empty($request->bounce_handler_id)) {
                $server->bounce_handler_id = null;
            }
            if (empty($request->feedback_loop_handler_id)) {
                $server->feedback_loop_handler_id = null;
            }

            if (!empty($request->servgroup)) {
                $server->servgroup = $request->servgroup;
            } else {
                $server->servgroup = 0;
            }

            if ($request->type == "smtp") {
                $ip = $request->host;
                if ($request->sendaddress != "") {
                    \Redis::set("server_".$ip."_sendaddress",$request->sendaddress);
                } else {
                    \Redis::del("server_".$ip."_sendaddress");
                }
                if ($request->tracking != "") {
                    \Redis::set("server_".$ip."_tracking",$request->tracking);
                } else {
                    \Redis::del("server_" . $ip . "_tracking");
                }
            }




            $server->save();
                // Log
                $server->log('created', $request->user()->customer);

                $request->session()->flash('alert-success', trans('messages.sending_server.created'));
                return redirect()->action('SendingServerController@index');

        }
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, $id)
    {
        $server = \Acelle\Model\SendingServer::findByUid($id);

        // authorize
        if (!$request->user()->customer->can('update', $server)) {
            return $this->notAuthorized();
        }
        $tracking = new \stdClass();
        $tracking->sendaddress = "";
        $tracking->tracking = "";
        if ($server->type == "smtp") {
            $ip = $server->host;
            if (\Redis::exists("server_".$ip."_sendaddress")) {
                $tracking->sendaddress = \Redis::get("server_".$ip."_sendaddress");
            }
            if (\Redis::exists("server_".$ip."_tracking")) {
                $tracking->tracking = \Redis::get("server_".$ip."_tracking");
            }
        }

        $server->fill($request->old());

        return view('sending_servers.edit', [
            'server' => $server,
            'tracking' => $tracking,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        // Get current user
        $current_user = $request->user();
        $server = \Acelle\Model\SendingServer::findByUid($id);

        // authorize
        if (!$request->user()->customer->can('update', $server)) {
            return $this->notAuthorized();
        }

        // save posted data
        if ($request->isMethod('patch')) {
            $this->validate($request, \Acelle\Model\SendingServer::rules($request->type));

            // Save current user info
            $server->fill($request->all());

            // bounce / feedback hanlder nullable
            if (empty($request->bounce_handler_id)) {
                $server->bounce_handler_id = null;
            }
            if (empty($request->feedback_loop_handler_id)) {
                $server->feedback_loop_handler_id = null;
            }

            if (!empty($request->servgroup)) {
                $server->servgroup = $request->servgroup;
            } else {
                $server->servgroup = 0;
            }

            if ($request->type == "smtp") {
                $ip = $request->host;
                if ($request->sendaddress != "") {
                    \Redis::set("server_".$ip."_sendaddress",$request->sendaddress);
                } else {
                    \Redis::del("server_".$ip."_sendaddress");
                }
                if ($request->tracking != "") {
                    \Redis::set("server_".$ip."_tracking",$request->tracking);
                } else {
                    \Redis::del("server_" . $ip . "_tracking");
                }
            }


            $server->save();
                // Log
                $server->log('updated', $request->user()->customer);
                $request->session()->flash('alert-success', trans('messages.sending_server.updated'));
                return redirect()->action('SendingServerController@index');

        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
    }

    /**
     * Custom sort items.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function sort(Request $request)
    {
        $sort = json_decode($request->sort);
        foreach ($sort as $row) {
            $item = \Acelle\Model\SendingServer::findByUid($row[0]);

            // authorize
            if (!$request->user()->customer->can('update', $item)) {
                return $this->notAuthorized();
            }

            $item->custom_order = $row[1];
            $item->save();
        }

        echo trans('messages.sending_server.custom_order.updated');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function delete(Request $request)
    {
        $items = \Acelle\Model\SendingServer::whereIn('uid', explode(',', $request->uids));

        foreach ($items->get() as $item) {
            // authorize
            if ($request->user()->customer->can('delete', $item)) {
                // Log
                $item->log('deleted', $request->user()->customer);

                $item->delete();
            }
        }

        // Redirect to my lists page
        echo trans('messages.sending_servers.deleted');
    }

    public function export_servers(Request $request)
    {

        if (isset($request->uids)) {
            if ($request->uids == "all") {
                // all servers
                LaravelLog::info("visi uidai");
                $items = \Acelle\Model\SendingServer::getAll();
                //LaravelLog::info("Servai: ".print_r($items,true));

                //return "ok";
            }  elseif (isset($request->uids)) {
                $items = \Acelle\Model\SendingServer::whereIn('uid', explode(',', $request->uids));
             LaravelLog::info("array: ".print_r($request->uids,true));

            } else {
                // multiple selected servers
                $items = \Acelle\Model\SendingServer::whereIn('uid', $request->uid);
                LaravelLog::info("uidas: " . $request->uid);
                //return "ok";
            }
        } else {
            LaravelLog::info("parameter not passed".$request->id);
            //return "ok";
        }
        if (is_object($items)) {
            LaravelLog::info("listas yra object");

        }
        return "ok";
    }

    /**
     * Disable sending server.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function disable(Request $request)
    {
        $items = \Acelle\Model\SendingServer::whereIn('uid', explode(',', $request->uids));

        foreach ($items->get() as $item) {
            // authorize
            if ($request->user()->customer->can('disable', $item)) {
                $item->disable();
            }
        }

        // Redirect to my lists page
        echo trans('messages.sending_servers.disabled');
    }

    /**
     * Disable sending server.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function enable(Request $request)
    {
        $items = \Acelle\Model\SendingServer::whereIn('uid', explode(',', $request->uids));

        foreach ($items->get() as $item) {
            // authorize
            if ($request->user()->customer->can('enable', $item)) {
                $item->enable();
            }
        }

        // Redirect to my lists page
        echo trans('messages.sending_servers.enabled');
    }

    /*
     *  This function allows to move servers to another app deployment
     *
     */

    public function moveservers(Request $request)
    {
        $items = \Acelle\Model\SendingServer::wherein('uid',explode(',',$request->uids));
        $deployment = $request->deployment;
        if (!empty($deployment)&&$deployment != "") {
            $api = $deployment."/api/postserver";


            foreach ($items->get() as $item) {
                LaravelLog::info("Moving server: " . $item->name . " to deployment: " . $deployment);
                $data = [ 'api_key' => 1122, 'server_name' => $item->name,'server_host' => $item->host,'server_user' => $item->smtp_username,'server_pass' => $item->smtp_password,'server_port' => $item->smtp_port ];
                $ch = curl_init($api);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json'
                ));
                $data_string = json_encode($data);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $response = curl_exec($ch);
                curl_close($ch);
                LaravelLog::info("Response from server addition api: ".$response);
               // $r = json_decode($response);
                // Do we need to delete the server locally ?
                // $item->delete();
            }
            echo "Servers are moved!";
        } else {
            echo "You have not defined any deployment to move on to!";
        }
    }



    private function testservers($customer, $server,$from_email,$to_email,$subject,$text)
    {
        try {
            $subject = $subject." ".$server->host;
            $taskrunner = New TaskRunner();
            $send_data = array('server_ip' => $server->host, 'port' => (int)$server->smtp_port, 'from_email' => $from_email,'to_email' => $to_email, 'subject' => $subject, 'body' => $text);
            $smtp_request = json_encode($send_data);
            $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_SMTP_SEND,$customer,$taskrunner::PRIORITY_HIGH,$smtp_request);
            LaravelLog::info('Sending test mail via: ' . $server->name);
        } catch (\Exception $ex) {
            LaravelLog::error('fail at testserver function in sending server controller: '.$ex);
        }

    }

    /**
     * Test Sending server.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $id
     *
     * @return \Illuminate\Http\Response
     */
    public function test(Request $request, $uid)
    {
        $customer = $request->user()->customer->id;
        $global_tracking = Setting::get('global_tracking');
        $all_servers = 0;
        $servers = \Acelle\Model\SendingServer::getAll()->get();
        if ($uid == "all") $all_servers = 1;
        // Get current user
//        $current_user = $request->user();

        // Fill new server info
        if ($all_servers > 0) {
            $temp = [ "uid" => 'all'];
            $server = (object)$temp;
            LaravelLog::info('test');
        } else {
            if ($uid) {
                $server = \Acelle\Model\SendingServer::findByUid($uid);
            } else {
                $server = new \Acelle\Model\SendingServer();
                $server->uid = 0;
            }

            $server->fill($request->all());
            $server->type = $request->type;
        }

        // authorize
//        if (!$current_user->customer->can('test', $server)) {
//            return $this->notAuthorized();
//        }

        if ($request->isMethod('post')) {
            // @todo testing method and return result here. Ex: echo json_encode($server->test())
            try {
                if ($all_servers > 0) {
//LaravelLog::info("servai: ".print_r($servers[0]->uid,true));
                foreach ($servers as $dot) {
                    $serv = \Acelle\Model\SendingServer::findByUid($dot->uid);
                 //   if ($serv->host == "69.197.161.254") {
                        $this->testservers($customer, $serv, $request->from_email, $request->to_email, $request->subject, $request->content);
                        LaravelLog::info('Sending from: '.$serv->host);
                 //       break;
                  //  }
//                    $serv->type = 'smtp';
//                    $serv->sendTestEmail(array(
//                        'from_email' => $request->from_email,
//                        'to_email' => $request->to_email,
//                        'subject' => $request->subject,
//                        'plain' => $request->content
//                    ));
                }


                } else {
                    $server->sendTestEmail(array(
                        'from_email' => $request->from_email,
                        'to_email' => $request->to_email,
                        'subject' => $request->subject,
                        'plain' => $request->content
                    ));
                }
            } catch (\Exception $ex) {
                echo json_encode([
                    'status' => 'error', // or success
                    'message' => $ex->getMessage()
                ]);
                return;
            }

            echo json_encode([
                'status' => 'success', // or success
                'message' => trans('messages.sending_server.test_success')
            ]);
            return;
        }

        return view('sending_servers.test', [
            'server' => $server,
            'trackingas' => $global_tracking,
        ]);
    }

    private function generateRandomString($length = 10) {
        return substr(str_shuffle(str_repeat($x='bcdefghijklmnopqrstuvwxyz', ceil($length/strlen($x)) )),1,$length);
    }

    private function generateRandomId($length = 10) {
        return substr(str_shuffle(str_repeat($x='0123456789', ceil($length/strlen($x)) )),1,$length);
    }

    // there are uids for this function fixed in 2021.12.28
    public function changedns(Request $request)
    {

        $uids = "";
        if (!$request->isMethod('post')) {
            $uids = implode(",", $request->ids);;
        }

        if ($request->isMethod('post')) {
            try {
                if ($request->uids != "") {
                    $items = \Acelle\Model\SendingServer::whereIn('uid', explode(",", $request->uids));
                    foreach ($items->get() as $item) {
                        MailLog::info("Got server: ".$item->name." ".$item->host);
                        $oldname = $item->name;
                        $domain = $request->domain;
                        // setup dns
                        $rand_dns_name = $this->generateRandomString(2);
                        $rand_dns_id = $this->generateRandomId(2);
                        $random_dns = $rand_dns_name.$rand_dns_id;
                        $json = (object) array();
                        $cloudflare = new CloudFlareHelper();
                        $doms = $cloudflare->get_domains($domain);
                        $id = null;
                        foreach ($doms as $dom) {
                            $id = $dom->id;
                        }
                        try {
                            $cloudflare->createDNSRecord($id, array('type' => 'A', 'name' => $random_dns, 'content' => $item->host, 'proxied' => false));
                        } catch (\Exception $ex) {
                            MailLog::error("Unable to preprocess cloudflare record $random_dns.$domain");
                        }
                        $final_dns = "$random_dns.$domain";
                        // pass request to change postfix config (optional - only works if minisvc is running)
                        try {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, "http://$item->host:4591/chgdns");
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS,
                                "auth_api=112233&ip=$item->host&dns=$final_dns&url=localhost");
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                            $server_output = curl_exec($ch);
                            if ($server_output === false) {
                                MailLog::info("Remote API not available on $item->host:4591 (minisvc not running), skipping postfix config update");
                            }
                            curl_close($ch);
                        } catch (\Exception $ex) {
                            MailLog::error("Unable to contact remote api for postfix server $item->host ($item->name)");
                        }
                        // change local record
                        $item->name = "$final_dns $item->host";
                        $item->save();
                        MailLog::info("Server $oldname have been changed to $final_dns");
                    }

                }

            } catch (\Exception $ex) {
                echo json_encode([
                    'status' => 'error', // or success
                    'message' => $ex->getMessage()
                ]);
                return;
            }

            echo json_encode([
                'status' => 'success', // or success
                'message' => 'Information have been processed!'
            ]);
            return;
        }

        return view('sending_servers.changedns', [
            'uids' => $uids
        ]);
    }
}
