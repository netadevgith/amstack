<?php

namespace Acelle\Http\Controllers;

use Acelle\Library\TaskRunner;
use Illuminate\Http\Request;
use Acelle\Model\Subscriber;
use Acelle\Model\EmailVerificationServer;
use Acelle\Library\Log as MailLog;

class SubscriberController extends Controller
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
    public function search($list, $request)
    {
        $subscribers = \Acelle\Model\Subscriber::search($request)
            ->where('mail_list_id', '=', $list->id);

        return $subscribers;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);

        return view('subscribers.index', [
            'list' => $list
        ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listing(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);

        // authorize
    //    if (\Gate::denies('read', $list)) {
      //      return;
        //}

        $subscribers = $this->search($list, $request);
        $total = distinctCount($subscribers);
        $subscribers->with(['mailList', 'subscriberFields']);
        $subscribers = \optimized_paginate($subscribers, $request->per_page, null, null, null, $total);

        $fields = $list->getFields->whereIn('uid', explode(',', $request->columns));

        return view('subscribers._list', [
            'subscribers' => $subscribers,
            'total' => $total,
            'list' => $list,
            'fields' => $fields,
        ]);
    }


    // remove subscribers duplicates
    public function delete_dupes(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);
        $id = $list->id;
        // FIXME OPTIMIZE DELETE BY LIMIT
        \DB::unprepared("DELETE t1 FROM subscribers t1 INNER JOIN subscribers t2 WHERE t1.mail_list_id = $id AND t1.id > t2.id AND t1.email = t2.email");

        return view('subscribers.index', [
            'list' => $list
        ]);
    }


    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {


        $list = \Acelle\Model\MailList::findByUid($request->list_uid);
        $subscriber = new \Acelle\Model\Subscriber();
        $subscriber->mail_list_id = $list->id;

        // authorize
   /*     if (\Gate::denies('create', $subscriber)) {
            return $this->noMoreItem();
        }
*/

        // Get old post values
        $values = [];
        if (null !== $request->old()) {
            foreach ($request->old() as $key => $value) {
                if (is_array($value)) {
                    $values[str_replace('[]', '', $key)] = implode(',', $value);
                } else {
                    $values[$key] = $value;
                }
            }
        }



        return view('subscribers.create', [
            'list' => $list,
            'subscriber' => $subscriber,
            'values' => $values,
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
        $customer = $request->user()->customer;
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);
        $subscriber = new \Acelle\Model\Subscriber();
        $subscriber->mail_list_id = $list->id;
        $subscriber->status = 'subscribed';

        // authorize
   /*     if (\Gate::denies('create', $subscriber)) {
            return $this->noMoreItem();
        }
*/
        // validate and save posted data
        if ($request->isMethod('post')) {
            $this->validate($request, $subscriber->getRules());


            // Save subscriber
            $subscriber->email = $request->EMAIL;
            $subscriber->save();
            // Update field
            $subscriber->updateFields($request->all());

            // update MailList cache
          //  event(new \Acelle\Events\MailListUpdated($subscriber->mailList));
            // new implementation uses external API to RabiitMQ
            MailLog::info("EXPERIMENTAL MailList update is initiated!!!");
            $taskrunner = New TaskRunner();
            $customer2= $customer->id;
            $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_LIST_UPDATE,$customer2,$taskrunner::PRIORITY_LOW,$subscriber->mailList->uid);
            MailLog::info("EXPERIMENTAL update already passed!!! customer: ".$customer2. " maillist: ".$subscriber->mailList->uid);


            // Log
            $subscriber->log('created', $customer);

            // Redirect to my lists page
            $request->session()->flash('alert-success', trans('messages.subscriber.created'));

            return redirect()->action('SubscriberController@index', $list->uid);

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
    public function edit(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);
        $subscriber = \Acelle\Model\Subscriber::findByUid($request->uid);

        // authorize
//        if (\Gate::denies('update', $subscriber)) {
//            return $this->notAuthorized();
//        }

        $prev = redirect()->getUrlGenerator()->previous();
        $request->session()->put('urlas', $prev);

        // Get old post values
        $values = [];
        foreach ($list->getFields as $key => $field) {
            $values[$field->tag] = $subscriber->getValueByField($field);
        }
        if (null !== $request->old()) {
            foreach ($request->old() as $key => $value) {
                if (is_array($value)) {
                    $values[str_replace('[]', '', $key)] = implode(',', $value);
                } else {
                    $values[$key] = $value;
                }
            }
        }

        return view('subscribers.edit', [
            'list' => $list,
            'subscriber' => $subscriber,
            'values' => $values,
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
        $customer = $request->user()->customer;
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);
        $subscriber = \Acelle\Model\Subscriber::findByUid($request->uid);


        $prev = $request->session()->pull('urlas', '');

        // authorize
//        if (\Gate::denies('update', $subscriber)) {
//            return $this->notAuthorized();
//        }

        // validate and save posted data
        if ($request->isMethod('patch')) {
            $this->validate($request, $subscriber->getRules());

            // Update field
            $subscriber->updateFields($request->all());

           // event(new \Acelle\Events\MailListUpdated($subscriber->mailList));
            // new implementation uses external API to RabiitMQ
            MailLog::info("EXPERIMENTAL MailList update is initiated!!!");
            $taskrunner = New TaskRunner();
            $customer2= $customer->id;
            $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_LIST_UPDATE,$customer2,$taskrunner::PRIORITY_LOW,$subscriber->mailList->uid);
            MailLog::info("EXPERIMENTAL update already passed!!! customer: ".$customer2. " maillist: ".$subscriber->mailList->uid);

            // Log
            $subscriber->log('updated', $customer);

            // Redirect to my lists page
            $request->session()->flash('alert-success', trans('messages.subscriber.updated'));
            if ($prev != "") {
                return redirect($prev);

            } else {
                return redirect()->action('SubscriberController@index', $list->uid);
            }
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
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
        $customer = $request->user()->customer;
        $subscribers = \Acelle\Model\Subscriber::whereIn('uid', explode(',', $request->uids));

        // get related mail lists to update the cached information
        $lists = $subscribers->get()->map(function($e) { return \Acelle\Model\MailList::find($e->mail_list_id); })->unique();

        // actually delete the subscriber
        foreach ($subscribers->get() as $subscriber) {
            // authorize
         /*   if (\Gate::denies('delete', $subscriber)) {
                return;
            }
         */
        }

        foreach ($subscribers->get() as $subscriber) {
            $subscriber->delete();

            // Log
            $subscriber->log('deleted', $customer);
        }

        foreach ($lists as $list) {
         //   event(new \Acelle\Events\MailListUpdated($list));
            // new implementation uses external API to RabiitMQ
            MailLog::info("EXPERIMENTAL MailList update is initiated!!!");
            $taskrunner = New TaskRunner();
            $customer2= $customer->id;
            $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_LIST_UPDATE,$customer2,$taskrunner::PRIORITY_LOW,$list->uid);
            MailLog::info("EXPERIMENTAL update already passed!!! customer: ".$customer2. " maillist: ".$list->uid);
        }

        // Redirect to my lists page
        echo trans('messages.subscribers.deleted');
    }

    /**
     * Subscribe subscriber.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function subscribe(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);
        $customer = $request->user()->customer;

        if ($request->select_tool == 'all_items') {
            $subscribers = $this->search($list, $request);
        } else {
            $subscribers = \Acelle\Model\Subscriber::whereIn('uid', explode(',', $request->uids));
        }

        foreach ($subscribers->get() as $subscriber) {
            // authorize
        //    if (\Gate::allows('subscribe', $subscriber)) {
                $subscriber->status = 'subscribed';
                $subscriber->save();
                // update MailList cache
        //        event(new \Acelle\Events\MailListUpdated($subscriber->mailList));
            // new implementation uses external API to RabiitMQ
            MailLog::info("EXPERIMENTAL MailList update is initiated!!!");
            $taskrunner = New TaskRunner();
            $customer2= $customer->id;
            $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_LIST_UPDATE,$customer2,$taskrunner::PRIORITY_LOW,$subscriber->mailList->uid);
            MailLog::info("EXPERIMENTAL update already passed!!! customer: ".$customer2. " maillist: ".$subscriber->mailList->uid);

                // Log
                $subscriber->log('subscribed', $customer);
          // }
        }

        // Redirect to my lists page
        echo trans('messages.subscribers.subscribed');
    }

    /**
     * Unsubscribe subscriber.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function unsubscribe(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);
        $customer = $request->user()->customer;

        if ($request->select_tool == 'all_items') {
            $subscribers = $this->search($list, $request);
        } else {
            $subscribers = \Acelle\Model\Subscriber::whereIn('uid', explode(',', $request->uids));
        }

        foreach ($subscribers->get() as $subscriber) {
            // authorize
       //     if (\Gate::allows('unsubscribe', $subscriber)) {
                $subscriber->status = 'unsubscribed';
                $subscriber->save();

                // Log
                $subscriber->log('unsubscribed', $customer);

                // update MailList cache
               // event(new \Acelle\Events\MailListUpdated($subscriber->mailList));
            // new implementation uses external API to RabiitMQ
            MailLog::info("EXPERIMENTAL MailList update is initiated!!!");
            $taskrunner = New TaskRunner();
            $customer2= $customer->id;
            $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_LIST_UPDATE,$customer2,$taskrunner::PRIORITY_LOW,$subscriber->mailList->uid);
            MailLog::info("EXPERIMENTAL update already passed!!! customer: ".$customer2. " maillist: ".$subscriber->mailList->uid);
         //   }
        }

        // Redirect to my lists page
        echo trans('messages.subscribers.unsubscribed');
    }

    /**
     * Import from file.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function import(Request $request)
    {
        $customer = $request->user()->customer;
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);
        // generate all lists array
        $listai = \Acelle\Model\MailList::getAll()->get();


        $system_jobs = $list->importJobs();

        // authorize
//        if (\Gate::denies('import', $list)) {
//            return $this->notAuthorized();
//        }

        if ($request->isMethod('post')) {
            if ($request->hasFile('file')) {
                // Start system job

                MailLog::info("Import queued list id: $list->id file: ".$request->file('file')->path());
             //   $job = new \Acelle\Jobs\ImportSubscribersJob($list, $request->user()->customer, $request->file('file')->path(),$request->listai);
                $job = new \Acelle\Jobs\ImportSubscribersJob($list, $request->user()->customer, $request->file('file')->path(),$request->listai);
            //    MailLog::info("Listai: ".print_r($request->listai,true));
                $this->dispatch($job);

                // Action Log
                $list->log('import_started', $request->user()->customer);
            } else {
                // @note: use try/catch instead
                echo "max_file_upload";
            }
        } else {
            return view('subscribers.import', [
                'list' => $list,
                'listai' => $listai,
                'system_jobs' => $system_jobs
            ]);
        }
    }

    /**
     * Check import proccessing.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function importProccess(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->current_list_uid);
        $system_job = $list->getLastImportJob();

        // authorize
//        if (\Gate::denies('import', $list)) {
//            return $this->notAuthorized();
//        }

        if(!is_object($system_job)) {
            return "none";
        }

        // authorize
//        if (\Gate::denies('import', $list)) {
//            return $this->notAuthorized();
//        }

        // Messages
        $message = \Acelle\Helpers\ImportSubscribersHelper::getMessage($system_job);

        return response()->json([
            "job" => $system_job,
            "data" => json_decode($system_job->data),
            "timer" => $system_job->runTime(),
            "message" => $message,
        ]);
    }

    /**
     * Download import log.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     * @todo move this to the MailList controller
     */
    public function downloadImportLog(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);

        // authorize
      /*  if (\Gate::denies('import', $list)) {
            return $this->notAuthorized();
        }*/

        // @todo: should be the exact MailList here
        $log = $list->getLastImportLog();
        // @todo what if log does not exist (removed)?
        return response()->download($log);
    }

    /**
     * Display a listing of subscriber import job.
     *
     * @return \Illuminate\Http\Response
     */
    public function importList(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);

        // authorize
    /*    if (\Gate::denies('import', $list)) {
            return $this->notAuthorized();
        }*/

        $system_jobs = $list->importJobs();
        $system_jobs = $system_jobs->orderBy($request->sort_order, $request->sort_direction);
        $system_jobs = $system_jobs->paginate($request->per_page);

        return view('subscribers._import_list', [
            'system_jobs' => $system_jobs,
            'list' => $list
        ]);
    }

    // Reset subscribtion status to subscribed
    public function recover(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);
        // FIXME for faster update we can remove classification by status = "unsubscribed"
        \DB::table('subscribers')->where([ 'mail_list_id' => $list->id, 'status' => 'unsubscribed'])->update(['status' => 'subscribed']);
        $request->session()->flash('alert-success', 'Subscribe status updated');
        return redirect()->action('SubscriberController@index', $list->uid);
    }


    // remove contacts
    public function remove(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);
        $subscribers = \Acelle\Model\Subscriber::whereIn('mail_list_id', [ $list->id ]);
        foreach ($subscribers->get() as $subscriber) {
            $subscriber->delete();
        }


        //event(new \Acelle\Events\MailListUpdated($list));
        // new implementation uses external API to RabiitMQ
        MailLog::info("EXPERIMENTAL MailList update is initiated!!!");
        $taskrunner = New TaskRunner();
        $customer2 = $request->user()->customer;
        $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_LIST_UPDATE,$customer2,$taskrunner::PRIORITY_LOW,$list->uid);
        MailLog::info("EXPERIMENTAL update already passed!!! customer: ".$customer2. " maillist: ".$list->uid);

        $request->session()->flash('alert-success', 'Contacts removed!');
        return redirect()->action('MailListController@index', $list->uid);
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

    public function exportasfunc(Request $request)
    {
        // 1 openers
        // 2 without hardbounces
        $sql = "";
        switch ($request->type) {
            case 1:
                $sql = \DB::select("select subscribers.email from open_logs left join tracking_logs ON open_logs.message_id = tracking_logs.message_id inner join subscribers on tracking_logs.subscriber_id = subscribers.id");
                break;
            case 2:
                $sql = \DB::select("select email from subscribers inner join mail_lists on subscribers.mail_list_id = mail_lists.id where subscribers.status = 'subscribed'");
                break;
        }

        $filename = uniqid('down_', true).'.csv';
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


    public function searchingexport(Request $request)
    {
    MailLog::info("Trying to export phase: $request->search on list $request->id");

    $sql = \DB::select("select distinct(subscribers.email) from subscribers where subscribers.mail_list_id = $request->id and subscribers.email like '%$request->search%'");

        $filename = uniqid('down_', true).'.csv';
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

    // funkcija is campaign overview chart eksportuoja delivered emailus
    public function export_from_chart(Request $request) {
        // 1 deliveries
        // 2 bounces
        // 3 deferred
        //print "export is campaign: ".$request->uid." type: ".$request->type;
        $sql = "";
        switch ($request->type) {
            case 1:
                $title = 'deliveries';
                $redis_k = $request->uid."_sent_data";
                break;
            case 2:
                $title = 'bounces_deferred';
                $redis_k = $request->uid."_undelivered_data";
                break;
//            case 3:
//                $title = 'unsubscribers';
//                $sql = \DB::select("select distinct(subscribers.email) from subscribers inner join tracking_logs on tracking_logs.subscriber_id = subscribers.id inner join campaigns_lists_segments on subscribers.mail_list_id = campaigns_lists_segments.mail_list_id where subscribers.status = 'unsubscribed' AND campaigns_lists_segments.campaign_id = $request->uid");
//                break;
//            case 4:
//                $title = 'notopeners';
//                $sql = \DB::select("select distinct(subscribers.email) from subscribers inner join tracking_logs on tracking_logs.subscriber_id = subscribers.id inner join campaigns_lists_segments on subscribers.mail_list_id = campaigns_lists_segments.mail_list_id where subscribers.email NOT IN (select email from blacklists) AND subscribers.id NOT IN (select tracking_logs.subscriber_id from tracking_logs inner join open_logs on open_logs.message_id = tracking_logs.message_id) AND campaigns_lists_segments.campaign_id = $request->uid");
//                break;
        }

        $keys = \Redis::hgetall($redis_k);
        $filename = uniqid('down_'.$title.'_', true).'.csv';
        $file = fopen($filename, 'w');
        $this->fputcsv2($file, array('EMAIL'));
        foreach ($keys as $key => $val) {
            $this->fputcsv2($file, array($key));
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


    // funkcija is campaign overview iseksportuoja ivairius kontaktus pagal tipa
    public function export_from_campaigns(Request $request)
    {
        // 1 openers
        // 2 clickers
        // 3 unsubscribers
        // 4 exludes openers and hardbounces
                print "export is campaign: ".$request->uid." type: ".$request->type;
        $sql = "";
        switch ($request->type) {
            case 1:
                $title = 'openers';
                $sql = \DB::select("select distinct(subscribers.email) from open_logs left join tracking_logs ON open_logs.message_id = tracking_logs.message_id inner join subscribers on tracking_logs.subscriber_id = subscribers.id inner join campaigns_lists_segments on subscribers.mail_list_id = campaigns_lists_segments.mail_list_id where campaigns_lists_segments.campaign_id = $request->uid");
                break;
            case 2:
                $title = 'clickers';
                $sql = \DB::select("select distinct(subscribers.email) from click_logs left join tracking_logs ON click_logs.message_id = tracking_logs.message_id inner join subscribers on tracking_logs.subscriber_id = subscribers.id inner join campaigns_lists_segments on subscribers.mail_list_id = campaigns_lists_segments.mail_list_id where campaigns_lists_segments.campaign_id = $request->uid");
                break;
            case 3:
                $title = 'unsubscribers';
                $sql = \DB::select("select distinct(subscribers.email) from subscribers inner join tracking_logs on tracking_logs.subscriber_id = subscribers.id inner join campaigns_lists_segments on subscribers.mail_list_id = campaigns_lists_segments.mail_list_id where subscribers.status = 'unsubscribed' AND campaigns_lists_segments.campaign_id = $request->uid");
                break;
            case 4:
                $title = 'notopeners';
                $sql = \DB::select("select distinct(subscribers.email) from subscribers inner join tracking_logs on tracking_logs.subscriber_id = subscribers.id inner join campaigns_lists_segments on subscribers.mail_list_id = campaigns_lists_segments.mail_list_id where subscribers.email NOT IN (select email from blacklists) AND subscribers.id NOT IN (select tracking_logs.subscriber_id from tracking_logs inner join open_logs on open_logs.message_id = tracking_logs.message_id) AND campaigns_lists_segments.campaign_id = $request->uid");
                break;
        }

        $filename = uniqid('down_'.$title.'_', true).'.csv';
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

    public function exportasfunc2(Request $request)
    {
        // 1 openers
        // 2 clickers
        $sql = "";
        switch ($request->type) {
            case 1:
                $sql = \DB::select("select distinct(subscribers.email) from open_logs left join tracking_logs ON open_logs.message_id = tracking_logs.message_id inner join subscribers on tracking_logs.subscriber_id = subscribers.id where subscribers.mail_list_id = $request->id");
                break;
            case 2:
                $sql = \DB::select("select distinct(subscribers.email) from click_logs left join tracking_logs ON click_logs.message_id = tracking_logs.message_id inner join subscribers on tracking_logs.subscriber_id = subscribers.id where subscribers.mail_list_id = $request->id");
                break;
        }

        $filename = uniqid('down_', true).'.csv';
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

    public function exportas(Request $request)
    {
        MailLog::info("export openers initiated!");
        $prev = redirect()->getUrlGenerator()->previous();

        header('Content-Disposition: attachment; filename="export.csv"');
        header("Cache-control: private");
        header("Content-type: application/force-download");
        header("Content-transfer-encoding: binary\n");

        echo "EMAIL\n";
        $campaignai = \DB::select("SELECT campaigns.id FROM `campaigns_lists_segments` inner join campaigns on campaigns_lists_segments.campaign_id = campaigns.id where mail_list_id = $request->list_uid");
// foreachinam pagal visus campaignus susijusius su tais maillistu id
        foreach ($campaignai as $campaign) {
            //echo $campaign->id."aa";
            $openai = \DB::select("select * from open_logs left join tracking_logs ON open_logs.message_id = tracking_logs.message_id inner join subscribers on tracking_logs.subscriber_id = subscribers.id where tracking_logs.campaign_id = $campaign->id");
            foreach ($openai as $openas) {
                // rasom kiekviena emaila i faila
            echo $openas->email."\n";
            }

        }
exit;


        //header("Location: $prev");
    }

// export without hard bounces
    public function exportas_without_bounces(Request $request)
    {
        MailLog::info("export subscribers without bounces! ".$request->list_uid);
        $prev = redirect()->getUrlGenerator()->previous();
        header('Content-Disposition: attachment; filename="export.csv"');
        header("Cache-control: private");
        header("Content-type: application/force-download");
        header("Content-transfer-encoding: binary\n");
        echo "EMAIL\n";
            $openai = \DB::select("select email from subscribers where status = 'subscribed' and mail_list_id = $request->list_uid");
            foreach ($openai as $openas) {
                echo $openas->email."\n";
            }


        exit;
        //header("Location: $prev");
    }


    /**
     * Export to csv.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function export(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);

        // authorize
        /*if (\Gate::denies('export', $list)) {
            return $this->notAuthorized();
        }*/

        $system_jobs = $list->exportJobs();

        $customer = $request->user()->customer;

        // authorize
      /*  if (\Gate::denies('export', $list)) {
            return $this->notAuthorized();
        }*/

        if ($request->isMethod('post')) {

            // Start system job
            //$job = (new \Acelle\Jobs\ExportSubscribersJob($list, $request->user()->customer))->onQueue('high');
            // for newest laravel version
            $job = new \Acelle\Jobs\ExportSubscribersJob($list, $request->user()->customer);
            $this->dispatch($job);
            // Action Log
            $list->log('export_started', $request->user()->customer);
        } else {
            return view('subscribers.export', [
                'list' => $list,
                'system_jobs' => $system_jobs
            ]);
        }
    }

    /**
     * Check export proccessing.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function exportProccess(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->current_list_uid);
        $system_job = $list->getLastExportJob();

        // authorize
      /*  if (\Gate::denies('export', $list)) {
            return $this->notAuthorized();
        }*/

        if(!is_object($system_job)) {
            return "none";
        }

        // authorize
//        if (\Gate::denies('export', $list)) {
//            return $this->notAuthorized();
//        }

        return response()->json([
            "job" => $system_job,
            "data" => json_decode($system_job->data),
            "timer" => $system_job->runTime(),
        ]);
    }

    /**
     * Download exported csv file after exporting.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function downloadExportedCsv(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);

        // authorize
//        if (\Gate::denies('export', $list)) {
//            return $this->notAuthorized();
//        }

        $system_job = $list->getLastExportJob();

        return response()->download(storage_path('job/'.$system_job->id.'/data.csv'));
    }

    /**
     * Display a listing of subscriber import job.
     *
     * @return \Illuminate\Http\Response
     */
    public function exportList(Request $request)
    {
        $list = \Acelle\Model\MailList::findByUid($request->list_uid);

        // authorize
//        if (\Gate::denies('export', $list)) {
//            return $this->notAuthorized();
//        }

        $system_jobs = $list->exportJobs();
        $system_jobs = $system_jobs->orderBy($request->sort_order, $request->sort_direction);
        $system_jobs = $system_jobs->paginate($request->per_page);

        return view('subscribers._export_list', [
            'system_jobs' => $system_jobs,
            'list' => $list
        ]);
    }

    /**
     * Copy subscribers to lists.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function copy(Request $request)
    {
        $from_list = \Acelle\Model\MailList::findByUid($request->from_uid);
        $to_list = \Acelle\Model\MailList::findByUid($request->to_uid);

        if ($request->select_tool == 'all_items') {
            $subscribers = $this->search($from_list, $request)->select('subscribers.*');
        } else {
            $subscribers = \Acelle\Model\Subscriber::whereIn('uid', explode(',', $request->uids));
        }

        foreach ($subscribers->get() as $subscriber) {
            // authorize
          //  if (\Gate::allows('update', $to_list)) {
                $subscriber->copy($to_list, $request->type);
            // }
        }

        // Trigger updating related campaigns cache
        //event(new \Acelle\Events\MailListUpdated($to_list));
        // new implementation uses external API to RabiitMQ
        MailLog::info("EXPERIMENTAL MailList update is initiated!!!");
        $taskrunner = New TaskRunner();
        $customer2 = $request->user()->customer;
        $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_LIST_UPDATE,$customer2,$taskrunner::PRIORITY_LOW,$to_list->uid);
        MailLog::info("EXPERIMENTAL update already passed!!! customer: ".$customer2. " maillist: ".$to_list->uid);

        // Log
        $to_list->log('copied', $request->user()->customer, [
            'count' => $subscribers->count(),
            'from_uid' => $from_list->uid,
            'to_uid' => $to_list->uid,
            'from_name' => $from_list->name,
            'to_name' => $to_list->name,
        ]);

        // Redirect to my lists page
        echo trans('messages.subscribers.copied');
    }

    /**
     * Move subscribers to lists.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function move(Request $request)
    {
        $from_list = \Acelle\Model\MailList::findByUid($request->from_uid);
        $to_list = \Acelle\Model\MailList::findByUid($request->to_uid);

        if ($request->select_tool == 'all_items') {
            $subscribers = $this->search($from_list, $request)->select('subscribers.*');
        } else {
            $subscribers = \Acelle\Model\Subscriber::whereIn('uid', explode(',', $request->uids));
        }

        foreach ($subscribers->get() as $subscriber) {
            // authorize
        //    if (\Gate::allows('update', $to_list)) {
                $subscriber->move($to_list, $request->type);
          //  }
        }

        // Trigger updating related campaigns cache
       // event(new \Acelle\Events\x$from_list));
       // event(new \Acelle\Events\MailListUpdated($to_list));
        // new implementation uses external API to RabiitMQ
        MailLog::info("EXPERIMENTAL MailList update is initiated!!!");
        $taskrunner = New TaskRunner();
        $customer2 = $request->user()->customer;
        $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_LIST_UPDATE,$customer2,$taskrunner::PRIORITY_LOW,$from_list->uid);
        $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_LIST_UPDATE,$customer2,$taskrunner::PRIORITY_LOW,$to_list->uid);
        MailLog::info("EXPERIMENTAL update already passed!!! customer: ".$customer2. " maillist: ".$from_list->uid);

        // Log
        $to_list->log('moved', $request->user()->customer, [
            'count' => $subscribers->count(),
            'from_uid' => $from_list->uid,
            'to_uid' => $to_list->uid,
            'from_name' => $from_list->name,
            'to_name' => $to_list->name,
        ]);

        // Redirect to my lists page
        echo trans('messages.subscribers.moved');
    }

    /**
     * Copy Move subscribers form.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function copyMoveForm(Request $request)
    {
        $from_list = \Acelle\Model\MailList::findByUid($request->from_uid);

        if ($request->select_tool == 'all_items') {
            $subscribers = $this->search($from_list, $request);
        } else {
            $subscribers = \Acelle\Model\Subscriber::whereIn('uid', explode(',', $request->uids));
        }

        return view('subscribers.copy_move_form', [
            'subscribers' => $subscribers,
            'from_list' => $from_list
        ]);
    }

    /**
     * Start the verification process
     *
     */
    public function startVerification(Request $request)
    {
        $subscriber = Subscriber::findByUid($request->uid);
        $server = EmailVerificationServer::findByUid($request->email_verification_server_id);
        try {
            $subscriber->verify($server);

            // success message
            $request->session()->flash('alert-success', trans('messages.verification.finish'));

            // update MailList cache
            //event(new \Acelle\Events\MailListUpdated($subscriber->mailList));
            // new implementation uses external API to RabiitMQ
            MailLog::info("EXPERIMENTAL MailList update is initiated!!!");
            $taskrunner = New TaskRunner();
            $customer2 = $request->user()->customer;
            $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_LIST_UPDATE,$customer2,$taskrunner::PRIORITY_LOW,$subscriber->mailList->uid);
            MailLog::info("EXPERIMENTAL update already passed!!! customer: ".$customer2. " maillist: ".$subscriber->mailList->uid);

            return redirect()->action('SubscriberController@edit', ['list_uid' => $request->list_uid, 'uid' => $subscriber->uid]);
        } catch (\Exception $e) {
            MailLog::error(sprintf("Something went wrong while verifying %s (%s). Error message: %s", $subscriber->email, $subscriber->id, $e->getMessage()));
            return view('somethingWentWrong', ['message' => sprintf("Something went wrong while verifying %s (%s). Error message: %s", $subscriber->email, $subscriber->id, $e->getMessage())]);
        }
    }

    /**
     * Reset the verification data
     *
     */
    public function resetVerification(Request $request)
    {
        $subscriber = Subscriber::findByUid($request->uid);

        try {
            MailLog::info(sprintf("Cleaning up verification data for %s (%s)", $subscriber->email, $subscriber->id));
            $subscriber->emailVerification->delete();
            // success message
            $request->session()->flash('alert-success', trans('messages.verification.reset'));

            MailLog::info(sprintf("Finish cleaning up verification data for %s (%s)", $subscriber->email, $subscriber->id));
            return redirect()->action('SubscriberController@edit', ['list_uid' => $request->list_uid, 'uid' => $subscriber->uid]);
        } catch (\Exception $e) {
            MailLog::error(sprintf("Something went wrong while cleaning up verification data for %s (%s). Error message: %s", $subscriber->email, $subscriber->id, $e->getMessage()));
            return view('somethingWentWrong', ['message' => sprintf("Something went wrong while cleaning up verification data for %s (%s). Error message: %s", $subscriber->email, $subscriber->id, $e->getMessage())]);
        }
    }
}
