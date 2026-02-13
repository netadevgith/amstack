<?php

namespace Acelle\Http\Controllers;

use Acelle\Library\StorageHelper;
use Illuminate\Http\Request;
use Acelle\Http\Controllers\Controller;
use \Acelle\Jobs\ImportBlacklistJob;
use Acelle\Library\Log as MailLog;

class Blacklist_mxController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        parent::__construct();

        $this->middleware('auth');
    }

    /**
     * Search items.
     */
    public function search($request)
    {
        $request->merge(array("customer_id" => $request->user()->customer->id));
        $blacklists = \Acelle\Model\Blacklist_mx::search($request);

        return $blacklists;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $customer = $request->user()->customer;

        $blacklists = $this->search($request);

        # Get current job
       // $system_job = $customer->getLastActiveImportBlacklistJob();

        return view('blacklists_mx.index', [
            'blacklists' => $blacklists,
            'system_job' => null,
        ]);
    }


      /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listing(Request $request)
    {
        $blacklists = $this->search($request)->paginate($request->per_page);

        return view('blacklists_mx._list', [
            'blacklists' => $blacklists,
        ]);
    }

    public function item_add(Request $request)
    {
        $customer_id = $request->user()->customer->id;
            if ($request->isMethod('post')) {
                try {
                    $reason = $request->comment;
                    // add point here
                    $record = $request->name;
                    if (substr($record, -1) != '.') $record = $record . '.';
                    if (\Config::get('app.storage') == true) {
                        $stor = new StorageHelper();
                        $stor->SubmitEmail($record, 11, "Bad mx");
                    }
                    \DB::table('blacklist_mx')->insert(['record' => $record, 'reason' => $reason, 'customer_id' => $customer_id, 'created_at' => \date('Y-m-d G:i:s'), 'updated_at' => \date('Y-m-d G:i:s')]);
                    $this->populate_redis();
                } catch (\Exception $ex) {
                    MailLog::error("Problem in Blacklist_mxController:item_add: ".$ex);
                }

        return redirect('admin/blacklist_mx');

        }


        return view('blacklists_mx.create');

    }


    private function populate_redis()
    {
        $blacklist = \Acelle\Model\Blacklist_mx::getAll()->get();
        \Redis::del('blacklist_mx');
        foreach ($blacklist as $bl) {
            \Redis::hset('blacklist_mx',$bl->record,$bl->reason);
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $blacklist = new \Acelle\Model\Blacklist_mx([
            'signing_enabled' => true,
        ]);
        $blacklist->fill($request->old());

        // authorize
        if (!$request->user()->customer->can('create', $blacklist)) {
            return $this->notAuthorized();
        }

        return view('blacklists_mx.create', [
            'blacklist' => $blacklist,
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
        $blacklist = new \Acelle\Model\Blacklist_mx();

        // authorize
        if (!$request->user()->customer->can('create', $blacklist)) {
            return $this->notAuthorized();
        }

        // save posted data
        if ($request->isMethod('post')) {
            $this->validate($request, \Acelle\Model\Blacklist_mx::rules());



            // Save current user info
            $blacklist->fill($request->all());
            $blacklist->customer_id = $request->user()->customer->id;
            $blacklist->status = 'active';

            $this->populate_redis();
            if ($blacklist->save()) {
                // Log
                $blacklist->log('created', $request->user()->customer);

                $request->session()->flash('alert-success', trans('messages.blacklist.created'));
                return redirect()->action('Blacklist_mxController@index');
            }
        }
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
        $blacklist = \Acelle\Model\Blacklist::findByUid($id);

        // authorize
        if (!$request->user()->customer->can('update', $blacklist)) {
            return $this->notAuthorized();
        }

        $blacklist->fill($request->old());

        return view('blacklists_mx.edit', [
            'blacklist' => $blacklist,
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
        $blacklist = \Acelle\Model\Blacklist_mx::findByUid($id);

        // authorize
        if (!$request->user()->customer->can('update', $blacklist)) {
            return $this->notAuthorized();
        }

        // save posted data
        if ($request->isMethod('patch')) {
            $this->validate($request, \Acelle\Model\Blacklist_mx::rules());

            // Save current user info
            $blacklist->fill($request->all());

            if ($blacklist->save()) {
                // Log
                $blacklist->log('updated', $request->user()->customer);

                $request->session()->flash('alert-success', trans('messages.blacklist.updated'));
                return redirect()->action('Blacklist_mxController@index');
            }
        }
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
        if ($request->select_tool == 'all_items') {
            $blacklists = $this->search($request);
        } else {
            $blacklists = \Acelle\Model\Blacklist_mx::whereIn('id', explode(',', $request->uids));
        }

        foreach ($blacklists->get() as $blacklist) {
            // authorize
//            if ($request->user()->customer->can('delete', $blacklist)) {
                // Log
                $blacklist->delete();
            //}
        }
        $this->populate_redis();
        // Redirect to my lists page
        echo trans('messages.blacklists.deleted');
    }

    public function deleteall(Request $request) {
        \Acelle\Model\Blacklist_mx::getAll()->delete();
        $request->session()->flash('alert-success', 'Blacklist is now empty!');
        return redirect()->action('Blacklist_mxController@index');
    }

    /**
     * Start import process.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */

    public function post_data(Request $request)
    {
        if ($request->isMethod('post')) {
            $customer = $request->user()->customer;
            MailLog::info("testas222222222222");

            if ($request->hasFile('file')) {
                // Start system job
                MailLog::info("iki cia veikia3");
               // apdorojam viska tiesiog cia ir redirectinam paskuj i pagr domains blacklist langa
                $comment = $request->comment;

                $content = \File::get($request->file('file')->path());
                $lines = preg_split('/\r\n|\r|\n/', $content);
                $total = count($lines);
                $blacklisted_contacts = [];
                $success = 0;
                MailLog::info("handlinam name importa: darom foreacha");
                $datetime = date("Y-m-d H:i:s");
                if (!$comment) $comment = "Import";
                // $customer =  $request->user()->customer->id;; // Ugly hack, FIXME Should be \Auth::user()->customer->id;
                foreach ($lines as $number => $line) {
                    $email = trim(strtolower($line));
                    if (!empty($email)&&$email != "") {
                        // add point here if it's not exist
                        if (substr($email, -1) != '.') $email = $email.'.';
                        $blacklisted_contacts[] = "('" . $email . "',now(),now(),'" . $comment . "',$customer->id)";
                    }
                }

                if (count($blacklisted_contacts) > 0) {
                    $parts = array_chunk($blacklisted_contacts, 500);
                    $part_total = count($parts);
                    // update status, line count
                    $part_cnt = 0;
                    foreach ($parts as $part => $value) {
                        $part_cnt++;
                        MailLog::info("Processing blacklist import part: $part_cnt of $part_total...");
                        //\DB::table('blacklists')->insert($value);
                        $sql = "INSERT IGNORE INTO blacklist_mx (record,created_at,updated_at,reason,customer_id) VALUES ".implode( ',', $value );
                        \DB::unprepared($sql);
                        $success++;
                    }
                }
                $this->populate_redis();
                $request->session()->flash('alert-success', 'New blacklist was successfully imported!');
                return redirect()->action('Blacklist_mxController@index');




            } else {
                $this->populate_redis();
                $request->session()->flash('alert-success', 'Got some problems then importing the data!');
                return redirect()->action('Blacklist_mxController@index');
            }
        }

    }

    public function import(Request $request)
    {

        // Get current job
        return view('blacklists_mx.import', [
            'system_job' => null
        ]);
    }


    /**
     * Cancel importing job.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function cancel(Request $request)
    {
        $customer = $request->user()->customer;
        $system_job = $customer->getLastActiveImportBlacklistJob();

        // authorize
        if (!$customer->can('importCancel', new \Acelle\Model\Blacklist_mx())) {
            return $this->notAuthorized();
        }

        $system_job->setCancelled();

        $request->session()->flash('alert-success', trans('messages.blacklist.import.cancelled'));
        return redirect()->action('Blacklist_mxController@index');
    }
}
