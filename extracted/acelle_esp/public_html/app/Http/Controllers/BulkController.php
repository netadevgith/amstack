<?php

namespace Acelle\Http\Controllers;

use Acelle\Library\BulkHelper;
use Illuminate\Http\Request;
use Acelle\Http\Controllers\Controller;
use \Acelle\Jobs\ImportBlacklistJob;
use Acelle\Library\Log as MailLog;
use SendGrid\Mail;

class BulkController extends Controller
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
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
//        $customer = $request->user()->customer;
//
//        if (!$customer->can('read', new \Acelle\Model\Blacklist())) {
//            return $this->notAuthorized();
//        }
//
//        $blacklists = $this->search($request);
//
//        # Get current job
//        $system_job = $customer->getLastActiveImportBlacklistJob();

        $valid_count = 0;
        $queue_count = 0;

        if (\Redis::exists('bulk_checker')) $valid_count = \Redis::hlen('bulk_checker');
        if (\Redis::exists('bulk_queue')) $queue_count = \Redis::hlen('bulk_queue');

        $valid = array();
        if (\Redis::exists('bulk_checker')) {
            $entries = \Redis::hgetall('bulk_checker');
            foreach ($entries as $key => $value) {
                $json = json_decode($value);
                $valid[] = $json;
            }
            $valid = (object)$valid;
        }



        return view('bulk.index', [
            'valid' => $valid,
'valid_count' => $valid_count,
'queue_count' => $queue_count,
//            'system_job' => $system_job,
        ]);
    }



    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

    public function submit(Request $request) {
    if ($request->type == 0) {
        MailLog::info("Submit queue");
        $lib = new BulkHelper();
        $creds = $lib->raw_to_array($request->credentials);
        foreach ($creds as $cr) {
            $host = $lib->resolve_host($cr['username']);
            \Redis::hset('bulk_queue',$cr['username'],json_encode(['host' => $host, 'username' => $cr['username'], 'password' => $cr['password']]));
        }

    } elseif ($request->type == 1) {
        MailLog::info("Submit clear");
        if (\Redis::exists('bulk_queue')) \Redis::del('bulk_queue');
    }
    }


    public function check(Request $request) {

        $raw_cred = $request->credentials;
        $lib = new BulkHelper();
        MailLog::info("Got post: ".$raw_cred);
        $creds = $lib->raw_to_array($raw_cred);
        foreach ($creds as $cr) {
            $host = $lib->resolve_host($cr['username']);
            if ($host != "" && $lib->check_imap($host,$cr['username'],$cr['password'])) {
                MailLog::info("OK ".$cr['username']);
                \Redis::hset('bulk_checker', $cr['username'], json_encode(['host' => $host, 'username' => $cr['username'], 'password' => $cr['password']]));
            } else {
                MailLog::info("BAD ".$cr['username']);
            }
        }

        return redirect()->action('BulkController@index');
    }



}
