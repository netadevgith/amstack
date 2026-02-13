<?php

namespace Acelle\Http\Controllers;

use Acelle\Library\TaskRunner;
use Acelle\Model\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Acelle\Model\MailList;
use Acelle\Model\EmailVerificationServer;
use Acelle\Library\Log as MailLog;
use Illuminate\Support\Facades\Route;
use SendGrid\Mail;
use Redis;

class MailListController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        parent::__construct();

        $this->middleware('auth', [
            'except' => [
                'embeddedFormSubscribe',
                'embeddedFormCaptcha',
                'checkEmail',
            ]
        ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $customer = $request->user()->customer;


        return view('lists.index');
    }

    public function deletebydomain(Request $request)
    {
        // FIXME OPTIMIZE DELETE BY LIMIT
        MailLog::info("okay");
        if ($request->method() == "POST" && $request->domain = "selected") {
            $sql = "DELETE FROM subscribers WHERE email like '%";
            $sql .= implode("' OR email like '%", $request->domains);
            \DB::statement($sql."' and mail_list_id = $request->uid");
            print "Done!";
        } else {
            \DB::statement("DELETE FROM subscribers WHERE email like '%" . $request->domain . "' AND mail_list_id = $request->id");
            print "paprastas metodas";
            MailLog::info("paprastas metodas");
            return redirect('lists/'.$request->uid.'/overview');
        }

    }

    public function openersbyprovider(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->uid);
    if (is_object($list)) {
        echo '<table class="panel-body text-center" style="background-color: #27a294; color: #ffffff">';
        $countas = 0;
    foreach ($list->ByProviderSubscribers() as $provider) {
        $countas++;
        $country = strstr($provider->domain, '.');
        $country = str_replace(".", "", $country);
        $country = strtoupper($country);
        if ($countas % 2 == 0) {
            echo '<tr>
                <td><input type="checkbox" name="domains[]" value="' . $provider->domain . '" /></td>
                <td> <img src="/images/flags/' . $country . '.png"></td>
                <td width="70%" class="text-semibold mb-10 mt-0">' . $provider->domain . '</td><td width="10%">' . $provider->count . '</td>
                <td width="10%"><a href="/lists/deletebydomain/' . $list->id . '/' . $provider->domain . '"><i class="icon-trash"></i></a></td>
            </tr>';
        } else {
            echo '<tr>
        <td><input type="checkbox" name="domains[]" value="' . $provider->domain . '" /></td>
        <td> <img src="/images/flags/' . $country . '.png"></td>
    <td width="70%" class="text-semibold mb-10 mt-0">' . $provider->domain . '</td><td width="10%">' . $provider->count . '</td></p>
        <td width="10%"><a href="/lists/deletebydomain/' . $list->id . '/' . $provider->domain . '"><i class="icon-trash"></i></a></td>
    </tr>';
        }
    }
    echo '</table>';
    echo '<input type="button" value="Delete selected" onclick="deletas_domenu()"/>';

} else {
        echo '<h1>No data</h1>';
    }

    }

    public function setserver(Request $request)
    {
       // $prev = redirect()->getTargetUrl()->previous();
        $rec = \DB::table('mail_lists_sending_servers')->where('mail_list_id', $request->mail_list_id)->first();

        if (!is_null($rec)) {
            // delete old records
            //\DB::select(\DB::raw("DELETE FROM mail_lists_sending_servers where mail_list_id = '$request->mail_list_id'"));
            \DB::table('mail_lists_sending_servers')->where('mail_list_id', $request->mail_list_id)->delete();
        }


        // add new
        \DB::table('mail_lists_sending_servers')->insert([
            ['sending_server_id' => $request->server_id, 'mail_list_id' => $request->mail_list_id, 'fitness' => '2000000']
        ]);
        //\DB::select(\DB::raw("INSERT INTO mail_lists_sending_servers (sending_server_id,mail_list_id,fitness) VALUES('$request->server_id','$request->mail_list_id','2000000')"));
//        return redirect($prev);
        return redirect('/lists');



    }


    public function contacts(Request $request)
    {

        $perPage = $request->input("per_page", 500);
        $page = $request->input("page", 0);
       // $skip = $page * $perPage;
      //  if($take < 1) { $take = 1; }
        //if($skip < 0) { $skip = 0; }

//        $cluster   = \Cassandra::cluster()                 // connects to localhost by default
//        ->withCredentials(\Config::get('database.connections')['cassandra']['username'], \Config::get('database.connections')['cassandra']['password'])
//            ->build();
//        $keyspace  = \Config::get('database.connections')['cassandra']['database'];
//        $session   = $cluster->connect($keyspace);        // create session, optionally scoped to a keyspace
//        $statement = new \Cassandra\SimpleStatement(       // also supports prepared and batch statements
//            'SELECT message_id, campaign_id FROM tracking_logs'
//        );
//        $future    = $session->executeAsync($statement);  // fully asynchronous and easy parallel execution
//        $result    = $future->get();                      // wait for the result, with an optional timeout
//
//        foreach ($result as $row) {                       // results and rows implement Iterator, Countable and ArrayAccess
//            printf("The keyspace %s has a table called %n\n", $row['message_id'], $row['campaign_id']);
//        }


        $customer = $request->user()->customer;

        // TODO impl sorting/filtering 2018.05.23
        $sort_sql = "";
        $search_sql ="";
        $order = "";

        if ($request->input('sort-order') != "") {
            if ($request->input('sort-order') == "status") {
                $sort_sql = "";
                $order = " order by status desc";
            } elseif ($request->input('sort-order') == "blacklisted") {
                $sort_sql = " inner join blacklists on subscribers.email = blacklists.email";
                $order = "";
            } else {
                $sort_sql = " WHERE subscribers.status = '" . $request->input('sort-order') . "'";
                $order = " order by status";
            }
        }

        if ($request->input('search_keyword') != "") {
            if (strpos($sort_sql, 'WHERE') !== false)
                $search_sql = " AND";
            else
                $search_sql = " WHERE";
            $search_sql .= " subscribers.email like '%".$request->input('search_keyword')."%'";

        }
        MailLog::info("filter sql: ".$sort_sql." search sql: ".$search_sql. " order: ".$order);
        $selectas = "";
        if (strpos($sort_sql, 'blacklists') !== false) $selectas = "blacklists.email as blacklistas,";
        $list = \DB::select(\DB::raw("select $selectas tracking_logs.message_id,subscribers.email,subscribers.status,subscribers.opened_at,subscribers.created_at,subscribers.updated_at,mail_lists.uid as maillist_uid,subscribers.uid as suid,name from subscribers inner join mail_lists on subscribers.mail_list_id = mail_lists.id left join tracking_logs on subscribers.id = tracking_logs.subscriber_id $sort_sql $search_sql $order limit $page,$perPage"));
        $count = \DB::select(\DB::raw("select count(*) as countas from subscribers $sort_sql $search_sql $order"))[0]->countas;
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator($list, $count, $perPage, $page);
        //MailLog::info(print_r($list,true));

        $count_openers = collect(\DB::select("select count(*) as countas from open_logs left join tracking_logs ON open_logs.message_id = tracking_logs.message_id inner join subscribers on tracking_logs.subscriber_id = subscribers.id"))->first()->countas;
        $count_hardbounces = collect(\DB::select("select count(*) as countas from subscribers inner join mail_lists on subscribers.mail_list_id = mail_lists.id where subscribers.status = 'unconfirmed'"))->first()->countas;

        return view('lists.contacts', [
            'lists' => $list,
            'openers' => $count_openers,
            'hardbounces' => $count_hardbounces,
            'paginator' => $paginator,
        ]);


    }



    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listing(Request $request)
    {
        $lists = \Acelle\Model\MailList::search($request)->paginate($request->per_page);


        return view('lists._list', [
            'lists' => $lists,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        // Generate info
        $customer = $request->user()->customer;
        $list = new \Acelle\Model\MailList(['all_sending_servers' => true]);
        $list->contact = new \Acelle\Model\Contact();

        if (is_object($customer->contact)) {
            $list->contact->fill($customer->contact->toArray());
            $list->send_to = $customer->contact->email;
        } else {
            $list->send_to = $customer->user->email;
        }

        // default values
        $list->subscribe_confirmation = true;
        $list->send_welcome_email = true;
        $list->unsubscribe_notification = true;

        // authorize
        if (\Gate::denies('create', $list)) {
            return $this->noMoreItem();
        }

        // Get old post values
        if (null !== $request->old()) {
            $list->fill($request->old());
        }
        if (isset($request->old()['contact'])) {
            $list->contact->fill($request->old()['contact']);
        }

        // Sending servers
        if (isset($request->old()['sending_servers'])) {
            $list->mailListsSendingServers = collect([]);
            foreach ($request->old()['sending_servers'] as $key => $param) {
                if ($param['check']) {
                    $server = \Acelle\Model\SendingServer::findByUid($key);
                    $row = new \Acelle\Model\MailListsSendingServer();
                    $row->mail_list_id = $list->id;
                    $row->sending_server_id = $server->id;
                    $row->fitness = $param['fitness'];
                    $list->mailListsSendingServers->push($row);
                }
            }
        }

        return view('lists.create', [
            'list' => $list,
        ]);
    }

    public function createopeners(Request $request)
    {
        // Generate info
        $customer = $request->user()->customer;
        $list = new \Acelle\Model\MailList(['all_sending_servers' => true]);
        $list->contact = new \Acelle\Model\Contact();

        if (is_object($customer->contact)) {
            $list->contact->fill($customer->contact->toArray());
            $list->send_to = $customer->contact->email;
        } else {
            $list->send_to = $customer->user->email;
        }

        // default values
        $list->subscribe_confirmation = true;
        $list->send_welcome_email = true;
        $list->unsubscribe_notification = true;

        // authorize
        if (\Gate::denies('create', $list)) {
            return $this->noMoreItem();
        }

        // Get old post values
        if (null !== $request->old()) {
            $list->fill($request->old());
        }
        if (isset($request->old()['contact'])) {
            $list->contact->fill($request->old()['contact']);
        }

        // Sending servers
        if (isset($request->old()['sending_servers'])) {
            $list->mailListsSendingServers = collect([]);
            foreach ($request->old()['sending_servers'] as $key => $param) {
                if ($param['check']) {
                    $server = \Acelle\Model\SendingServer::findByUid($key);
                    $row = new \Acelle\Model\MailListsSendingServer();
                    $row->mail_list_id = $list->id;
                    $row->sending_server_id = $server->id;
                    $row->fitness = $param['fitness'];
                    $list->mailListsSendingServers->push($row);
                }
            }
        }

        return view('lists.createopeners', [
            'list' => $list,
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
        // Generate info
        $customer = $request->user()->customer;
        $list = new \Acelle\Model\MailList();

        // authorize
        if (\Gate::denies('create', $list)) {
            return $this->noMoreItem();
        }

        // validate and save posted data
        if ($request->isMethod('post')) {
            $this->validate($request, \Acelle\Model\MailList::$rules);

            $rules = [];
            if (isset($request->sending_servers)) {
                foreach ($request->sending_servers as $key => $param) {
                    if ($param['check']) {
                        $rules['sending_servers.'.$key.'.fitness'] = 'required';
                    }
                }
            }
            $this->validate($request, $rules);

            // Save contact
            $contact = \Acelle\Model\Contact::create($request->all()['contact']);
            $list->fill($request->all());
            $list->customer_id = $customer->id;
            $list->contact_id = $contact->id;
            $list->save();

            // For sending servers
            if (isset($request->sending_servers)) {
                $list->updateSendingServers($request->sending_servers);
            }

            // Trigger updating related campaigns cache
         //   event(new \Acelle\Events\MailListUpdated($list));
            // new implementation uses external API to RabiitMQ
            MailLog::info("EXPERIMENTAL MailList cache update is initiated!!!");
            $taskrunner = New TaskRunner();
            $customer2 = $customer->id;
            $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_LIST_UPDATE,$customer2,$taskrunner::PRIORITY_LOW,$list->uid);
            MailLog::info("EXPERIMENTAL maillist cache update already passed!!! customer: ".$customer2. " maillist: ".$list->uid);

            // Log
            $list->log('created', $request->user()->customer);

            // Redirect to my lists page
            $request->session()->flash('alert-success', trans('messages.list.created'));

            return redirect()->action('MailListController@index');
        }
    }


// openers list creation function
    public function storeopeners(Request $request)
    {

 //       echo "test";
   //     die;
//        // Generate info
        $customer = $request->user()->customer;
        $list = new \Acelle\Model\MailList();
//
//        // authorize
//        if (\Gate::denies('create', $list)) {
//            return $this->noMoreItem();
//        }
//
//        // validate and save posted data
        if ($request->isMethod('post')) {
            // we need to auto generate the sql queries
            $SQL = "SELECT email from app_openai where 1=2 ";
            $provider_sql = "";
            $enabled_providers = $request->enable_providers;
            $enabled_location = $request->enable_location;
            // if providers are enabled
            if ($enabled_providers == "true") {
                $get_providers = $request->providers;
                //print "we are generating providers sql";
                if ($get_providers != "") {
                    // foreach spaces in the string
                    $providers = explode(" ", $get_providers);
                    foreach ($providers as $provider) {
                        //print "we got: ".$provider;
                        $provider_sql .= " or email like '%$provider'";
                    }
                }
            }
            // if filter by countries are provided
            $locataion_sql = "";
            if ($enabled_location == "true") {
                $get_location = $request->location;
                //print "we are generating providers sql";
                if ($get_location != "") {
                    // foreach spaces in the string
                    $locations = explode(" ", $get_location);
                    foreach ($locations as $location) {
                        //print "we got: ".$provider;
                        $locataion_sql .= " or location like lower('%$location')";
                    }
                }

            }
            if ($enabled_location == "false" && $enabled_providers == "false") {
                // stop here if no filter options are set
                print "No filter options were set!";
                exit;
            }

            if ($enabled_providers)
                $SQL .= $provider_sql;
            if ($enabled_location)
                $SQL .= $locataion_sql;

            $db = "trackingas";
            $dbuser = "ses_remote";
            $dbpass = "bGh9CaF897q";
            $dbhost = "app.parkagency.net";
            $dbport = "3306";
            $db = new \mysqli($dbhost, $dbuser, $dbpass, $db);
            if ($db->connect_errno) {
                printf("Error connecting to the database: %s\n", $db->connect_error);
                exit();
            }
            $result = $db->query($SQL);
            $rows = array();
            $count_records = 0;
            while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
                $count_records++;
                $rows[] = $row;
            }

//            foreach ($rows as $row) {
//                print "Emailas: ".$row['email']."<br>";
//            }
//


            $this->validate($request, \Acelle\Model\MailList::$rules);

            $rules = [];
            if (isset($request->sending_servers)) {
                foreach ($request->sending_servers as $key => $param) {
                    if ($param['check']) {
                        $rules['sending_servers.' . $key . '.fitness'] = 'required';
                    }
                }
            }





            $this->validate($request, $rules);

            // Save contact
            $contact = \Acelle\Model\Contact::create($request->all()['contact']);
            $list->fill($request->all());
            $list->customer_id = $customer->id;
            $list->contact_id = $contact->id;
            $list->save();


            // create subscribers from the external list
            foreach ($rows as $row) {
                $subscriber = new \Acelle\Model\Subscriber();
                $subscriber->mail_list_id = $list->id;
                $subscriber->status = 'subscribed';
                $subscriber->email = $row['email'];
                $subscriber->save();
               // $subscriber->updateFields($request->all());
            }


//
//            // For sending servers
            if (isset($request->sending_servers)) {
                $list->updateSendingServers($request->sending_servers);
            }
//
//            // Trigger updating related campaigns cache
           //  event(new \Acelle\Events\MailListUpdated($list));
            // new implementation uses external API to RabiitMQ
            try {
                MailLog::info("EXPERIMENTAL MailList cache update is initiated!!!");
                $taskrunner = New TaskRunner();
                $customer2 = $customer->id;
                $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_LIST_UPDATE, $customer2, $taskrunner::PRIORITY_LOW, $list->uid);
                MailLog::info("EXPERIMENTAL maillist cache update already passed!!! customer: " . $customer2 . " maillist: " . $list->uid);
            } catch (\Exception $ex) {
                MailLog::error("Unable to process update list cache at MailListController.php:528");
            }
//
//            // Log
             $list->log('created', $request->user()->customer);
//
//            // Redirect to my lists page
             $request->session()->flash('alert-success', trans('messages.list.created'));
//
            return redirect()->action('MailListController@index');
//        }
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
    public function edit(Request $request, $uid)
    {
        // Generate info
        $customer = $request->user()->customer;
        $list = \Acelle\Model\MailList::findByUid($uid);

        // authorize
        if (\Gate::denies('update', $list)) {
            return $this->notAuthorized();
        }

        // Get old post values
        if (null !== $request->old()) {
            $list->fill($request->old());
        }
        if (isset($request->old()['contact'])) {
            $list->contact->fill($request->old()['contact']);
        }



        // Sending servers
        if (isset($request->old()['sending_servers'])) {
            $list->mailListsSendingServers = collect([]);
            foreach ($request->old()['sending_servers'] as $key => $param) {
                if ($param['check']) {
                    $server = \Acelle\Model\SendingServer::findByUid($key);
                    $row = new \Acelle\Model\MailListsSendingServer();
                    $row->mail_list_id = $list->id;
                    $row->sending_server_id = $server->id;
                    $row->fitness = $param['fitness'];
                    $list->mailListsSendingServers->push($row);
                }
            }
        }

        return view('lists.edit', [
            'list' => $list,
        ]);
    }



    function get_current_servers($id)
    {
        $servai = \DB::table('sending_servers')->select('sending_servers.uid','mail_lists_sending_servers.fitness')
            ->join('mail_lists_sending_servers', 'sending_servers.id', '=', 'mail_lists_sending_servers.sending_server_id')
            ->where('mail_lists_sending_servers.mail_list_id', $id)->get();
        $serv = array();
        foreach ($servai as $key => $param) {
            $serv[$param->uid] = [ "check" => 1, "fitness" => $param->fitness,  ];
        }

        return $serv;
    }

    public static function arrays_are_equal($array1, $array2)
    {
        array_multisort($array1);
        array_multisort($array2);
        return ( serialize($array1) === serialize($array2) );
    }

    function clear_processes($uid) {
        MailLog::info("Killing processes of the campaign: ".$uid);
        exec("kill `ps x|grep -v grep|grep gosender|grep $uid|awk '{ print \$1}'` > /dev/null 2>&1 &");

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
        // Generate info
        $customer = $request->user()->customer;
        $list = \Acelle\Model\MailList::findByUid($request->uid);

        // authorize
        if (\Gate::denies('update', $list)) {
            return $this->notAuthorized();
        }

        // validate and save posted data
        if ($request->isMethod('patch')) {
            $this->validate($request, \Acelle\Model\MailList::$rules);

            $rules = [];
            if (isset($request->sending_servers)) {
                foreach ($request->sending_servers as $key => $param) {
                    if ($param['check']) {
                        $rules['sending_servers.'.$key.'.fitness'] = 'required';
                    }
                }
            }
            $oldlist = new \StdClass();
            $oldlist = $list;
            $oldrequest = new \StdClass();
            $oldrequest = $request;
                // get old servers
                $old_servers = $this->get_current_servers($list->id);

            $this->validate($request, $rules);

            // Save contact
            $list->contact->fill($request->all()['contact']);
            $list->contact->save();
            $list->fill($request->all());
            $list->save();



            // For sending servers
            if (isset($request->sending_servers)) {
                $list->updateSendingServers($request->sending_servers);
            }

           // sleep(2);

            MailLog::info("going further...");

            // we need to compare running processes against the database, kill the cancelled servers and fork the new assigned ones
            $default_fitness = \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
            MailLog::info("debug: ".$oldlist->speed);
          //  if (empty($request->all_sending_servers) || $oldlist->all_sending_servers != $request->all_sending_servers || $oldlist->speed != $request->speed) {
                MailLog::info("We detected changes in maillist id: ".$list->id." the next step is to decide whatever it needs senders to be restarted...");
                if ($request->all_sending_servers == 1) {
                    MailLog::info("All sending servers are enabled");
                    $campaigns = \DB::table('mail_lists')->select('campaigns.uid')
                        ->join('campaigns_lists_segments', 'mail_lists.id', '=', 'campaigns_lists_segments.mail_list_id')
                        ->join('campaigns', 'campaigns_lists_segments.campaign_id', '=', 'campaigns.id')
                        ->where('mail_lists.uid', $list->uid)
                        ->where('campaigns.status', 'sending')
                        ->get();
                    if (count($campaigns) > 0) {
                        $campaigns_fixed = array();
                        foreach ($campaigns as $key => $value) {
                            $campaigns_fixed[] = $value->uid;
                        }
                        MailLog::info("Making queue for campaign restart background sender for speed change for these uids: " . print_r($campaigns_fixed, true));
                        Campaign::RestartBackroundProcesses($campaigns_fixed);
                       // $job = (new \Acelle\Jobs\RestartProcessesJob($campaigns_fixed))->delay(0);
                       // $this->dispatch($job);
                    } else {
                        MailLog::info("No compagains found for restart, assigned to the maillist: $list->uid");
                    }
//                    foreach ($compaigns as $c) {
//                        MailLog::info("Targeting campaign: ".$c->uid." with all servers");
//                        $servers = \DB::table('sending_servers')->select('host', 'smtp_port')
//                            ->where('status', 'active')->where('sending_servers.id','>', 1)->get();
//                        $this->clear_processes($c->uid);
//                        foreach($servers as $servas) {
//                            //if (!isset($servas->fitness)) $servas->fitness = $default_fitness;
//                            if ($request->speed > 10000) $servas->fitness = $request->speed;
//                            else $servas->speed = $default_fitness;
//                            MailLog::info("\$HOME/gosender --send --campuid $c->uid --smtphost $servas->host --smtpport $servas->smtp_port --smtpspeed $servas->fitness  > /dev/null 2>&1 &");
//                            exec("\$HOME/gosender --send --campuid $c->uid --smtphost $servas->host --smtpport $servas->smtp_port --smtpspeed $servas->fitness  > /dev/null 2>&1 &");
//                        }
//                    }


                } else {
MailLog::info("Servers assigned by hand...");
                    // get new servers
                    $new_servers = array();
                    foreach ($request->sending_servers as $key => $value) {
                        if ($value['check'] == 1)
                            $new_servers[$key] = [ "check" => 1, "fitness" => $value['fitness'] ];
                    }

                //    if (!$this->arrays_are_equal($old_servers,$new_servers)||$list->all_sending_servers == 1) {
                        MailLog::info("Only specified servers are enabled");
                        // update all the servers that are
                        $campaigns = \DB::table('mail_lists')->select('campaigns.uid')
                            ->join('campaigns_lists_segments', 'mail_lists.id', '=', 'campaigns_lists_segments.mail_list_id')
                            ->join('campaigns', 'campaigns_lists_segments.campaign_id', '=', 'campaigns.id')
                            ->where('mail_lists.uid', $list->uid)
                            ->where('campaigns.status', 'sending')
                            ->get();
                        if (count($campaigns) > 0) {
                            $campaigns_fixed = array();
                            foreach ($campaigns as $key => $value) {
                                $campaigns_fixed[] = $value->uid;
                            }
                            MailLog::info("Making queue for campaign restart background sender for speed change for these uids: " . print_r($campaigns_fixed, true));
                            //sleep(1);
                            Campaign::RestartBackroundProcesses($campaigns_fixed);
                           // $job = (new \Acelle\Jobs\RestartProcessesJob($campaigns_fixed))->delay(0);
                           // $this->dispatch($job);
                        } else {
                            MailLog::info("No compagains found for restart, assigned to the maillist: $list->uid");
                        }
//                        foreach ($compaigns as $c) {
//                            MailLog::info("Targeting campaign: ".$c->uid." with selected servers");
//                            $servers = \DB::table('sending_servers')->select('uid', 'host', 'smtp_port')
//                                ->where('status', 'active')->where('sending_servers.id','>', 1)->get();
//                            // we choose what we actually need TODO reimplement in a smarter way ;-)
//                            $prep_srv = array();
//                            foreach ($servers as $srv) {
//                                if (array_key_exists($srv->uid,$new_servers)) {
//                                $prep_srv[] = [ "host" => $srv->host, "smtp_port" => $srv->smtp_port, "fitness" => $new_servers[$srv->uid]['fitness'] ];
//                                }
//
//                            }
//                            $this->clear_processes($c->uid);
//                            foreach($prep_srv as $servas) {
//                                if (!isset($servas['fitness'])) $servas['fitness'] = $default_fitness;
//                                MailLog::info("\$HOME/gosender --send --campuid $c->uid --smtphost ".$servas['host']." --smtpport ".$servas['smtp_port']." --smtpspeed ".$servas['fitness']."  > /dev/null 2>&1 &");
//                                exec("\$HOME/gosender --send --campuid $c->uid --smtphost ".$servas['host']." --smtpport ".$servas['smtp_port']." --smtpspeed ".$servas['fitness']."  > /dev/null 2>&1 &");
//                            }
//                        }

                 //   }




                }

           // }

          //  $result = array_diff($this->object_to_array($list->mailListsSendingServers()),$request->sending_servers);
//
//            if ($changed > 0) {
//                MailLog::info("Server consistency changed!");
//            } else {
//                MailLog::info("Servers are the same it was left.");
//            }

         //   MailLog::info("SET: ".print_r($this->get_current_servers($list->id),true));




            // Log
            $list->log('updated', $request->user()->customer);

            // update track urls on campaigns
            $kampanijos = \DB::table('mail_lists')->select('campaigns.uid')
                ->join('campaigns_lists_segments', 'mail_lists.id', '=', 'campaigns_lists_segments.mail_list_id')
                ->join('campaigns', 'campaigns_lists_segments.campaign_id', '=', 'campaigns.id')
                ->where('mail_lists.uid', $request->uid)->get();
           foreach ($kampanijos as $komp) {
               if (Redis::exists($komp->uid)) {
                   $co = json_decode(Redis::get($komp->uid));
                   if (!empty($list->trackurl)) {
                       $co->trackurl = $list->trackurl;
                       Redis::set($komp->uid, json_encode($co));
                   }
               }
               if (!empty($list->trackurl))
               \DB::table('campaigns')->where('uid', $komp->uid)->update(['trackurl' => $list->trackurl]);
             //  MailLog::info("Setting the new tracking info to the campaign: " . $komp->uid);
           }

            // Redirect to my lists page
            $request->session()->flash('alert-success', trans('messages.list.updated'));

            return redirect()->action('MailListController@edit', $list->uid);
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
            $list = \Acelle\Model\MailList::findByUid($row[0]);

            // authorize
            if (\Gate::denies('update', $list)) {
                return $this->notAuthorized();
            }

            $list->custom_order = $row[1];
            $list->save();
        }

        echo trans('messages.lists.custom_order.updated');
    }

    /**
     * Delete confirm message.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function deleteConfirm(Request $request)
    {
        $lists = \Acelle\Model\MailList::whereIn('uid', explode(',', $request->uids));

        return view('lists.delete_confirm', [
            'lists' => $lists,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function delete(Request $request)
    {
        if (isSiteDemo()) {
            echo trans('messages.operation_not_allowed_in_demo');
            return;
        }

        // FIXME OPTIMIZE DELETE BY LIMIT

        $lists = \Acelle\Model\MailList::whereIn('uid', explode(',', $request->uids));

        foreach ($lists->get() as $item) {
            // authorize
            if (\Gate::allows('delete', $item)) {
                $item->del_date = \Carbon\Carbon::now();
                $item->save();
                //$item->delete();

                // not needed as the related campaigns will be deleted as well
                 $item->updateCachedInfo();

                // Log
                $item->log('deleted', $request->user()->customer);

                // update MailList cache
               // event(new \Acelle\Events\MailListUpdated($item));
                // We will use the new way

            }
        }

        // Redirect to my lists page
        echo trans('messages.lists.deleted');
    }

    /**
     * List overview.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function overview(Request $request)
    {
        $customer = $request->user()->customer;
        $list = \Acelle\Model\MailList::findByUid($request->uid);

    //    event(new \Acelle\Events\MailListUpdated($list));
        try {
            MailLog::info("EXPERIMENTAL MailList cache update is initiated!!!");
            $taskrunner = New TaskRunner();
            $customer2 = $customer->id;
            $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_LIST_UPDATE, $customer2, $taskrunner::PRIORITY_LOW, $list->uid);
            MailLog::info("EXPERIMENTAL maillist cache update already passed!!! customer: " . $customer2 . " maillist: " . $list->uid);
        } catch (\Exception $ex) {
            MailLog::error("Unable to process update list cache at MailListController.php:942");
        }

//        // authorize
//        if (\Gate::denies('read', $list)) {
//            return $this->notAuthorized();
//        }

        return view('lists.overview', [
            'list' => $list,
        ]);
    }

    /**
     * List growth chart content.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function listGrowthChart(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->uid);

        if (is_object($list)) {
            $list_id = $list->id;
        } else {
            $list_id = null;
            $list = new \Acelle\Model\MailList();
            $list->customer_id = $request->user()->customer->id;
        }

        // authorize
        if (\Gate::denies('read', $list)) {
            return $this->notAuthorized();
        }

        $result = [
            'columns' => [],
            'data' => [],
            'bar_names' => [trans('messages.subscriber_growth')],
        ];

        // columns
        for ($i = 2; $i >= 0; --$i) {
            $result['columns'][] = \Carbon\Carbon::now()->subMonthsNoOverflow($i)->format('m/Y');
        }

        // datas
        foreach ($result['bar_names'] as $bar) {
            $data = [];
            for ($i = 2; $i >= 0; --$i) {
                $data[] = \Acelle\Model\Customer::subscribersCountByTime(
                    \Carbon\Carbon::now()->subMonthsNoOverflow($i)->startOfMonth(),
                    \Carbon\Carbon::now()->subMonthsNoOverflow($i)->endOfMonth(),
                    $request->user()->customer->id,
                    $list_id
                );
            }

            $result['data'][] = [
                'name' => $bar,
                'type' => 'bar',
                'data' => $data,
                'itemStyle' => [
                    'normal' => [
                        'label' => [
                            'show' => true,
                            'textStyle' => [
                                'fontWeight' => 500,
                            ],
                        ],
                    ],
                ],
            ];
        }

        return json_encode($result);
    }



    public function OpenerslistGrowthChart(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->uid);

        if (is_object($list)) {
            $list_id = $list->id;
        } else {
            $list_id = null;
            $list = new \Acelle\Model\MailList();
            $list->customer_id = $request->user()->customer->id;
        }

        // authorize
        if (\Gate::denies('read', $list)) {
            return $this->notAuthorized();
        }

        $result = [
            'columns' => [],
            'data' => [],
            'bar_names' => [trans('messages.subscriber_growth')],
        ];

        // columns
        for ($i = 2; $i >= 0; --$i) {
            $result['columns'][] = \Carbon\Carbon::now()->subMonthsNoOverflow($i)->format('m/Y');
        }

        // datas
        foreach ($result['bar_names'] as $bar) {
            $data = [];
            for ($i = 2; $i >= 0; --$i) {
                $data[] = \Acelle\Model\Customer::subscribersCountByTime(
                    \Carbon\Carbon::now()->subMonthsNoOverflow($i)->startOfMonth(),
                    \Carbon\Carbon::now()->subMonthsNoOverflow($i)->endOfMonth(),
                    $request->user()->customer->id,
                    $list_id
                );
            }

            $result['data'][] = [
                'name' => $bar,
                'type' => 'bar',
                'data' => $data,
                'itemStyle' => [
                    'normal' => [
                        'label' => [
                            'show' => true,
                            'textStyle' => [
                                'fontWeight' => 500,
                            ],
                        ],
                    ],
                ],
            ];
        }

        return json_encode($result);
    }

    /**
     * Chart statistics chart.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function statisticsChart(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->uid);
        $customer = $request->user()->customer;

        if (is_object($list)) {
            $list_id = $list->id;
        } else {
            $list_id = null;
            $list = new \Acelle\Model\MailList();
            $list->customer_id = $request->user()->customer->id;
        }

        // authorize
        if (\Gate::denies('read', $list)) {
            return $this->notAuthorized();
        }

        $result = [
            'title' => '',
            'columns' => [],
            'data' => [],
            'bar_names' => [],
        ];

        $datas = [];
        if (isset($list->id)) {
            if ($list->readCache('SubscribeCount', 0)) {
                $result['bar_names'][] = trans('messages.subscribed');
                $datas[] = ['value' => $list->readCache('SubscribeCount', 0), 'name' => trans('messages.subscribed')];
            }

            if ($list->readCache('UnsubscribeCount', 0)) {
                $result['bar_names'][] = trans('messages.unsubscribed');
                $datas[] = ['value' => $list->readCache('UnsubscribeCount', 0), 'name' => trans('messages.unsubscribed')];
            }

            if ($list->readCache('UnconfirmedCount', 0)) {
                $result['bar_names'][] = trans('messages.unconfirmed');
                $datas[] = ['value' => $list->readCache('UnconfirmedCount', 0), 'name' => trans('messages.unconfirmed')];
            }

            if ($list->readCache('BlacklistedCount', 0)) {
                $result['bar_names'][] = trans('messages.blacklisted');
                $datas[] = ['value' => $list->readCache('BlacklistedCount', 0), 'name' => trans('messages.blacklisted')];
            }

            if ($list->readCache('SpamReportedCount', 0)) {
                $result['bar_names'][] = trans('messages.spam_reported');
                $datas[] = ['value' => $list->readCache('SpamReportedCount', 0), 'name' => trans('messages.spam_reported')];
            }
        } else {
            // create data
            if ($customer->readCache('SubscribedCount', 0)) {
                $result['bar_names'][] = trans('messages.subscribed');
                $datas[] = ['value' => $request->user()->customer->readCache('SubscribedCount', 0), 'name' => trans('messages.subscribed')];
            }

            if ($customer->readCache('UnsubscribedCount', 0)) {
                $result['bar_names'][] = trans('messages.unsubscribed');
                $datas[] = ['value' => $customer->readCache('UnsubscribedCount', 0), 'name' => trans('messages.unsubscribed')];
            }

            if ($customer->readCache('UnconfirmedCount', 0)) {
                $result['bar_names'][] = trans('messages.unconfirmed');
                $datas[] = ['value' => $customer->readCache('UnconfirmedCount', 0), 'name' => trans('messages.unconfirmed')];
            }

            if ($customer->readCache('BlackListedCount', 0)) {
                $result['bar_names'][] = trans('messages.blacklisted');
                $datas[] = ['value' => $customer->readCache('BlackListedCount', 0), 'name' => trans('messages.blacklisted')];
            }

            if ($customer->readCache('SpamReportedCount', 0)) {
                $result['bar_names'][] = trans('messages.spam_reported');
                $datas[] = ['value' => $customer->readCache('SpamReportedCount', 0), 'name' => trans('messages.spam_reported')];
            }
        }

        // datas
        $result['data'][] = [
            'name' => trans('messages.statistics'),
            'type' => 'pie',
            'radius' => '70%',
            'center' => ['50%', '57.5%'],
            'data' => $datas
        ];

        $result['pie'] = 1;
        return json_encode($result);
    }

    /**
     * Quick view.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function quickView(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->uid);

        if (!is_object($list)) {
            $list = new \Acelle\Model\MailList();
            $list->uid = '000';
            $list->customer_id = $request->user()->customer->id;
        }

        // authorize
        if (\Gate::denies('read', $list)) {
            return $this->notAuthorized();
        }

        return view('lists._quick_view', [
            'list' => $list,
        ]);
    }

    /**
     * Copy list.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function copy(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->copy_list_uid);

        // authorize
        if (\Gate::denies('update', $list)) {
            return $this->notAuthorized();
        }

        $list->copy($request->copy_list_name);

        echo trans('messages.list.copied');
    }

    /**
     * Embedded Forms.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function embeddedForm(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $list)) {
            return $this->notAuthorized();
        }

        return view('lists.embedded_form', [
            'list' => $list,
        ]);
    }

    /**
     * Embedded Forms.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function embeddedFormFrame(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $list)) {
            return $this->notAuthorized();
        }

        return view('lists.embedded_form_frame', [
            'list' => $list,
        ]);
    }

    /**
     * reCaptcha check.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function embeddedFormCaptcha(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->uid);

        $request->session()->set('form_url', \URL::previous());

        return view('lists.embedded_form_captcha', [
            'list' => $list,
        ]);
    }

    /**
     * Subscribe user from embedded Forms.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function embeddedFormSubscribe(Request $request)
    {
        if (\Acelle\Model\Setting::get('embedded_form_recaptcha') == 'yes') {
            $success = \Acelle\Library\Tool::checkReCaptcha($request);
        } else {
            $success = true;
        }

        $list = \Acelle\Model\MailList::findByUid($request->uid);

        if (!$success) {
            $url = $request->session()->pull('form_url');
            $errs = [trans("messages.invalid_captcha")];
            return view('lists.embedded_form_captcha_invalid', [
                'errors' => $errs,
                'list' => $list,
                'back_link' => $url,
            ]);
        }

        // Create subscriber
        if ($request->isMethod('post')) {
            $subscriber = new \Acelle\Model\Subscriber($request->all());
            $subscriber->mail_list_id = $list->id;
            if($list->subscribe_confirmation) {
                $subscriber->status = 'unconfirmed';
            } else {
                $subscriber->status = 'subscribed';
            }
            $subscriber->from = 'embedded-form';

            // Validation
            $validator = \Validator::make($request->all(), $subscriber->getRules());

            if ($validator->fails()) {
                $url = $request->session()->pull('form_url');
                // $validator->errors()
                $errs = [];
                foreach($validator->errors()->toArray() as $key => $error) {
                    $errs[] = $key . ": " . $error[0];
                }

                if (strpos($url, '?') !== false) {
                    $url = $url . "&" . implode('&', $errs);
                } else {
                    $url = $url . "?" . implode('&', $errs);
                }

                // return redirect()->away($url);
                return view('lists.embedded_form_errors', [
                    'errors' => $errs,
                    'list' => $list,
                    'back_link' => $url,
                ]);
            }

            $subscriber->email = $request->EMAIL;
            $subscriber->ip = $request->ip();
            $subscriber->save();
            // Update field
            $subscriber->updateFields($request->all());

            if($list->subscribe_confirmation) {
                // SEND subscription confirmation email
                $list->sendSubscriptionConfirmationEmail($subscriber);

                return redirect()->action('PageController@signUpThankyouPage', ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid]);
            } else {
                // change status to subscribed
                $subscriber->updateStatus('subscribed');

                // Send welcome email
                if($list->send_welcome_email) {
                    // SEND subscription confirmation email
                    $list->sendSubscriptionWelcomeEmail($subscriber);
                }

                return redirect()->action('PageController@signUpConfirmationThankyou', [
                        'list_uid' => $list->uid,
                        'uid' => $subscriber->uid,
                        'code' => 'empty',
                    ]
                );
            }
        }
    }

    /**
     * Mail list emails verification main page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function verification(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->uid);

        return view('lists.email_verification', [
            'list' => $list,
        ]);
    }

    /**
     * Start the verification process
     *
     */
    public function startVerification(Request $request)
    {
        $list = MailList::findByUid($request->uid);
        $server = EmailVerificationServer::findByUid($request->email_verification_server_id);
        Log::info("Trying to start verification process for list " . $list->id);
        $list->queueForVerification($server->id);
        return redirect()->action('MailListController@verification', $list->uid);
    }

    /**
     * Stop the verification process
     *
     */
    public function stopVerification(Request $request)
    {
        $list = MailList::findByUid($request->uid);
        $list->stopVerification();
        return redirect()->action('MailListController@verification', $list->uid);
    }

    /**
     * Reset the verification data
     *
     */
    public function resetVerification(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->uid);
        $list->resetVerification();
        return redirect()->action('MailListController@verification', $list->uid);
    }

    /**
     * Check verification progress
     *
     */
    public function verificationProgress(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->uid);
        $percent = $list->getVerifiedSubscribersPercentage();

        if (!$list->isVerificationRunning()) {
            echo 'done';
            $request->session()->flash('alert-success', trans('messages.verification.done'));
            return;
        }

        return view('lists.email_verification_progress', [
            'list' => $list,
        ]);
    }

    /**
     * Check email
     *
     */
    public function checkEmail(Request $request)
    {
        header("Access-Control-Allow-Origin: *");

        $list = \Acelle\Model\MailList::findByUid($request->uid);
        $subscriber = $list->subscribers()->where('email','=',strtolower(trim($request->EMAIL)))->first();

        if(is_object($subscriber) && $subscriber->status != \Acelle\Model\Subscriber::STATUS_SUBSCRIBED) {
            $result = trans('messages.email_already_subscribed');
        } else {
            $result = true;
        }

        return response()->json($result);
    }
}
