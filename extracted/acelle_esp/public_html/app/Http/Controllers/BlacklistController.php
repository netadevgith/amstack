<?php

namespace Acelle\Http\Controllers;

use Acelle\Library\StorageHelper;
use Illuminate\Http\Request;
use Acelle\Http\Controllers\Controller;
use \Acelle\Jobs\ImportBlacklistJob;
use Acelle\Library\Log as MailLog;

class BlacklistController extends Controller
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
        $blacklists = \Acelle\Model\Blacklist::search($request);

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

        if (!$customer->can('read', new \Acelle\Model\Blacklist())) {
            return $this->notAuthorized();
        }

        $blacklists = $this->search($request);

        # Get current job
        $system_job = $customer->getLastActiveImportBlacklistJob();

        return view('blacklists.index', [
            'blacklists' => $blacklists,
            'system_job' => $system_job,
        ]);
    }

    private function fputcsv2($fh, array $fields, $delimiter = ',', $enclosure = '"', $mysql_null = true) {
        $delimiter_esc = preg_quote($delimiter, '/');
        $enclosure_esc = preg_quote($enclosure, '/');

        $output = array();
        foreach ($fields as $field) {
            if ($field === null && $mysql_null) {
                $output[] = 'NULL';
                continue;
            }

            $output[] = preg_match("/(?:${delimiter_esc}|${enclosure_esc}|\s)/", $field) ? (
                $enclosure . str_replace($enclosure, $enclosure . $enclosure, $field) . $enclosure
            ) : $field;
        }

        fwrite($fh, join($delimiter, $output) . "\n");
    }

    public function exportblacklist() {
        $sql = \DB::select("select email from blacklists where external = 0");


        $date = date("Y.m.d_H-i-s");
        $filename = uniqid('down_blacklist_'.$date, true).'.csv';
        $file = fopen($filename, 'w');
        $this->fputcsv2($file, array('EMAIL'));
        foreach ($sql as $item) {
            $this->fputcsv2($file, array($item->email));
        }
        fclose($file);
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filename)); //Absolute URL
        ob_clean();
        flush();
        readfile($filename); //Absolute URL
        exit();
    }


    public static function populate_sql_from_redis() {
        \Redis::set('blacklists_locked',1);
        $date = date("Y-m-d H:i:s");
        $fresh_entries = \Redis::hgetall('blacklists');
        $count_entries = \Redis::hlen('blacklists');
        MailLog::info('We got: '.$count_entries.' of blacklist entries in the redis backend, sorting list started...');
        $prepared_insert = [];
        $count = 1;
        foreach ($fresh_entries as $key => $value) {
            $count++;
            //$prepared_insert[] = "" 'email' => $key, 'created_at' => $date, 'updated_at'=> $date, 'reason'=> 'Catched in parser', 'external' => 1, 'customer_id'=>1];
            $prepared_insert[] = "('".$key."','$date','$date','Catched in parser',1,1)";
            \Redis::hdel('blacklists',$key);
        }
        unset($fresh_entries);
        try {
            $chunk_size = 20000;
            $parts = ceil(count($prepared_insert) / $chunk_size);
            $prepared_insert = array_chunk($prepared_insert, $chunk_size);
            $part_count = 0;
            foreach ($prepared_insert as $part => $value) {
                $part_count++;
              //  \DB::transaction(function () use ($part, $value, $part_count, $parts) {
                    \DB::unprepared("INSERT IGNORE INTO blacklists (email,created_at,updated_at,reason,external,customer_id) VALUES ".implode( ',', $value));
                    MailLog::info("Processing blacklists part: $part_count of $parts...");
                //}, 5);
            }
        } catch (\Exception $ex) {
            MailLog::error('Got critical problems on updating SQL database from redis blacklists backend: '.$ex);
        } Finally {
            \Redis::del('blacklists_locked');
            MailLog::info('Populating SQL from redis using blacklists data is finished!');
        }

        //MailLog::info('DEBUG: '.print_r($prepared_insert,true));

    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listing(Request $request)
    {
        if (!$request->user()->customer->can('read', new \Acelle\Model\Blacklist())) {
            return $this->notAuthorized();
        }

        $blacklists = $this->search($request)->paginate($request->per_page);

        return view('blacklists._list', [
            'blacklists' => $blacklists,
        ]);
    }

    public function item_add(Request $request)
    {
        try {
            if (isset($request->email) && $request->isMethod('get')) {
                $reason = $request->comment;
                \DB::table('blacklists')->insert(['email' => $request->email, 'reason' => $reason, 'admin_id' => 1, 'customer_id' => 1, 'created_at' => \date('Y-m-d G:i:s'), 'updated_at' => \date('Y-m-d G:i:s')]);
                if (\Config::get('app.storage') == true) {
                    $stor = new StorageHelper();
                    $stor->SubmitEmail($request->email, 2, "Blacklisted");
                }
                return;

            } elseif ($request->isMethod('post')) {
                $reason = $request->comment;
                if (isset($request->fast) && $request->fast == "true") {
                    MailLog::info("pridedam fast blacklist: " . $request->email);
                    \Redis::hset("blacklists_fast", $request->email, $reason);
                }
                if (\Config::get('app.storage') == true) {
                    $stor = new StorageHelper();
                    $stor->SubmitEmail($request->email, 2, "Blacklisted");
                }
                \DB::table('blacklists')->insert(['email' => $request->email, 'reason' => $reason, 'admin_id' => 1, 'customer_id' => 1, 'created_at' => \date('Y-m-d G:i:s'), 'updated_at' => \date('Y-m-d G:i:s')]);
                return redirect('admin/blacklist');

            }
        } catch (\Exception $ex) {
            MailLog::error("Problem in BlacklistController:item_add: ".$ex);
        }

        return view('blacklists.create');

    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $blacklist = new \Acelle\Model\Blacklist([
            'signing_enabled' => true,
        ]);
        $blacklist->fill($request->old());

        // authorize
        if (!$request->user()->customer->can('create', $blacklist)) {
            return $this->notAuthorized();
        }

        return view('blacklists.create', [
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
        $blacklist = new \Acelle\Model\Blacklist();

        // authorize
        if (!$request->user()->customer->can('create', $blacklist)) {
            return $this->notAuthorized();
        }

        // save posted data
        if ($request->isMethod('post')) {
            $this->validate($request, \Acelle\Model\Blacklist::rules());



            // Save current user info
            $blacklist->fill($request->all());
            $blacklist->customer_id = $request->user()->customer->id;
            $blacklist->status = 'active';


            if ($blacklist->save()) {
                // Log
                $blacklist->log('created', $request->user()->customer);

                $request->session()->flash('alert-success', trans('messages.blacklist.created'));
                return redirect()->action('BlacklistController@index');
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

        return view('blacklists.edit', [
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
        $blacklist = \Acelle\Model\Blacklist::findByUid($id);

        // authorize
        if (!$request->user()->customer->can('update', $blacklist)) {
            return $this->notAuthorized();
        }

        // save posted data
        if ($request->isMethod('patch')) {
            $this->validate($request, \Acelle\Model\Blacklist::rules());

            // Save current user info
            $blacklist->fill($request->all());

            if ($blacklist->save()) {
                // Log
                $blacklist->log('updated', $request->user()->customer);

                $request->session()->flash('alert-success', trans('messages.blacklist.updated'));
                return redirect()->action('BlacklistController@index');
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
            $blacklists = \Acelle\Model\Blacklist::whereIn('id', explode(',', $request->uids));
        }

        foreach ($blacklists->get() as $blacklist) {
            // authorize
            if ($request->user()->customer->can('delete', $blacklist)) {
                // Log
                $blacklist->delete();
            }
        }

        // Redirect to my lists page
        echo trans('messages.blacklists.deleted');
    }

    public function deleteall(Request $request) {
        \Acelle\Model\Blacklist::getAll()->delete();
        $request->session()->flash('alert-success', 'Blacklist is now empty!');
        return redirect()->action('BlacklistController@index');
    }

    /**
     * Start import process.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function import(Request $request)
    {
        $customer = $request->user()->customer;

        if ($request->isMethod('post')) {
            // authorize
            if (!$request->user()->customer->can('import', new \Acelle\Model\Blacklist())) {
                return $this->notAuthorized();
            }

            if ($request->hasFile('file')) {
                // Start system job
                //$job = (new \Acelle\Jobs\ImportBlacklistJob($request->file('file')->path(),$request->comment, $request->user()->customer))->onQueue('high');
                $job = new \Acelle\Jobs\ImportBlacklistJob($request->file('file')->path(),$request->comment, $request->user()->customer);
                $this->dispatch($job);
            } else {
                // @note: use try/catch instead
                echo "max_file_upload";
            }
        }

        // Get current job
        $system_job = $customer->getLastActiveImportBlacklistJob();

        return view('blacklists.import', [
            'system_job' => $system_job
        ]);
    }

    /**
     * Check import proccessing.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function importProcess(Request $request)
    {
        $customer = $request->user()->customer;
        $system_job = \Acelle\Model\SystemJob::find($request->system_job_id);

        // authorize
        if (!$customer->can('read', new \Acelle\Model\Blacklist())) {
            return $this->notAuthorized();
        }

        return view('blacklists.import_process', [
            'system_job' => $system_job
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
        if (!$customer->can('importCancel', new \Acelle\Model\Blacklist())) {
            return $this->notAuthorized();
        }

        $system_job->setCancelled();

        $request->session()->flash('alert-success', trans('messages.blacklist.import.cancelled'));
        return redirect()->action('BlacklistController@index');
    }
}
