<?php

namespace Acelle\Http\Controllers;

use Illuminate\Http\Request;
use Acelle\Http\Controllers\Controller;
use Acelle\Library\Log as MailLog;

class ServGroupController extends Controller
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
        $groups = \Acelle\Model\ServerGroups::search($request);

        return $groups;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $customer = $request->user()->customer;

//        if (!$customer->can('read', new \Acelle\Model\Blacklist())) {
//            return $this->notAuthorized();
//        }

        $groups = $this->search($request);

        # Get current job
       // $system_job = $customer->getLastActiveImportBlacklistJob();

        return view('servgroups.index', [
            'groups' => $groups,
        ]);
    }



    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listing(Request $request)
    {
//        if (!$request->user()->customer->can('read', new \Acelle\Model\Blacklist())) {
//            return $this->notAuthorized();
//        }

        $groups = $this->search($request)->paginate($request->per_page);

        return view('servgroups._list', [
            'groups' => $groups,
        ]);
    }

    public function item_add(Request $request)
    {
        if ($request->isMethod('post')) {
            \DB::table('servergroups')->insert(['name' => $request->name]);
        return redirect('admin/servgroups');

        }


        return view('servgroups.create');

    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $group = new \Acelle\Model\ServerGroups();

        $group->fill($request->old());

        // authorize
//        if (!$request->user()->customer->can('create', $blacklist)) {
//            return $this->notAuthorized();
//        }

        return view('servgroups.create', [
            'group' => $group,
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
        $group = new \Acelle\Model\ServerGroups();

        // authorize
//        if (!$request->user()->customer->can('create', $blacklist)) {
//            return $this->notAuthorized();
//        }

        // save posted data
        if ($request->isMethod('post')) {
           // $this->validate($request, \Acelle\Model\Blacklist::rules());



            // Save current user info
            $group->fill($request->all());
            //$blacklist->customer_id = $request->user()->customer->id;
            //$blacklist->status = 'active';


            if ($group->save()) {
                // Log
             //   $blacklist->log('created', $request->user()->customer);

                $request->session()->flash('alert-success', 'Insert group complete!');
                return redirect()->action('ServGroupController@index');
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
        $group = \Acelle\Model\ServerGroups::findByUid($id);

        // authorize
//        if (!$request->user()->customer->can('update', $blacklist)) {
//            return $this->notAuthorized();
//        }

        $group->fill($request->old());

        return view('servgroups.edit', [
            'group' => $group,
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
        $group = \Acelle\Model\ServerGroups::findByUid($id);

        // authorize
//        if (!$request->user()->customer->can('update', $blacklist)) {
//            return $this->notAuthorized();
//        }

        // save posted data
        if ($request->isMethod('patch')) {
            $this->validate($request, \Acelle\Model\ServerGroups::rules());

            // Save current user info
            $group->fill($request->all());

            if ($group->save()) {
                // Log
                //$blacklist->log('updated', $request->user()->customer);

                $request->session()->flash('alert-success', 'Group updated!');
                return redirect()->action('ServGroupController@index');
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
            $groups = $this->search($request);
        } else {
            $groups = \Acelle\Model\ServerGroups::whereIn('id', explode(',', $request->uids));
        }

        foreach ($groups->get() as $group) {
            // authorize
           // if ($request->user()->customer->can('delete', $group)) {
                // Log
            \DB::unprepared("UPDATE sending_servers set servgroup=0 where servgroup = $group->id");
                $group->delete();
            //}
        }

        // Redirect to my lists page
        echo 'Deleted!';
    }

    public function deleteall(Request $request) {
        \Acelle\Model\ServerGroups::getAll()->delete();
        $request->session()->flash('alert-success', 'Group list is now empty!');
        return redirect()->action('BlacklistController@index');
    }

    /**
     * Start import process.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
//    public function import(Request $request)
//    {
//        $customer = $request->user()->customer;
//
//        if ($request->isMethod('post')) {
//            // authorize
//            if (!$request->user()->customer->can('import', new \Acelle\Model\Blacklist())) {
//                return $this->notAuthorized();
//            }
//
//            if ($request->hasFile('file')) {
//                // Start system job
//                $job = (new \Acelle\Jobs\ImportBlacklistJob($request->file('file')->path(),$request->comment, $request->user()->customer))->onQueue('high');
//                $this->dispatch($job);
//            } else {
//                // @note: use try/catch instead
//                echo "max_file_upload";
//            }
//        }
//
//        // Get current job
//        $system_job = $customer->getLastActiveImportBlacklistJob();
//
//        return view('blacklists.import', [
//            'system_job' => $system_job
//        ]);
//    }

    /**
     * Check import proccessing.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
//    public function importProcess(Request $request)
//    {
//        $customer = $request->user()->customer;
//        $system_job = \Acelle\Model\SystemJob::find($request->system_job_id);
//
//        // authorize
//        if (!$customer->can('read', new \Acelle\Model\Blacklist())) {
//            return $this->notAuthorized();
//        }
//
//        return view('blacklists.import_process', [
//            'system_job' => $system_job
//        ]);
//    }

    /**
     * Cancel importing job.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
//    public function cancel(Request $request)
//    {
//        $customer = $request->user()->customer;
//        $system_job = $customer->getLastActiveImportBlacklistJob();
//
//        // authorize
//        if (!$customer->can('importCancel', new \Acelle\Model\Blacklist())) {
//            return $this->notAuthorized();
//        }
//
//        $system_job->setCancelled();
//
//        $request->session()->flash('alert-success', trans('messages.blacklist.import.cancelled'));
//        return redirect()->action('BlacklistController@index');
//    }
}
