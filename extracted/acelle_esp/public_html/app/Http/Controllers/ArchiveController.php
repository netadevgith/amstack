<?php

namespace Acelle\Http\Controllers;

use Acelle\Jobs\QueueBackgroundSendingJob;
use Acelle\Jobs\RetryCampagnSendJob;
use Acelle\Library\UserAgentHelper;
use Illuminate\Http\Request;
use SendGrid\Mail;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;
use Acelle\Library\Log as MailLog;
use Illuminate\Support\Facades\Log as LaravelLog;
use Illuminate\Support\Facades\Storage;
use Acelle\Library\StringHelper;
use Acelle\Model\Setting;
use DB;
use Redis;
use \Acelle\Jobs\RestartProcessesJob;
use Acelle\Library\DNSHelper;
use UA;

class ArchiveController extends Controller
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
        // we pass this argument to the view and then to the listing.js, soo long path to make it work properly
        $type = "archive";
        $customer = $request->user()->customer;
        $campaigns = $customer->getArchivedCampaigns();



        return view('campaigns.index', [ 'campaigns' => $campaigns, 'type' => $type, ]);
    }


}
