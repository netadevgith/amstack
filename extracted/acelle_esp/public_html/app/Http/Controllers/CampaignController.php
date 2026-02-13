<?php

namespace Acelle\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Acelle\Jobs\QueueBackgroundSendingJob;
use Acelle\Jobs\RetryCampagnSendJob;
use Acelle\Library\StorageHelper;
use Acelle\Library\TaskRunner;
use Acelle\Library\UserAgentHelper;
use Acelle\Model\Link;
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
use Acelle\Library\CloudFlareHelper;
use Acelle\Library\TrackHash;

class CampaignController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        parent::__construct();
//MailLog::info("Campaign controller");
        $this->middleware('auth', ['except' => [
            'open',
            'click',
            'click_new',
            'open_new',
            'unsubscribe',
            'reportabuse',
            'RedirectSource',
            'RedirectImage',
            'webView'
        ]]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $type = "normal";
        $customer = $request->user()->customer;
        $campaigns = $customer->getNormalCampaigns();


        return view('campaigns.index', [
            'campaigns' => $campaigns,
            'type' => $type,
        ]);
    }


    public function RedirectSource(Request $request)
    {
        return redirect('/source/'.$request->campaign_uid.'/'.$request->file);

    }

    public function RedirectImage(Request $request)
    {
        $DEBUG = 0;
        $path = public_path('source/' . $request->file);
        // remove the possible vulnerability...
        $path = str_replace('..','',$path);
        if ($DEBUG > 0)
        MailLog::info("Requested access image: ".$request->file);
        if (file_exists($path)) {
            if ($DEBUG > 0)
            MailLog::info("Loading image: ".$path);
            $file = \File::get($path);
            $type = \File::mimeType($path);
            $response = \Response::make($file, 200);
            $response->header("Content-Type", $type);

            return $response;
        }

    }

    public function simulation_status(Request $request)
    {
        header('Content-Type: application/json');
        if (Redis::exists($request->uid.'_simulation'))
        $log = Redis::get($request->uid.'_simulation');
        else $log = "";
        $json = json_encode(array('status_raw' => $log));
        return $json;
    }

    public function CampaignSimulationStop(Request $request) {
        MailLog::info("Stopping simuation for campaign: $request->uid");
        if (!\Redis::exists($request->uid.'_simulation_stopped'))
            \Redis::set($request->uid.'_simulation_stopped',1);
        header('Content-Type: application/json');
        return json_encode(["ok" => "ok"]);
    }

    // new implementation 2020.11.18 features all campaigns in one api call
    public function counter(Request $request)
    {
        header('Content-Type: application/json');
        $camps = Redis::keys("campaign_*_cache");
        $returns = array();
        foreach ($camps as $camp) {
            $uid = explode("_", $camp)[1];
            $counter = Redis::get($uid.'_counter') ?? 0;
            $total = Redis::get($uid.'_total') ?? 0;
            $openers = Redis::get($uid.'_openers') ?? 0;
            $clickers = Redis::get($uid.'_clickers') ?? 0;
            $status = "new";
            if (Redis::exists($uid)) $status = json_decode(Redis::get($uid))->status;
            $pause = 0;
            if (Redis::exists($uid.'_paused')) $pause = 1;
            $returns[] = array('uid'=> $uid,'counter' => $counter,'pause' => $pause, 'total' => $total, 'openers' => $openers, 'clickers' => $clickers, 'status' => $status);
        }
        return json_encode($returns);
    }

    public function old_counter(Request $request)
    {
        header('Content-Type: application/json');
        $cnt = Redis::get($request->uid.'_counter');
        $total = Redis::get($request->uid.'_total');
        $openers = Redis::get($request->uid.'_openers');
        $clickers = Redis::get($request->uid.'_clickers');
        if (Redis::exists($request->uid)) $status = json_decode(Redis::get($request->uid))->status;
        $pause = 0;
        if (Redis::exists($request->uid.'_paused')) $pause = 1;

        if (is_null($cnt)) $cnt = 0;
        if (is_null($total)) $total = 0;
        if (is_null($openers)) $openers = 0;
        if (is_null($clickers)) $clickers = 0;
        if (!isset($status)) $status = '';
        $counter = json_encode(array('counteris' => $cnt, 'pause' => $pause, 'total' => $total, 'openers' => $openers, 'clickers' => $clickers, 'status' => $status));
        return $counter;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listing(Request $request)
    {
        //\DB::enableQueryLog();
        MailLog::info("Listinimas: ".$request->type);

        $campaigns = \Acelle\Model\Campaign::search($request)->paginate($request->per_page);

        //print_r($campaigns);
       // MailLog::info(print_r(DB::getQueryLog(),true));

        return view('campaigns._list', [
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $customer = $request->user()->customer;
        $campaign = new \Acelle\Model\Campaign([
            'track_open' => true,
            'track_click' => true,
            'sign_dkim' => true,
        ]);

        // authorize
        if (\Gate::denies('create', $campaign)) {
            return $this->noMoreItem();
        }

        $campaign->name = trans('messages.untitled');
        $campaign->customer_id = $customer->id;
        $campaign->status = \Acelle\Model\Campaign::STATUS_NEW;
        $campaign->type = $request->type;
        $campaign->save();

        return redirect()->action('CampaignController@recipients', ['uid' => $campaign->uid]);
    }

    private function random_color() {
        $rand = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e', 'f');
        $color = '#'.$rand[rand(0,15)].$rand[rand(0,15)].$rand[rand(0,15)].$rand[rand(0,15)].$rand[rand(0,15)].$rand[rand(0,15)];
    return $color;
    }

    private function backgroundcolor($hour) {
        switch ($hour) {
            case 1:
                return "#4E4545";
                break;
            case 2:
                return "#473838";
                break;
            case 3:
                return "#473232";
                break;
            case 4:
                return "#32473E";
                break;
            case 5:
                return "#3A4939";
                break;
            case 6:
                return "#444939";
                break;
            case 7:
                return "#272255";
                break;
            case 8:
                return "#102F4A";
                break;
            case 9:
                return "#4A1C10";
                break;
            case 10:
                return "#441109";
                break;
            case 11:
                return "#614C14";
                break;
            case 12:
                return "#2F2C26";
                break;
        }
    }

    private function foregroundcolor($hour) {
        switch ($hour) {
            case 1:
                return "#EBB523";
                break;
            case 2:
                return "#E5C214";
                break;
            case 3:
                return "#F0DA14";
                break;
            case 4:
                return "#F0F014";
                break;
            case 5:
                return "#DAF014";
                break;
            case 6:
                return "#CCF014";
                break;
            case 7:
                return "#AEF014";
                break;
            case 8:
                return "#91F014";
                break;
            case 9:
                return "#B8F28C";
                break;
            case 10:
                return "#B1F6A9";
                break;
            case 11:
                return "#A9F6F6";
                break;
            case 12:
                return "#F6A9B1";
                break;
        }
    }

    private function warncolor($hour) {
        switch ($hour) {
            case 1:
                return "#F51228";
                break;
            case 2:
                return "#F5127C";
                break;
            case 3:
                return "#F512C7";
                break;
            case 4:
                return "#D012F6";
                break;
            case 5:
                return "#7C12F6";
                break;
            case 6:
                return "#3012F6";
                break;
            case 7:
                return "#123FF6";
                break;
            case 8:
                return "#128CF6";
                break;
            case 9:
                return "#12D8F6";
                break;
            case 10:
                return "#12F6EE";
                break;
            case 11:
                return "#12F6A2";
                break;
            case 12:
                return "#38F612";
                break;
        }
    }

    public function reportabuse(Request $request)
    {
        $received = 0;
        $error = 0;
        $error_msg = "";
        $zyklon = date('g'); // 12-hour format of an hour without leading zeros
        $layout['background_foreground'] = $this->foregroundcolor($zyklon);
        $layout['background'] = $this->backgroundcolor($zyklon);
        $layout['abuse_h2'] = $this->warncolor($zyklon);
        $layout['button'] = $this->random_color();
        try {

            try {
                $load_view = 'forms.abuse';
                $location = \Acelle\Model\IpLocation::add($_SERVER['REMOTE_ADDR']);
                $lang_page = strtolower($location->country_code);
                // if ($lang_page == "de") $lang_page = "fi";

                MailLog::info("Got watcher from $lang_page who are watching abuse form zyklon: $zyklon");
                if (view()->exists('forms.lang.' . $lang_page)) {
                    $load_view = 'forms.lang.' . $lang_page;
                }
            } catch (\Exception $ex) {
                MailLog::error("Unable to determine the client ip in abuse form");
                $load_view = 'forms.abuse';
            }


            if ($request->isMethod('post')) {
                try {
                    if (isset($request->email) && !empty($request->email) && filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
                        $reason = " First name: " . $request->name . " Last name: " . $request->last . " Reason: " . $request->report;
                        try {
                            if (\Config::get('app.storage') == true) {
                                $stor = new StorageHelper();
                                $stor->SubmitEmail($request->email,3,"Abuse form");
                            }
                            \DB::table('blacklist_abuse')->insert(['email' => $request->email, 'reason' => $reason, 'admin_id' => 1, 'customer_id' => 1, 'created_at' => \date('Y-m-d G:i:s'), 'updated_at' => \date('Y-m-d G:i:s')]);
                        } catch (\Exception $exas) {
                            MailLog::info("We already have the abuse email $request->email in our database");
                        }
                        $received = 1;
                        MailLog::info("Abuse form got submitted with email: $request->email");
                        \Acelle\Http\Controllers\Blacklist_abuseController::populate_redis((object) array('email' => $request->email, 'reason' => $reason));
                    } else {
                        $error = 1;
                        $error_msg = "Badly written email address";
                    }
                } catch (\Exception $ex) {
                    MailLog::error("Problem then posting the abuse form: " . $ex);
                    $error = 1;
                    $received = 0;
                }
            }

        } catch (\Exception $exas2) {
            MailLog::error("Error in report abuse global function...".$exas2);
        }


            return view($load_view, [
                 'received' => $received,
                 'error' => $error,
                'error_msg' => $error_msg,
                'layout' => $layout,
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
        $campaign = \Acelle\Model\Campaign::findByUid($id);

        // Trigger the CampaignUpdate event to update the campaign cache information
        // The second parameter of the constructor function is false, meanining immediate update
        try {
            event(new \Acelle\Events\CampaignUpdated($campaign));
        } catch (\Exception $ex) {
            // in case TrackingLog record does not exist yet (open before logged!)
        }

        // authorize
        if (\Gate::denies('read', $campaign)) {
            // return $this->notAuthorized();
        }

        if ($campaign->status == 'new') {
            return redirect()->action('CampaignController@edit', ['uid' => $campaign->uid]);
        } else {
            return redirect()->action('CampaignController@overview', ['uid' => $campaign->uid]);
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($id);

        // authorize
        /* testas HACK
        if (\Gate::denies('update', $campaign)) {
            return $this->notAuthorized();
        }
        */

        // Check step and redirect
        if ($campaign->step() == 0) {
            return redirect()->action('CampaignController@recipients', ['uid' => $campaign->uid]);
        } elseif ($campaign->step() == 1) {
            return redirect()->action('CampaignController@setup', ['uid' => $campaign->uid]);
        } elseif ($campaign->step() == 2) {
            return redirect()->action('CampaignController@template', ['uid' => $campaign->uid]);
        } elseif ($campaign->step() == 3) {
            return redirect()->action('CampaignController@schedule', ['uid' => $campaign->uid]);
        } elseif ($campaign->step() >= 4) {
            return redirect()->action('CampaignController@confirm', ['uid' => $campaign->uid]);
        }
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
            $item = \Acelle\Model\Campaign::findByUid($row[0]);

            // authorize
            if (\Gate::denies('sort', $item)) {
                // return $this->notAuthorized();
            }

            $item->custom_order = $row[1];
            $item->save();
        }

        echo trans('messages.campaigns.custom_order.updated');
    }

    /**
     * Recipients.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */

private function get_numeric($val) { 
if ($val == '') return 0;
  if (is_numeric($val)) { 
    return $val; 
  } 
  return 0; 
} 


    public function recipients(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('update', $campaign)) {
            // return $this->notAuthorized();
        }

        // Get rules and data
        $rules = $campaign->recipientsRules($request->all());
        $campaign->fillRecipients($request->all());

        if (!empty($request->old())) {
            $rules = $campaign->recipientsRules($request->old());
            $campaign->fillRecipients($request->old());
        }

        if ($request->isMethod('post')) {
            // Check validation
            $this->validate($request, $rules);

            if ($request->notopeners == true) {
                MailLog::info("I've got detected that the openers not sending options are applied!");
                $campaign->notopeners = 1;
                $campaign->save();
            } else {
                $campaign->notopeners = 0;
                $campaign->save();
            }

            $campaign->saveRecipients($request->all());



            // Trigger the CampaignUpdate event to update the campaign cache information
            // The second parameter of the constructor function is false, meanining immediate update
            event(new \Acelle\Events\CampaignUpdated($campaign));

            // redirect to the next step
            return redirect()->action('CampaignController@setup', ['uid' => $campaign->uid]);
        }

        //// validate and save posted data
        //if ($request->isMethod('post')) {
        //
        //    // Check validation
        //    $this->validate($request, \Acelle\Model\Campaign::$rules);
        //
        //    // Save campaign
        //    $campaign->mail_list_id = \Acelle\Model\MailList::findByUid($request->mail_list_uid)->id;
        //    if ($request->segment_uid) {
        //        $campaign->segment_id = \Acelle\Model\Segment::findByUid($request->segment_uid)->id;
        //    } else {
        //        $campaign->segment_id = null;
        //    }
        //    $campaign->save();
        //
        //    return redirect()->action('CampaignController@setup', ['uid' => $campaign->uid]);
        //}

        return view('campaigns.recipients', [
            'campaign' => $campaign,
            'rules' => $rules
        ]);
    }

    /**
     * Campaign setup.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */

    private function utf_to_latin($string) {
        $replace = [
            '&lt;' => '', '&gt;' => '', '&#039;' => '', '&amp;' => '',
            '&quot;' => '', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'Ae',
            '&Auml;' => 'A', 'Å' => 'A', 'Ā' => 'A', 'Ą' => 'A', 'Ă' => 'A', 'Æ' => 'Ae',
            'Ç' => 'C', 'Ć' => 'C', 'Č' => 'C', 'Ĉ' => 'C', 'Ċ' => 'C', 'Ď' => 'D', 'Đ' => 'D',
            'Ð' => 'D', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ē' => 'E',
            'Ę' => 'E', 'Ě' => 'E', 'Ĕ' => 'E', 'Ė' => 'E', 'Ĝ' => 'G', 'Ğ' => 'G',
            'Ġ' => 'G', 'Ģ' => 'G', 'Ĥ' => 'H', 'Ħ' => 'H', 'Ì' => 'I', 'Í' => 'I',
            'Î' => 'I', 'Ï' => 'I', 'Ī' => 'I', 'Ĩ' => 'I', 'Ĭ' => 'I', 'Į' => 'I',
            'İ' => 'I', 'Ĳ' => 'IJ', 'Ĵ' => 'J', 'Ķ' => 'K', 'Ł' => 'K', 'Ľ' => 'K',
            'Ĺ' => 'K', 'Ļ' => 'K', 'Ŀ' => 'K', 'Ñ' => 'N', 'Ń' => 'N', 'Ň' => 'N',
            'Ņ' => 'N', 'Ŋ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O',
            'Ö' => 'Oe', '&Ouml;' => 'Oe', 'Ø' => 'O', 'Ō' => 'O', 'Ő' => 'O', 'Ŏ' => 'O',
            'Œ' => 'OE', 'Ŕ' => 'R', 'Ř' => 'R', 'Ŗ' => 'R', 'Ś' => 'S', 'Š' => 'S',
            'Ş' => 'S', 'Ŝ' => 'S', 'Ș' => 'S', 'Ť' => 'T', 'Ţ' => 'T', 'Ŧ' => 'T',
            'Ț' => 'T', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'Ue', 'Ū' => 'U',
            '&Uuml;' => 'Ue', 'Ů' => 'U', 'Ű' => 'U', 'Ŭ' => 'U', 'Ũ' => 'U', 'Ų' => 'U',
            'Ŵ' => 'W', 'Ý' => 'Y', 'Ŷ' => 'Y', 'Ÿ' => 'Y', 'Ź' => 'Z', 'Ž' => 'Z',
            'Ż' => 'Z', 'Þ' => 'T', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a',
            'ä' => 'ae', '&auml;' => 'ae', 'å' => 'a', 'ā' => 'a', 'ą' => 'a', 'ă' => 'a',
            'æ' => 'ae', 'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c', 'ċ' => 'c',
            'ď' => 'd', 'đ' => 'd', 'ð' => 'd', 'è' => 'e', 'é' => 'e', 'ê' => 'e',
            'ë' => 'e', 'ē' => 'e', 'ę' => 'e', 'ě' => 'e', 'ĕ' => 'e', 'ė' => 'e',
            'ƒ' => 'f', 'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h',
            'ħ' => 'h', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
            'ĩ' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i', 'ĳ' => 'ij', 'ĵ' => 'j',
            'ķ' => 'k', 'ĸ' => 'k', 'ł' => 'l', 'ľ' => 'l', 'ĺ' => 'l', 'ļ' => 'l',
            'ŀ' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n', 'ņ' => 'n', 'ŉ' => 'n',
            'ŋ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'oe',
            '&ouml;' => 'oe', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o', 'ŏ' => 'o', 'œ' => 'oe',
            'ŕ' => 'r', 'ř' => 'r', 'ŗ' => 'r', 'š' => 's', 'ù' => 'u', 'ú' => 'u',
            'û' => 'u', 'ü' => 'ue', 'ū' => 'u', '&uuml;' => 'ue', 'ů' => 'u', 'ű' => 'u',
            'ŭ' => 'u', 'ũ' => 'u', 'ų' => 'u', 'ŵ' => 'w', 'ý' => 'y', 'ÿ' => 'y',
            'ŷ' => 'y', 'ž' => 'z', 'ż' => 'z', 'ź' => 'z', 'þ' => 't', 'ß' => 'ss',
            'ſ' => 'ss', 'ый' => 'iy', 'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G',
            'Д' => 'D', 'Е' => 'E', 'Ё' => 'YO', 'Ж' => 'ZH', 'З' => 'Z', 'И' => 'I',
            'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O',
            'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U', 'Ф' => 'F',
            'Х' => 'H', 'Ц' => 'C', 'Ч' => 'CH', 'Ш' => 'SH', 'Щ' => 'SCH', 'Ъ' => '',
            'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'YU', 'Я' => 'YA', 'а' => 'a',
            'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l',
            'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
            'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e',
            'ю' => 'yu', 'я' => 'ya'
        ];

        return str_replace(array_keys($replace), $replace, $string);

    }


    public function setup(Request $request)
    {
        $customer = $request->user()->customer;
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
      //  if (\Gate::denies('update', $campaign)) {
           // return $this->notAuthorized();
        //}

        $campaign->from_name = !empty($campaign->from_name) ? $campaign->from_name : $campaign->defaultMailList->from_name;
        // LEGACY
      //  $campaign->from_email = !empty($campaign->from_email) ? $campaign->from_email : $campaign->defaultMailList->from_email;
        // NEW impl
        $campaign->from_email = !empty($campaign->from_email) ? $campaign->from_email : "info@".$this->get_track_dom();
        $campaign->subject = !empty($campaign->subject) ? $campaign->subject : $campaign->defaultMailList->default_subject;
        $campaign->trackurl = !empty($campaign->trackurl) ? $campaign->trackurl : $campaign->defaultMailList->trackurl;
        $campaign->tracktype = !empty($campaign->tracktype) ? $campaign->tracktype : 0;
        $tracking = new \stdClass();
        $tracking->enabled = true;
        $tracking->headers = "";
        try {
            if (Redis::Exists($campaign->uid . "_headers")) {
                $trkobj = json_decode(Redis::Get($campaign->uid . "_headers"));
                $tracking->enabled = $trkobj->tracking_enabled;
                $tracking->headers = $trkobj->custom_headers_raw;
            }
        } catch (\Exception $ex) {
            MailLog::error("Unable to read headers from campaign: ".$campaign->uid);
        }

        // Deferred email implementation 2022.08.12
        $deferred = new \stdClass();
        $deferred->enabled = false;
        $deferred->wait = 0;
        try {
            if (Redis::Exists($campaign->uid."_deferred_setting")) {
                $defobj = json_decode(Redis::Get($campaign->uid."_deferred_setting"));
                $deferred->enabled = $defobj->enabled;
                $deferred->wait = $defobj->wait;
            }
        } catch (\Exception $ex) {
            MailLog::error("Unable to read deferred email settings for campaign: ".$campaign->uid);
        }

        // end of deferred email implementation


        if (!Redis::Exists('campaign_'.$campaign->uid.'_cache')) {
            Redis::set('campaign_'.$campaign->uid.'_cache',json_encode(array('SubscriberCount' => 0, 'DeliveredRate' => 0, 'DeliveredCount'=> 0, 'ClickedRate' => 0, 'UniqOpenRate'=> 0, 'UniqOpenCount'=> 0, 'NotOpenRate'=> 0,'NotOpenCount' =>0)));
        }

       // $campaign->subject = $this->utf_to_latin($campaign->subject);

//        $dns = new DNSHelper();
//        $dnsai = $dns->get_domains();
//        $trackdomenai[] = array();
//
//        //->map(function ($item) {
//        //return ['value' => $item->id, 'text' => $item->name];
//        $count = 0;
//        foreach ($dnsai as $dnsas) {
//            $trackdomenai = [ 'value' => $count, 'text' => $dnsas->name ];
//            $count++;
//        }



        $rules = array(
            'name' => 'required',
            'subject' => 'required',
            'from_email' => 'required|email',
            'from_name' => 'required',
        );

        // Get old post values
        if (null !== $request->old()) {
            $campaign->fill($request->old());
        }

        // validate and save posted data
        if ($request->isMethod('post')) {
            // Check validation
            $this->validate($request, $rules);
// CAMPAIGN ISSAUGOJIMAS
//print_r($request);
//die;
            // Save campaign
            $campaign->fill($request->all());
            $campaign->save();
            // custom headers save
            $headers_enabled = $request->tracking_headers;
            // set the variable, with removing the all white spaces
            $custom_headers = $request->input('custom_headers');
            try {
                $headobj = new \stdClass();
                $headobj->custom_headers = null;
                $headobj->custom_headers_raw = null;
                $headobj->tracking_enabled = true;
                if (\strlen($custom_headers) > 0) {
                    MailLog::info("Custom tracking headers: " . $custom_headers);
                    // parse the ini info from the string
                    $headers_ini = parse_ini_string($custom_headers,false);
                    MailLog::info("Got headers in ini: ".print_r($headers_ini,true));
                    $headobj->custom_headers = $headers_ini;
                    $headobj->custom_headers_raw = $custom_headers;
                }
                $headobj->tracking_enabled = $headers_enabled;
                if (\strlen($custom_headers) > 0 || $headers_enabled == false) {
                    Redis::set($campaign->uid . "_headers", json_encode($headobj));
                } else {
                    Redis::del($campaign->uid . "_headers");
                }

            } catch (\Exception $ex) {
                $request->session()->flash('alert-error', "Custom headers validation error!");
                MailLog::error("Failed to set the campaign: ".$campaign->uid." custom headers: ".$custom_headers." error: $ex");
            }

            // deferred emails save 2022.08.12
            $deferred_enabled = $request->deferred_enabled;
            $deferred_wait = $request->deferred_wait;
            if ($deferred_enabled == true) {
                $defnobj = new \stdClass();
                $defnobj->enabled = $deferred_enabled;
                $defnobj->wait = $deferred_wait;
                Redis::set($campaign->uid."_deferred_setting",json_encode($defnobj));
            } else {
                if (Redis::Exists($campaign->uid."_deferred_setting")) {
                    Redis::del($campaign->uid."_deferred_setting");
                }
            }

            // deferred emails save end

            // uzsetinam zero i redis backenda
            //$redis->set($campaign->uid, json_encode($campaign));

            // Log
            $campaign->log('created', $customer);

            return redirect()->action('CampaignController@template', ['uid' => $campaign->uid]);
        }

        return view('campaigns.setup', [
            'campaign' => $campaign,
            'rules' => $rules,
            'tracking' => $tracking,
            'deferred' => $deferred,
        ]);
    }

    /**
     * Template.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function template(Request $request)
    {
        $customer = $request->user()->customer;

        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        /* testas
        if (\Gate::denies('update', $campaign)) {
            return $this->notAuthorized();
        }
        */


        // Required tags
        $validate = 'required';

        // @todo hard-coded here
        $requiredTags = array(
            array('name' => '{UNSUBSCRIBE_URL}', 'required' => true),
        );


        foreach ($requiredTags as $tag) {
            if ($tag['required']) {
                $validate .= '|substring:"'.$tag['name'].'"';
            }
        }
        $rules = [];

        // if campaign type is not plain text
        if ($campaign->type != 'plain-text' && $customer->getOption('unsubscribe_url_required') == 'yes') {
            $rules['html'] = $validate;
        }

        // Get old post values
        if (null !== $request->old()) {
            $campaign->fill($request->old());
        }

        $rules = [];
        // validate and save posted data
        if ($request->isMethod('post')) {
            // Check validation
// FIXME template submitas
            $this->validate($request, $rules);

            // Save campaign
            $campaign->fill($request->all());
            // convert html to plain text if plain text is empty
            // DOWNLOAD IMAGES PART
            if ($campaign->tracktype == 0) {
                preg_match_all('/<img[^>]+src="([^">]+)"/i', $campaign->html, $matches);
                if (!file_exists(public_path() . '/source/' . $campaign->uid)) mkdir(public_path() . '/source/' . $campaign->uid);
                // foreach every found image link and download them step by step
                foreach ($matches[1] as $match) {
                    MailLog::info("Img: " . $match);
                    $baze = basename($match);
                    // INJECT REDIS CUSTOM SOURCE TO THE IMAGE !
                    $soursas = Redis::sRandMember('sourcepart');
                    if ($soursas == "") $soursas = "source";
                    MailLog::info("Random part for campaign selected: " . $soursas);
                    $url = asset('/' . $soursas . '/' . $campaign->uid . '/' . $baze);
                    $destination = public_path() . '/source/' . $campaign->uid . '/' . $baze;
                    if (!file_exists(public_path() . '/source/' . $campaign->uid . '/' . $baze)) {
                        // parsiunciame image faila
                        MailLog::info("Image neegzsistuoja, vyksta downloadas");
                        if (@getimagesize($match)) {
                            $data = file_get_contents($match);
                            file_put_contents($destination, $data);
                            MailLog::info("Atsiusta image: " . $destination);
                        }
                    }
                    // replace text to image source of new images
                    if (file_exists($destination)) {
                        $campaign->html = str_replace($match, $url, $campaign->html);
                        $campaign->plain = str_replace($match, $url, $campaign->plain);
                        MailLog::info("Edit campaign html $match with image url: " . $url);
                    }

                }
            } elseif ($campaign->tracktype == 1) {
                // new tracking type for the campaign
                preg_match_all('/<img[^>]+src="([^">]+)"/i', $campaign->html, $matches);
                $local_path = public_path('source/');
                //if (!file_exists(public_path() . '/source/' . $campaign->uid)) mkdir(public_path() . '/source/' . $campaign->uid);
                // foreach every found image link and download them step by step
                foreach ($matches[1] as $match) {
                    MailLog::info("Img: " . $match);
                    $baze = basename($match);
                    // INJECT REDIS CUSTOM SOURCE TO THE IMAGE !
                    //$soursas = Redis::sRandMember('sourcepart');
                    //if ($soursas == "") $soursas = "source";
                    //MailLog::info("Random part for campaign selected: " . $soursas);
                    $url = asset('/' . $baze);
                    $destination = $local_path . $baze;
                    if (!file_exists($local_path . $baze)) {
                        // parsiunciame image faila
                        MailLog::info("Image neegzsistuoja, vyksta downloadas");
                        if (@getimagesize($match)) {
                            $data = file_get_contents($match);
                            file_put_contents($destination, $data);
                            MailLog::info("Atsiusta image: " . $destination);
                        }
                    }
                    // replace text to image source of new images
                    if (file_exists($destination)) {
                        $campaign->html = str_replace($match, $url, $campaign->html);
                        $campaign->plain = str_replace($match, $url, $campaign->plain);
                        MailLog::info("Edit campaign html $match with image url: " . $url);
                    }
                }
            }
            // END DOWNLOAD IMAGES
            if (trim($request->plain) == '') {
                $campaign->plain = preg_replace('/\s+/',' ',preg_replace('/\r\n/',' ',strip_tags($request->html)));
            } else {
                // HACK
                $campaign->reply_to = $campaign->from_email;
// replace track domain

            }
            $campaign->save();



            if(isset($request->template_source)) {
                return redirect()->action('CampaignController@templatePreview', ['uid' => $campaign->uid]);
            } else {
                return redirect()->action('CampaignController@schedule', ['uid' => $campaign->uid]);
            }
        }

        // redirect page
        if(!empty($campaign->html) || $campaign->type == 'plain-text') {
            return redirect()->action('CampaignController@templatePreview', ['uid' => $campaign->uid]);
        } else {
            return redirect()->action('CampaignController@templateSelect', ['uid' => $campaign->uid]);
        }

        return view('campaigns.template', [
            'campaign' => $campaign,
            'rules' => $rules,
        ]);
    }

    /**
     * Select template type.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function templateSelect(Request $request)
    {
        $user = $request->user();
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('update', $campaign)) {
//            return $this->notAuthorized();
        }

        return view('campaigns.template_select', [
            'campaign' => $campaign,
        ]);
    }

    /**
     * Choose an existed template.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function templateChoose(Request $request)
    {
        $user = $request->user();
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $template = \Acelle\Model\Template::findByUid($request->template_uid);

        // authorize
        if (\Gate::denies('update', $campaign)) {
//            return $this->notAuthorized();
        }

        $campaign->html = $template->content;
        $campaign->template_source = $template->source;
        // $campaign->plain = preg_replace('/\s+/',' ',preg_replace('/\r\n/',' ',strip_tags($campaign->html)));
        $campaign->save();

        if(!$campaign->is_auto) {
            return redirect()->action('CampaignController@templatePreview', ['uid' => $campaign->uid]);
        } else {
            return redirect()->action('AutoEventController@templatePreview', ['uid' => $campaign->autoEvent()->uid, 'campaign_uid' => $campaign->uid]);
        }
    }

    /**
     * Template preview.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
private function get_track_dom()
{
    return \DB::table('settings')->where('id', 23)->first()->value;
}


    public function templatePreview(Request $request)
    {
        $user = $request->user();
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        $rules = [];

        // authorize
       /* testas if (\Gate::denies('update', $campaign)) {
            return $this->notAuthorized();
        }
       */
//print_r($campaign);
//print($campaign->plain);
// die;
//print_r($this->get_track_dom());
//die;
$dom = $this->get_track_dom();

        return view('campaigns.template_preview', [
            'campaign' => $campaign,
            'trackdomenas' => $dom,
            'rules' => $rules
        ]);
    }

    /**
     * Template preview iframe.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function templateIframe(Request $request)
    {
        $user = $request->user();
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        /* testas if (\Gate::denies('update', $campaign)) {
            return $this->notAuthorized();
        }
        */

        return view('campaigns.preview', [
            'campaign' => $campaign,
        ]);
    }

    /**
     * Schedule.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */

    public function schedule(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // check step
        if($campaign->step() < 3) {
            return redirect()->action('CampaignController@template', ['uid' => $campaign->uid]);
        }

        // authorize
        /* testas
        if (\Gate::denies('update', $campaign)) {
            return $this->notAuthorized();
        }
        */

        $delivery_date = isset($campaign->run_at) && $campaign->run_at != '0000-00-00 00:00:00' ? \Acelle\Library\Tool::dateTime($campaign->run_at)->format('Y-m-d') : \Acelle\Library\Tool::dateTime(\Carbon\Carbon::now())->format('Y-m-d');
        $delivery_time = isset($campaign->run_at) && $campaign->run_at != '0000-00-00 00:00:00' ? \Acelle\Library\Tool::dateTime($campaign->run_at)->format('H:i') : \Acelle\Library\Tool::dateTime(\Carbon\Carbon::now())->format('H:i');

        $rules = array(
            'delivery_date' => 'required',
            'delivery_time' => 'required',
        );

        // Get old post values
        if (null !== $request->old()) {

            $campaign->fill($request->old());
        }

        // validate and save posted data
        if ($request->isMethod('post')) {
            // Check validation
             $this->validate($request, $rules);

            //// Save campaign
            $time = \Acelle\Library\Tool::systemTimeFromString($request->delivery_date . ' ' . $request->delivery_time);
            $campaign->run_at = $time;
            // kazkada ateityje kita statusa galima bus implementuoti pvz. scheduled ar panasiai bet dabar sistema nera tam pritaikyta
//            $over = \Carbon\Carbon::now();
//            $diff = $over->diffInMinutes($campaign->run_at);
//            MailLog::info("Datetime difference in minutes: ".$diff);
//            if ($diff > 5) $campaign->status = 'scheduled';
            $campaign->save();
            // THE TRULY SAVE!

            if ($campaign->tracktype == 0) {
                // REPARSE HTML links
                // sita reikia uzsetinti sitoje vietoje ne pries siuntima, del to kad ateityje pakeitus, backend senderis nesupras, nes nebus sudeti {DOMAIN} ir kiti random {CAMPAIGN} click tagai ir t.t.
                $url_click_track = Setting::get('url_click_track');

                if (preg_match_all('/<a[^+]*href=["\'](?<url>http[^"\']*)["\']/i', $campaign->html, $matches)) {
                    foreach ($matches[1] as $key => $href) {
                        $url = $matches['url'][$key];
                        MailLog::info("Campaign: $campaign->uid aptikome linka: " . $url);
                        $newUrl = str_replace('URL', StringHelper::base64UrlEncode2($url), $url_click_track);
                        $newUrl = str_replace('URL', StringHelper::base64UrlEncode2($url), $newUrl);
                        $newUrl = str_replace('{CAMPAIGN}', StringHelper::genearateclickUrl(), $newUrl);
                        $newUrl = str_replace('MESSAGE_ID', 'MSGID2', $newUrl);
                        $newHref = str_replace($url, $newUrl, $href);
                        // if the link contains UNSUBSCRIBE URL tag
                        if (strpos($href, '{UNSUBSCRIBE_URL}') !== false) {
                            // just do nothing
                        } else if (strpos($href, '{REPORT_URL}') !== false) {

                        } else if (strpos($href, '{EMAIL}') !== false) {

                        } else if (strpos($href, '{SUBID1}') !== false) {
//                    } else if (preg_match("/{[A-Z0-9_]+}/", $href)) {
                            // just skip if the url contains a tag. For example: {UPDATE_PROFILE_URL}
                            MailLog::info("link: " . $href . " contains tag: {SUBID1}");
                        } else if (strpos($href, '{SUBID2}') !== false) {
                            MailLog::info("link: " . $href . " contains tag: {SUBID2}");
                        } else if (strpos($href, '{SUBID5}') !== false) {
                            MailLog::info("link: " . $href . " contains tag: {SUBID5}");
                        } else {
                            MailLog::info("ORIG: $url");
                            MailLog::info("MODIF:  $newUrl");
                            $campaign->html = str_replace($url, $newUrl, $campaign->html);
                            // $campaign->html = str_replace($href, $newHref, $campaign->html);
                        }
                    }
                }

            } // if tracktype == 0 end
            $stor = new StorageHelper();
            if ($campaign->tracktype == 3 && \Config::get('app.storage') == true&&!$stor->IsOnline()) {
                $request->session()->flash('alert-error', "Storage server seems to be offline!");
            }

            if ($campaign->tracktype == 3 && \Config::get('app.storage') == true&&$stor->IsOnline()) {
                // we should get all links, images links and transmit them to the storage
                MailLog::info("We are testing tracking type v2 (external storage)");
                $links = array();
                $linksnumeric = array();
                // completely new implementation of regexp search
                $regexp = "<a\s[^>]*href=(\"??)([^\" >]*?)\\1[^>]*>(.*)<\/a>";
                // CAN BE UPGRADED AS
               // $regexp = "<[-_=a-z0-9\" ]+(?:href)+=\"([^\"]+)\""; // see tracking v3 section at gosender
                if(preg_match_all("/$regexp/siU", $campaign->html, $matches, PREG_SET_ORDER)) {
                    $count = 0;
                    foreach ($matches as $match) {
                        // $match[2] = link address
                        // $match[3] = link text
                        $title = $match[3];
                        $url = $match[2];
                        //MailLog::info("Campaign: $campaign->uid aptikome linka: " . $url);
                        // if the link contains UNSUBSCRIBE URL tag
                        if (strpos($url, '{UNSUBSCRIBE_URL}') !== false) {
                            // just do nothing
                        } else if (strpos($url, '{REPORT_URL}') !== false) {

                        } else if (strpos($url, '{EMAIL}') !== false) {

                        } else if (strpos($url, '{SUBID1}') !== false) {

                        } else if (preg_match("/{[A-Z0-9_]+}/", $url)) {
                            // just skip if the url contains a tag. For example: {UPDATE_PROFILE_URL}
                            MailLog::info("link: " . $url . " contains tag: {SUBID1}");
                        } else if (strpos($url, '{SUBID2}') !== false) {
                            MailLog::info("link: " . $url . " contains tag: {SUBID2}");
                        } else if (strpos($url, '{SUBID5}') !== false) {
                            MailLog::info("link: " . $url . " contains tag: {SUBID5}");
                        } else {
                            $links[] = $url;
                            $linksnumeric[] = [ 'id' => $count, 'url' => $url ];
                            \Redis::hset("campaign_".$campaign->uid."_links",$count,$url);
                            $count++;
                        }
                    }
                }
                MailLog::info("Currently catched links: ".print_r($links,true));
                // new tracking type for the campaign
                preg_match_all('/<img[^>]+src="([^">]+)"/i', $campaign->html, $matches);
                $local_path = public_path('source/');
                //if (!file_exists(public_path() . '/source/' . $campaign->uid)) mkdir(public_path() . '/source/' . $campaign->uid);
                // foreach every found image link and download them step by step
                $images = array();
                $imagesnumeric = array();
                $count = 0;
                foreach ($matches[1] as $match) {
                 //   MailLog::info("Img: " . $match);
                   // $images[] = $match;
                    $contents = file_get_contents($match);
                    $md5file = md5($contents);
                    if (!$stor->ImageHashExists($md5file)) {
                        //MailLog::info("Image $match does not exist on the storage");
                        $filename =  $stor->ImageUpload($contents);
                        if ($filename !== false) {
                            $jsonas = json_decode($filename);
                            MailLog::info("Uploaded image filename: ".$jsonas->filename);
                            $images[] = $jsonas->filename;
                            $imagesnumeric[] = [ 'id' => $count, 'filename' => $jsonas->filename ];
                            \Redis::hset("campaign_".$campaign->uid."_images",$count,$jsonas->filename);
                        }
                    } else {
                        // get image url from hash
                        //MailLog::info("already exists");
                        $filename = $stor->ImageFilenameFromHash($md5file);
                        if ($filename !== false) {
                            $jsonas = json_decode($filename);
                            MailLog::info("Already uploaded image filename: ".$jsonas->filename);
                            $images[] = $jsonas->filename;
                            $imagesnumeric[] = [ 'id' => $count, 'filename' => $jsonas->filename ];
                            \Redis::hset("campaign_".$campaign->uid."_images",$count,$jsonas->filename);
                        }
                    }
                    $count++;
                }
                MailLog::info("Currently cached images: ".print_r($images,true));
                // we should post campaign info to the storage api
                $camptmp = $campaign;
                $camptmp->deployment = \Config::get('app.deployment');
                $camptmp->urls = $links;
                $camptmp->images = $images;
                $camptmp->urlsnumeric = $linksnumeric;
                $camptmp->imagesnumeric = $imagesnumeric;
                $camptmp->mail_list_id = 0;
                $camptmp->mail_list_uid = "";
                $camptmp->mail_list_name = "";
                //MailLog::info("Interesting info: ".print_r($camptmp->defaultMailList->name,true));
                if ($camptmp->defaultMailList->id ?? false) {
                    $camptmp->mail_list_id = $camptmp->defaultMailList->id;
                    $camptmp->mail_list_uid = $camptmp->defaultMailList->uid;
                    $camptmp->mail_list_name = $camptmp->defaultMailList->name;
                }
                if ($stor->SubmitCampaignInfo($camptmp) == false) {
                    $request->session()->flash('alert-error', "Problem in transmitting campaign data to storage server!");
                }
            }

//            $hashit = new TrackHash();
//            $textas = "1234567890u52045";
//            $hashas = $hashit->HashIt($textas);
//            MailLog::info("KEY of: $textas is: $hashas");
//            $unmasked = $hashit->UnhashIt("kocmzkcsrlkpaeccoz");
//            MailLog::info("Unmasked text is: $unmasked");

            if ($campaign->trackurl == "") $campaign->trackurl = Setting::get('global_tracking');
            Redis::set($campaign->uid,json_encode($campaign));
            //// CIA NUSTATOME DNS
            ///
            ///

            // PROXY SUPPORT
            if (\Redis::exists("proxy_wide") && \Redis::get("proxy_wide") == "1" && \Redis::exists("proxy_default") && \Redis::get("proxy_default") != "") {
            $proxy_ip = \Redis::get("proxy_default");
            $domfor = $campaign->trackurl;
            MailLog::info("Proxy have been enabled, setting manual ip: $proxy_ip for domain: $domfor");
            if (\Config::get('app.cloudflare_enabled') == true) {
               $cloudflare = new CloudFlareHelper();
               $cloudflare->manually_set($domfor,$proxy_ip);
            }
            } else {
               MailLog::info("The standard cloudflare configuration will be used (without proxy)");
               $cloudflare = new CloudFlareHelper();
               try {
                    $cloudflare->import_domains(array($campaign->trackurl));
                    $cloudflare->set_tracking_domain($campaign->trackurl);
                } catch (\Exception $ex) {
                    MailLog::info("We got problems then importing domain: ".$campaign->trackurl." when saving campaign: $campaign->uid, maybe this domain is already in the system?");
                }

            }
            /// END OF PROXY SUPPORT
            if (\Config::get('app.cloudflare_enabled') == true&&$campaign->trackurl != "") {
                $cloudflare = new CloudFlareHelper();
                try {
                    $send_domain = @explode('@',$campaign->from_email)[1];
		    if ($send_domain != "") {
                    // set tracking address domain spf records
                    MailLog::info("Send address domain detected: ".$send_domain);
                    $cloudflare->delete_spf_records($send_domain);
	            $cloudflare->add_spf_records($send_domain);
                    }
                } catch (\Exception $ex) {
                    MailLog::info("We got problems when setting send address for campaign: $campaign->uid: ".$ex);
                }

            }
            ///
            // Here we need to implement the cloudflare domain set
//            if ($campaign->trackurl != "") {
//                $dns = new DNSHelper();
//                $dns->set_tracking_domain($campaign->trackurl);
//            }


            return redirect()->action('CampaignController@confirm', ['uid' => $campaign->uid]);
        }

        return view('campaigns.schedule', [
            'campaign' => $campaign,
            'rules' => $rules,
            'delivery_date' => $delivery_date,
            'delivery_time' => $delivery_time,
        ]);
    }

    /**
     * Cofirm.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function confirm(Request $request)
    {
        $customer = $request->user()->customer;
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);


        // check step
        if($campaign->step() < 4) {
            return redirect()->action('CampaignController@schedule', ['uid' => $campaign->uid]);
        }

        // authorize
        /* testas hack
        if (\Gate::denies('update', $campaign)) {
            return $this->notAuthorized();
        }
        */

        $hash = new \Acelle\Library\TrackHash();
        MailLog::info("GOT ALGO: ".print_r($hash->ShowAlgo(),true));

        // validate and save posted data
        if ($request->isMethod('post') && $campaign->step() >= 5) {
            // Save campaign
            // @todo: check campaign status before requeuing. Otherwise, several jobs shall be created and campaign will get sent several times

//            if ($campaign->isPaused()) {
//                $campaign->requeue();
//
//            }


// startuojam kopijuotus campaign
            if ($campaign->isNew()) {
                $campaign->requeue();
                $campaign->updateCache();
            }


// startuojam naujai padarytus campaign
            //if ($campaign->)

            // Log
            $campaign->log('started', $customer);


            // uzsetinam zero i redis backenda

           if (!Redis::exists($campaign->uid.'_counter')) Redis::set($campaign->uid.'_counter', 0);
           //if(!$redis->exists($campaign->uid))
           // $redis->del($campaign->uid);
            //   $redis->set($campaign->uid,json_encode($campaign));

            if (Redis::exists($campaign->uid.'_paused')) Redis::del($campaign->uid.'_paused');

            return redirect()->action('CampaignController@index');
        }

        return view('campaigns.confirm', [
            'campaign' => $campaign,
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
        $customer = $request->user()->customer;

        if (isSiteDemo()) {
            echo trans('messages.operation_not_allowed_in_demo');
            return;
        }

        $items = \Acelle\Model\Campaign::whereIn('uid', explode(',', $request->uids));

        foreach ($items->get() as $item) {
            // authorize
         //   if (\Gate::allows('delete', $item)) {

            // FIXME patikrinti sending jeigu siuncia tada daryti kokius nors veiksmus kad ta siusntima sustabdytu ir ypac kai insertinasi tracking logas
            Redis::set($item->uid.'_canceled',1);

                $item->delete();
                // Log
                $item->log('deleted', $customer);
                // do kill existing sending if they exists
                 @exec("kill `ps aux|grep -v grep|grep sender|grep \"$item->uid\"|awk '{print \$2}'`");

                // remove every campaign from redis
            //    if (Redis::exists($item->uid."_paused")) Redis::del($item->uid."_paused");
            //    if (Redis::exists($item->uid."_counter")) Redis::del($item->uid."_counter");
            //    if (Redis::exists($item->uid."_total")) Redis::del($item->uid."_total");
             //   if (Redis::exists($item->uid)) Redis::del($item->uid);
                if (Redis::exists("campaign_".$item->uid."_subscribers_test")) Redis::del("campaign_".$item->uid."_subscribers_test");
                if (Redis::exists("campaign_".$item->uid."_subscribers")) Redis::del("campaign_".$item->uid."_subscribers");

            //if (Redis::exists("campaign_".$item->uid."_servers")) Redis::del("campaign_".$item->uid."_servers");
            //if (Redis::exists("campaign_".$item->uid."_openers")) Redis::del("campaign_".$item->uid."_openers");
            //if (Redis::exists("campaign_".$item->uid."_clickers")) Redis::del("campaign_".$item->uid."_clickers");
            if (Redis::exists("campaign_".$item->uid."_undelivered_data")) Redis::del("campaign_".$item->uid."_undelivered_data");
            if (Redis::exists("campaign_".$item->uid."_undelivered_val")) Redis::del("campaign_".$item->uid."_undelivered_val");
            if (Redis::exists('campaign_'.$item->uid.'_static')) Redis::del('campaign_'.$item->uid.'_static');

           // }
        }

        // Redirect to my lists page
        echo trans('messages.campaigns.deleted');
    }


    /**
     *  Function that does archive the selected campaigns
     */
    public function archive(Request $request)
    {
        $customer = $request->user()->customer;
        $items = \Acelle\Model\Campaign::whereIn('uid', explode(',', $request->uids));
        foreach ($items->get() as $item) {
            // authorize
            //   if (\Gate::allows('delete', $item)) {

            // FIXME patikrinti sending jeigu siuncia tada daryti kokius nors veiksmus kad ta siusntima sustabdytu ir ypac kai insertinasi tracking logas
            Redis::set($item->uid.'_canceled',1);

            $item->archive();
            // Log
//            $item->log('deleted', $customer);
            // do kill existing sending if they exists
            @exec("kill `ps aux|grep -v grep|grep sender|grep \"$item->uid\"|awk '{print \$2}'`");
//            if (Redis::exists("campaign_".$item->uid."_subscribers_test")) Redis::del("campaign_".$item->uid."_subscribers_test");
//            if (Redis::exists("campaign_".$item->uid."_subscribers")) Redis::del("campaign_".$item->uid."_subscribers");
//            if (Redis::exists("campaign_".$item->uid."_undelivered_data")) Redis::del("campaign_".$item->uid."_undelivered_data");
//            if (Redis::exists("campaign_".$item->uid."_undelivered_val")) Redis::del("campaign_".$item->uid."_undelivered_val");
//            if (Redis::exists('campaign_'.$item->uid.'_static')) Redis::del('campaign_'.$item->uid.'_static');

            // }
        }

        // Redirect to my lists page
        echo 'Campaigns have been successfully archived!';
    }



    public function unarchive(Request $request)
    {
        $customer = $request->user()->customer;
        $items = \Acelle\Model\Campaign::whereIn('uid', explode(',', $request->uids));
        foreach ($items->get() as $item) {
             Redis::del($item->uid.'_canceled');

            $item->unarchive();
        }

        // Redirect to my lists page
        echo 'Campaigns have been successfully unarchived!';
    }

    /**
     * Campaign overview.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function overview(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $agent_api = new UserAgentHelper();

        // Trigger the CampaignUpdate event to update the campaign cache information
        // The second parameter of the constructor function is false, meanining immediate update
        try {
            //event(new \Acelle\Events\CampaignUpdated($campaign));
            MailLog::info("EXPERIMENTAL Campaign cache update is initiated!!!");
            $taskrunner = New TaskRunner();
            $customer2 = $request->user()->customer->id;
            $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_CAMPAIGN_UPDATE,$customer2,$taskrunner::PRIORITY_LOW,$request->uid);
            MailLog::info("EXPERIMENTAL Campaign cache update already passed!!! customer: ".$customer2. " campuid: ".$request->uid);
        } catch (\Exception $ex) {
            // in case TrackingLog record does not exist yet (open before logged!)
        }

        // authorize
        if (\Gate::denies('read', $campaign)) {
//            return $this->notAuthorized();
        }

        return view('campaigns.overview', [
            'campaign' => $campaign,
            'agent_api' => $agent_api,
        ]);
    }

    public function overview_chart(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $agent_api = new UserAgentHelper();
        return view('campaigns._chart', [
            'campaign' => $campaign,
            'agent_api' => $agent_api,
        ]);
    }

    public function overview_platforms(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $agent_api = new UserAgentHelper();
        return view('campaigns._most_platforms', [
            'campaign' => $campaign,
            'agent_api' => $agent_api,
        ]);
    }

    public function overview_open_click_rate(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $agent_api = new UserAgentHelper();
        return view('campaigns._open_click_rate', [
            'campaign' => $campaign,
            'agent_api' => $agent_api,
        ]);
    }

    public function overview_count_boxes(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $agent_api = new UserAgentHelper();
        return view('campaigns._count_boxes', [
            'campaign' => $campaign,
            'agent_api' => $agent_api,
        ]);
    }

    public function overview_24h_chart(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $agent_api = new UserAgentHelper();
        return view('campaigns._24h_chart', [
            'campaign' => $campaign,
            'agent_api' => $agent_api,
        ]);
    }

    public function overview_top_link(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $agent_api = new UserAgentHelper();
        return view('campaigns._top_link', [
            'campaign' => $campaign,
            'agent_api' => $agent_api,
        ]);
    }

    public function overview_click_country(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $agent_api = new UserAgentHelper();
        return view('campaigns._most_click_country', [
            'campaign' => $campaign,
            'agent_api' => $agent_api,
        ]);
    }

    public function overview_open_country(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $agent_api = new UserAgentHelper();
        return view('campaigns._most_open_country', [
            'campaign' => $campaign,
            'agent_api' => $agent_api,
        ]);
    }

    public function overview_open_location(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $agent_api = new UserAgentHelper();
        return view('campaigns._most_open_location', [
            'campaign' => $campaign,
            'agent_api' => $agent_api,
        ]);
    }

    public function overview_mta_openers(Request $request)
    {
        $query = "SELECT count(id) as countas from app_openai where campaign = '".$request->uid."'";
        $db_user = "ses_remote";
        $db_host = "135.181.2.16";
        $db_db = "trackingas";
        $db_pass = "bGh9CaF897qZab@2";
        //$depl = \Config::get('app.deployment');
        $db = new \mysqli($db_host, $db_user, $db_pass, $db_db);
        $result = $db->query($query);
        $row = $result->fetch_assoc();
        $value = $row['countas'];
        MailLog::info("We got $value openers from mta for campaign $request->uid");
        return $value;
    }
    /**
     * Campaign links.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function links(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
//            return $this->notAuthorized();
        }

        return view('campaigns.links', [
            'campaign' => $campaign,
        ]);
    }

    /**
     * 24-hour chart.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function chart24h(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
//            return $this->notAuthorized();
        }

        $result = [
            'columns' => [],
            'data' => [],
            'bar_names' => [trans('messages.opened'), trans('messages.clicked')],
        ];

        $hours = [];

        // columns
        for ($i = 23; $i >= 0; --$i) {
            $result['columns'][] = \Acelle\Library\Tool::dateTime(\Carbon\Carbon::now())->subHours($i)->format('h:A');
            $hours[] = \Acelle\Library\Tool::dateTime(\Carbon\Carbon::now())->subHours($i)->format('H');
        }

        // 24h collection
        $openData24h = $campaign->openUniqHours(\Acelle\Library\Tool::dateTime(\Carbon\Carbon::now())->subHours(24), \Carbon\Carbon::now());
        $clickData24h = $campaign->clickHours(\Acelle\Library\Tool::dateTime(\Carbon\Carbon::now())->subHours(24), \Carbon\Carbon::now());

        // datas
        foreach ($result['bar_names'] as $key => $bar) {
            $data = [];
            if ($key == 0) {
                foreach ($hours as $ohour) {
                    $num = isset($openData24h[$ohour]) ? count($openData24h[$ohour]) : 0;
                    $data[] = $num;
                }
            } else {
                foreach ($hours as $chour) {
                    $num = isset($clickData24h[$chour]) ? count($clickData24h[$chour]) : 0;
                    $data[] = $num;
                }
            }

            $result['data'][] = [
                'name' => $bar,
                'type' => 'line',
                'smooth' => true,
                'data' => $data,
                'itemStyle' => [
                    'normal' => [
                        'areaStyle' => [
                            'type' => 'default',
                        ],
                    ],
                ],
            ];
        }

        return json_encode($result);
    }

    /**
     * Chart.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function chart(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
//            return $this->notAuthorized();
        }

        $result = [
            'columns' => [],
            'data' => [],
            'bar_names' => [
                trans('messages.recipients'),
                trans('messages.delivered'),
                trans('messages.failed'),
                trans('messages.Open'),
                trans('messages.Click'),
                trans('messages.Bounce'),
                trans('messages.report'),
                trans('messages.unsubscribe'),
            ],
        ];

        // columns
        $result['columns'][] = trans("messages.count");

        // datas
        $result['data'][] = [
            'name' => trans('messages.unsubscribe'),
            'type' => 'bar',
            'smooth' => true,
            'data' => [$campaign->unsubscribeCount()],
            'itemStyle' => [
                'normal' => [
                    'color' => '#D81B60'
                ]
            ],
        ];

        $result['data'][] = [
            'name' => trans('messages.report'),
            'type' => 'bar',
            'smooth' => true,
            'data' => [$campaign->feedbackCount()],
            'itemStyle' => [
                'normal' => [
                    'color' => '#00897B'
                ]
            ],
        ];

        $result['data'][] = [
            'name' => trans('messages.Bounce'),
            'type' => 'bar',
            'smooth' => true,
            'data' => [$campaign->bounceCount()],
            'itemStyle' => [
                'normal' => [
                    'color' => '#6D4C41'
                ]
            ],
        ];

        $result['data'][] = [
            'name' => trans('messages.Click'),
            'type' => 'bar',
            'smooth' => true,
            'data' => [$campaign->clickedEmailsCount()],
            'itemStyle' => [
                'normal' => [
                    'color' => '#039BE5'
                ]
            ],
        ];

        $result['data'][] = [
            'name' => trans('messages.Open'),
            'type' => 'bar',
            'smooth' => true,
            'data' => [$campaign->openUniqCount()],
            'itemStyle' => [
                'normal' => [
                    'color' => '#546E7A'
                ]
            ],
        ];

        $result['data'][] = [
            'name' => trans('messages.failed'),
            'type' => 'bar',
            'smooth' => true,
            'data' => [$campaign->failedCount()],
            'itemStyle' => [
                'normal' => [
                    'color' => '#E53935'
                ]
            ],
        ];

        $result['data'][] = [
            'name' => trans('messages.delivered'),
            'type' => 'bar',
            'smooth' => true,
            'data' => [$campaign->deliveredCount()],
            'itemStyle' => [
                'normal' => [
                    'color' => '#7CB342'
                ]
            ],
        ];

        $result['data'][] = [
            'name' => trans('messages.recipients'),
            'type' => 'bar',
            'smooth' => true,
            'data' => [$campaign->readCache('SubscriberCount', 0)],
            'itemStyle' => [
                'normal' => [
                    'color' => '#555'
                ]
            ],
        ];


        $result['horizontal'] = 1;

        return json_encode($result);
    }

    /**
     * Chart Country.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function chartCountry(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
//            return $this->notAuthorized();
        }

        $result = [
            'title' => '',
            'columns' => [],
            'data' => [],
            'bar_names' => [],
        ];

        // create data
        $datas = [];
        $total = $campaign->openCount();
        $count = 0;
        foreach ($campaign->topCountries()->get() as $location) {
            $country_name = (!empty($location->country_name) ? $location->country_name : trans('messages.unknown'));
            $result['bar_names'][] = $country_name;

            $datas[] = ['value' => $location->aggregate, 'name' => $country_name];
            $count += $location->aggregate;
        }

        // others
        if($total > $count) {
            $result['bar_names'][] = trans('messages.others');
            $datas[] = ['value' => $total - $count, 'name' => trans('messages.others')];
        }

        // datas
        $result['data'][] = [
            'name' => trans('messages.country'),
            'type' => 'pie',
            'radius' => '70%',
            'center' => ['50%', '57.5%'],
            'data' => $datas
        ];

        $result['pie'] = 1;

        return json_encode($result);
    }





    /* platformu statistikos pagal openus
    */

    public function chartPlatforms(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $agent_api = new UserAgentHelper();

        // authorize
        if (\Gate::denies('read', $campaign)) {
//            return $this->notAuthorized();
        }

        $result = [
            'title' => '',
            'columns' => [],
            'data' => [],
            'bar_names' => [],
        ];

        // create data
        $datas = [];
        $total = $campaign->openCount();
        $count = 0;
        foreach ($campaign->topPlatforms()->get() as $location) {
            $result['bar_names'][] = $agent_api->get_operating_system($location->user_agent);

            $datas[] = ['value' => $location->aggregate, 'name' => $agent_api->get_operating_system($location->user_agent)];
            $count += $location->aggregate;
        }

        // others
        if($total > $count) {
            $result['bar_names'][] = trans('messages.others');
            $datas[] = ['value' => $total - $count, 'name' => trans('messages.others')];
        }

        // datas
        $result['data'][] = [
            'name' => 'Device',
            'type' => 'pie',
            'radius' => '70%',
            'center' => ['50%', '57.5%'],
            'data' => $datas
        ];

        $result['pie'] = 1;

        return json_encode($result);
    }

    /**
     * Chart Country by clicks.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function chartClickCountry(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
//            return $this->notAuthorized();
        }

        $result = [
            'title' => '',
            'columns' => [],
            'data' => [],
            'bar_names' => [],
        ];

        // create data
        $datas = [];
        $total = $campaign->clickCount();
        $count = 0;
        foreach ($campaign->topClickCountries()->get() as $location) {
            $result['bar_names'][] = $location->country_name;

            $datas[] = ['value' => $location->aggregate, 'name' => $location->country_name];
            $count += $location->aggregate;
        }

        // others
        if($total > $count) {
            $result['bar_names'][] = trans('messages.others');
            $datas[] = ['value' => $total - $count, 'name' => trans('messages.others')];
        }

        // datas
        $result['data'][] = [
            'name' => trans('messages.country'),
            'type' => 'pie',
            'radius' => '70%',
            'center' => ['50%', '57.5%'],
            'data' => $datas
        ];

        $result['pie'] = 1;

        return json_encode($result);
    }

    /**
     * 24-hour quickView.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function quickView(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
//            return $this->notAuthorized();
        }

        return view('campaigns._quick_view', [
            'campaign' => $campaign,
        ]);
    }

    /**
     * Select2 campaign.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function select2(Request $request)
    {
        $data = ['items' => [], 'more' => true];

        $data['items'][] = ['id' => 0, 'text' => trans('messages.all')];
        foreach (\Acelle\Model\Campaign::getAll()->get() as $campaign) {
            $data['items'][] = ['id' => $campaign->uid, 'text' => $campaign->name];
        }

        echo json_encode($data);
    }


    public function testbackgroundqueue(Request $request)
    {
        MailLog::info('Got test from the url to test the background queue technique for campaign: '.$request->uid);
        //\Acelle\Model\Campaign::QueueBackgroundSending($request->uid);
        //$job = (new \Acelle\Jobs\QueueBackgroundSendingJob($request->uid))->onQueue('high')->delay(1);
        $job = new \Acelle\Jobs\QueueBackgroundSendingJob($request->uid);
        $this->dispatch($job);
        return;
    }

    /* start the campaign queue background sending
     *
     *
     */

//    public function QueueBackgroundSending(Request $request)
//    {
//        MailLog::info("Doing job queue for background restarting of campaign(s): ".print_r($request->uids,true));
//        $job = (new QueueBackgroundSendingJob($request->uid))->onQueue('high');
//        $this->dispatch($job);
//    }


    /*
     *  Restart the background sending
     *  This function adds the job queue
     *
     */

    public function RestartBackground(Request $request) {
        MailLog::info("Doing job queue for background restarting of campaign(s): ".print_r($request->uids,true));
        //$job = (new RestartProcessesJob($request->uids))->onQueue('high')->delay(1);
        $job = new \Acelle\Jobs\RestartProcessesJob($request->uids);
        $this->dispatch($job);
    }

    /* Start resend technoque in the background
    */
    public function DoRetryBackGroundCampaign(Request $request) {
        MailLog::info("Doing job queue for RETRY SEND background restarting of campaign(s): ".print_r($request->uids,true));
        //$job = (new RetryCampagnSendJob($request->uids))->onQueue('high')->delay(1);
        $job = new \Acelle\Jobs\RetryCampagnSendJob($request->uids);
        $this->dispatch($job);
    }

    private function getmaillistname($id) {
//MailLog::info("Got maillistid: $id");
        try {
            $list = \Acelle\Model\MailList::findById($id);
//MailLog::info("got maillistname: $list->name");
            return $list->name;
        } catch (\Exception $ex) {
            return "";
        }
    }

    /**
     * Tracking when open.
     */
    public function open(Request $request)
    {
        $logger = MailLog::create(storage_path('logs/open.log'));

        try {
        $log = new \Acelle\Model\OpenLog();
        $log->message_id = \Acelle\Library\StringHelper::base64UrlDecode($request->message_id);
        // new block to check for server ip
        $server_ip = null;
        $pieces = explode("@", $log->message_id);
        if (strlen($pieces[1]) > 1) {
            MailLog::info("We got found the server ip: ".$pieces[1]." of tracking link $request->message_id");
            $server_ip = $pieces[1];
            $log->message_id = $pieces[0].'@.';
        }

        // end of new block to check the server ip
        $location = \Acelle\Model\IpLocation::add($_SERVER['REMOTE_ADDR']);
        $log->ip_address = $location->ip_address;
        $log->user_agent = $_SERVER['HTTP_USER_AGENT'];


       $tracking_log = \Acelle\Model\TrackingLog::where('message_id', $log->message_id)->first();
       $lok = json_decode($location)->country_name ?? "Unknown";
       $lok2 = json_decode($location)->country_code ?? "nzn";
       if (isset($log->trackingLog->subscriber->email))
           $logger->info("Open tracking, subscriber: ".$log->trackingLog->subscriber->email." opened email, message id: ".$log->message_id. " ip: ".$log->ip_address." location: ".$lok);
       else
           $logger->warning("Open tracking, message id: ".$log->message_id. " ip: ".$log->ip_address." location: ".$lok." but we don't have such message id in the tracking log");
        // check if old records exists
        $already_exists = 0;
        $old_track = \Acelle\Model\OpenLog::where('message_id', $log->message_id)->first();
        if (is_object($old_track)) {
            $already_exists = 1;
            $logger->warning('Open is not unique because message_id: '.$log->message_id.' for that open is already registered in open_logs');
        } else {
            $logger->info("Open is unique because we don't have $log->message_id in open_logs yet!");
        }


        if (is_object($tracking_log)) {
            $subscriber = $tracking_log->subscriber;
            if ($subscriber->status != 'unsubscribed') {
       //         $subscriber->status = 'opener';
                $now = new \DateTime();
                $subscriber->opened_at = $now;
                $subscriber->save();
            }
        }


        // Trigger the CampaignUpdate event to update the campaign cache information
        // The second parameter of the constructor function is false, meanining immediate update

        // Jeigu isjungiam ant nginx auto forwarda tada galima naudoti sita suda
       // LaravelLog::info("Additional info: ".$_SERVER['HTTP_X_FORWARDED_FOR']);


            $log->save();
          //  event(new \Acelle\Events\MailListUpdated($log->trackingLog->subscriber->mailList));
            // count the backend!
            if ($already_exists == 0) Redis::incr($tracking_log->campaign->uid.'_openers');



            if (\Config::get('app.internal') == true) {
                if (\Config::get('app.storage') == true) {
                    try {
                        $stor = new StorageHelper();
                        $stor->SubmitOpener($log->trackingLog->subscriber->email, $log->ip_address, $server_ip, '', $log->user_agent, $lok, '', '', '', '');
                    } catch (\Exception $ex) {
                        $logger->error("Unable to transmit the record to storage!");
                    }
                }
            }

            if (\Config::get('app.internal') == false) {
                try {
                    $campuid = $log->trackingLog->campaign->uid ?? '';
                    $emil = $log->trackingLog->subscriber->email;
                    //$logger->info("got campaign uid: ".$campuid);
                    // try injection to another server
                    $db_user = "ses_remote";
                    $db_host = "78.46.73.84";
                    $db_db = "trackingas";
                    $db_pass = "bGh9CaF897q";
                    //$trackingai = \DB::select("SELECT email FROM `tracking_logs` inner join subscribers on tracking_logs.subscriber_id = subscribers.id where tracking_logs.message_id = '" . $log->message_id . "'");
                    $db = new \mysqli($db_host, $db_user, $db_pass, $db_db);
// FIX from old method to the new one
                  //  $values = \geoip_record_by_name($log->ip_address);
                   // $lokacija = $values['country_name'] . " " . $values['city'];
                    //  MailLog::info("Locationas: ".$lokacija);
                   // foreach ($trackingai as $trackas) {
                        //print $trackas->email;
                        $domain = explode('@',$emil)[1];
                    $reverse = "";
                    // fix for those who using amazons ses
                    if (strpos($server_ip, '.') !== false) {
                        $reverse = @gethostbyaddr($server_ip);
                    }
                        $depl = \Config::get('app.deployment');
                        $trkdom = $_SERVER['HTTP_HOST'];
                        $logger->info("Opener from deployment: $depl domain: $trkdom");
                    $maillist = "";
                    try {
                        $maillist = $this->getmaillistname($tracking_log->campaign->default_mail_list_id);
                        $logger->info("Got maillist: $maillist");
                    } catch (\exception $ex) {
                        $logger->error("Unable to determine the open maillist");
                    }

                    if (\Config::get('app.storage') == true) {
                        try {
                            $stor = new StorageHelper();
                            $stor->SubmitOpener($log->trackingLog->subscriber->email, $log->ip_address, $server_ip, $reverse, $log->user_agent, $lok, $depl, $trkdom, $campuid, $maillist);
                        } catch (\Exception $ex) {
                            $logger->error("Unable to transmit the record to storage!");
                        }
                     }
                    // until 2020.01.07
                 //   $db->query("INSERT IGNORE INTO app_31 (email,ip_address,server_ip,server_ptr,user_agent,location, deployment, domain,campaign,maillist) VALUES('$emil','$log->ip_address','$server_ip','$reverse','$log->user_agent','$lok','$depl','$trkdom','$campuid','$maillist')");

                    $db->query("INSERT IGNORE INTO app_openai (email,ip_address,server_ip,server_ptr,user_agent,location, deployment, domain,campaign,maillist) VALUES('$emil','$log->ip_address','$server_ip','$reverse','$log->user_agent','$lok','$depl','$trkdom','$campuid','$maillist')");


                   // }
                } catch (\Exception $ex) {
                    $logger->error("Unable to insert open log to the remote APP intance!");
                }
                // another server injection end
            }
            $logger->info('STATUS: OK');

        } catch (\Exception $ex) {
            // in case TrackingLog record does not exist yet (open before logged!)
            $logger->error('STATUS: ERR: Cannot save open log for message: ' . $log->message_id. ' because the campaign is already deleted!');
        } finally {
            $logger->info('[-----------------------------------------------------------------------------------]');
            return response()->file(public_path('images/transparent.gif'));
        }

    }

    public function open_new(Request $request) {
        $DEBUG = 1;
        $PUT_EXTERNAL = 1;
        $logger = MailLog::create(storage_path('logs/open.log'));
        $msg = "IP: ".$_SERVER['REMOTE_ADDR'];
        if ($DEBUG > 0) MailLog::info("Just testing new age open tracking :D ".$request->image);
        $DEBUG = 1;
        try {
            list($id, $ext) = explode(".", $request->image);
            $hasher = new TrackHash();
            $unhashed = $hasher->UnhashIt($id);
            $msg .= " ID: ".$unhashed;
            if (\Redis::hexists('tracking_logs',$unhashed)) {
                if ($DEBUG >0) MailLog::info("open_new: Tracking log $unhashed found on redis");
                $msg .= " log exist: yes";
                if ($json = json_decode(\Redis::hget('tracking_logs', $unhashed))) {
                    if ($DEBUG >0) MailLog::info("open_new: Tracking log unserialized ok");
                    $msg .= " json ok: yes";
                    $test = $json->Test;
                    $msg .= " test: ".(boolval($json->Test) ? 'yes' : 'no');
                    if ($DEBUG >0) {
                        if ($test == true) MailLog::info("It's a test email open tracking");
                    }
                    //$test = false; // FIXME change on the production
                    if ($DEBUG > 0) MailLog::info("open_new: we got tracking_log internals: ".print_r($json,true));
                    // if tracking log is not a just a test email and are not yet opened, we can proceed further
                    if ($json->Test == false) {
                        $msg .= " first open: ".(boolval($json->Opened) ? 'no' : 'yes');
                        if ($json->Opened == false) {
                            // we shoud register it as opened
                            $json->Opened = true;
                            // we should save the changes to the tracking log
                            \Redis::hset('tracking_logs',$unhashed,json_encode($json));
                            ////////////// old open_log code
                            $log = new \Acelle\Model\OpenLog();
                            $log->message_id = $json->Trackid;
                            $location = \Acelle\Model\IpLocation::add($_SERVER['REMOTE_ADDR']);
                            $log->ip_address = $location->ip_address;
                            $log->user_agent = "";
                            if (isset($_SERVER['HTTP_USER_AGENT'])) $log->user_agent = $_SERVER['HTTP_USER_AGENT'];
                            // we will fuck some sql
                            $tracking_log = \Acelle\Model\TrackingLog::where('message_id',$log->message_id)->first();
                            $locobj = json_decode($location);
                            $lok = $locobj->country_name ?? "Unknown";
                            $lok2 = $locobj->country_code ?? "Un";
                            $lok3 = $locobj->city ?? "";
                            $lok_long = $lok;
                            if ($lok3 != "") {
                                $lok_long = $lok." ".$lok3;
                            }
                            $msg .= " location: ".$lok_long;
                            $msg .= " UA: ".$log->user_agent;
                            try {
                                $log->save();
                            } catch (\Exception $ex) {
                                $msg .= "unable to save trackinglog";
                                if ($DEBUG > 0) MailLog::info("Trouble saving tracking log with trackid $json->Trackid");
                            }
                            \Redis::incr($json->Campuid.'_openers');
                            if (\Config::get('app.internal') == false) {
                                try {
                                    $emil = $log->trackingLog->subscriber->email;
                                    $msg .= " email: $emil";
                                    $db_user = "ses_remote";
                                    $db_host = "135.181.2.16";
                                    $db_db = "trackingas";
                                    $db_pass = "bGh9CaF897q";
                                    $domain = explode('@',$emil)[1];
                                    $reverse = "";
                                    // fix for those who using amazons ses
                                    if (strpos($json->Server, '.') !== false) {
                                        $reverse = @gethostbyaddr($json->Server);
                                    }
                                    $depl = \Config::get('app.deployment');
                                    $trkdom = $_SERVER['HTTP_HOST'];
                                    $maillist = "";
                                    try {
                                        $maillist = $this->getmaillistname($tracking_log->campaign->default_mail_list_id);
                                        $msg .= " maillist: ".$maillist;
                                    } catch (\exception $ex) {
                                        if ($DEBUG > 0) MailLog::error("open_new: Unable to determine the open maillist");
                                    }
                                    if (\Config::get('app.storage') == true) {
                                        try {
                                            $stor = new StorageHelper();
                                            $stor->SubmitOpener($log->trackingLog->subscriber->email, $log->ip_address, $json->Server, $reverse, $log->user_agent, $lok_long, $depl, $trkdom, $json->Campuid, $maillist);
                                        } catch (\Exception $ex) {
                                            $logger->error("Unable to transmit the record to storage!");
                                        }
                                    }
                                    // DEPRECATED
                                    $db = new \mysqli($db_host, $db_user, $db_pass, $db_db);
                                    $db->query("INSERT IGNORE INTO app_openai (email,ip_address,server_ip,server_ptr,user_agent,location, deployment, domain,campaign,maillist) VALUES('$emil','$log->ip_address','$json->Server','$reverse','$log->user_agent','$lok_long','$depl','$trkdom','$json->Campuid','$maillist')");
                                } catch (\Exception $ex) {
                                    if ($DEBUG > 0) MailLog::error("open_new: Unable to insert open log to the remote APP intance!");
                                }
                                // another server injection end
                            }
                            ////////////// end of old open_log code
                        } else {
                            if ($DEBUG > 0) MailLog::info("open_new: Tracking log $unhashed are already registered as opened!");
                        }
                    } else {
                        $msg .= " test email";
                    }


                }
                $msg .= " OK";
            } else {
                // tracking log cannot be found
                if ($DEBUG > 0) MailLog::info("open_new: we cannot find tracking log for $unhashed");
                $msg .= " no tracking log found in redis backend";
            }
        } catch (\Exception $ex) {
            MailLog::error("open_new: Trouble with open_new: ".$ex);
        } finally {
            $logger->info($msg);
            return response()->file(public_path('images/transparent.gif'));
        }
    }

    private function findlinkbyid($id) {
        // if the link id not found in redis campaigns_links list, we will search for it trought the mysql links table
        $link = Link::where('id', '=', $id)->first();
        if (!is_object($link)) {
        return "";
        }
        try {
            if ($link->url != "") {
                MailLog::info("We got link from sql table: " . $link->url);
                return $link->url;
            }
            return "";
        } catch (\Exception $ex) {
            MailLog::error("Cannot find the link in the mysql table links by id: ".$id);
            return "";
        }

    }

    public function click_new(Request $request) {
        // backwards url, will will do redirect if no valid url is found on our database
        $backwards_url = "http://google.com";
        $DEBUG = 1;
        $msg = "";
        $logger = MailLog::create(storage_path('logs/click.log'));
        if ($DEBUG > 0) MailLog::info("Got click with new engine :D ".$request->urlas);
        $hash = new \Acelle\Library\TrackHash();
        $unhashed = $hash->UnhashIt($request->urlas);
        if ($DEBUG > 0) MailLog::info("Unhashed: ".$unhashed);
        if ($unhashed == "") {
            if ($DEBUG > 0) MailLog::info("Got empty url click, redirecting to the backwards url: ".$backwards_url);
            return redirect()->away($backwards_url);
        }
        if ($unhashed != "" && strpos($unhashed, "u") !== false) {
            // CLICK
            if ($DEBUG > 0) MailLog::info("We got some urls here :D ".$unhashed);
            list($trackid,$linkid) = $hash->spliturl($unhashed);
            $url = $hash->GetUrlbyId($linkid);
            // if the link was not found on the redis backend ?
            if ($url == "") {
                $url = $this->findlinkbyid($linkid);
            }
            // some hack to replace fuckin editors mess
            $url = str_replace("amp;",'',$url);
            // here we should implement any of necessary emergency redirects such as:
            if (strpos($url,"click.genitrck.com") !== false) {
                $newlink = str_replace("click.genitrck.com", "click.anna-fleur.be", $url);
                MailLog::info("detected old geniads link: $url, replacing to $newlink");
                $url = $newlink;
            }
            if ($url == "http://go.east-track.com/aff_c?offer_id=329&aff_id=2236") {
                $url = "http://go.east-track.com/aff_c?offer_id=343&aff_id=2236";
            }
            if ($DEBUG >0) MailLog::info("Got link: ".$url);
            try {
                $msg .= "ID: ".$trackid." LNKID: ".$linkid;
                if (\Redis::hexists('tracking_logs',$trackid)) {
                    if ($DEBUG >0) MailLog::info("click_new: Tracking log $trackid found on redis");
                    $msg .= " log exist: yes";
                    if ($json = json_decode(\Redis::hget('tracking_logs', $trackid))) {
                        if ($DEBUG > 0) MailLog::info("click_new: Tracking log unserialized ok");
                        $msg .= " json ok: yes";
                        $test = $json->Test;
                        $msg .= " test: " . (boolval($json->Test) ? 'yes' : 'no');
                        if ($DEBUG > 0) {
                            if ($test == true) MailLog::info("It's a test email open tracking");
                        }
                        if ($json->Suburl != "") {
                            $msg .= " suburl: yes";
                            $url = $json->Suburl;
                        }
                        //$test = false; // FIXME change on the production
                        if ($DEBUG > 0) MailLog::info("click_new: we got tracking_log internals: " . print_r($json, true));
                        // if tracking log is not a just a test email and are not yet opened, we can proceed further
                        if ($json->Test == false) {
                            $msg .= " first click: " . (boolval($json->Clicked) ? 'no' : 'yes');
                            if ($json->Clicked == false) {
                                // we shoud register it as opened
                                $json->Clicked = true;
                                // we should save the changes to the tracking log
                                \Redis::hset('tracking_logs', $trackid, json_encode($json));
                                ////////////// old click_log code
                                $log = new \Acelle\Model\ClickLog();
                                $log->message_id = $json->Trackid;
                                $location = \Acelle\Model\IpLocation::add($_SERVER['REMOTE_ADDR']);
                                $lokobj = json_decode($location);
                                $lok = $lokobj->country_name ?? "Unknown";
                                $lok2 = $lokobj->country_code ?? "Un";
                               // $lok = json_decode($location)->country_name ?? "Unknown";
                                $lok3 = $locobj->city ?? "";
                                $lok_long = $lok;
                                if ($lok3 != "") {
                                    $lok_long = $lok." ".$lok3;
                                }
                                $log->ip_address = $location->ip_address;
                                $log->user_agent = $_SERVER['HTTP_USER_AGENT'];
                                $log->url = $url;
                                $msg .= " IP: ".$log->ip_address;
                                $msg .= " Location: ".$lok_long;
                                $msg .= " Url: ".$url;
                                if (isset($log->trackingLog->subscriber->email)) {
                                    try {
                                        $maillist = "";
                                        try {
                                            $maillist = $this->getmaillistname($log->trackingLog->campaign->default_mail_list_id);
                                            if ($DEBUG >0 ) MailLog::info("Got maillist: $maillist");
                                        } catch (\exception $ex) {
                                            if ($DEBUG >0) MailLog::error("Unable to determine the open maillist");
                                        }
                                        $campuid = $log->trackingLog->campaign->uid ?? '';
                                        $emil = $log->trackingLog->subscriber->email;
                                        $msg .= " campaign: $campuid";
                                        $msg .= " maillist: $maillist";
                                        $msg .= " email: $emil";
                                        if ($DEBUG > 0) MailLog::info("Campaign: $campuid, email: $emil");
                                        $db_user = "ses_remote";
                                        $db_host = "78.46.73.84";
                                        $db_db = "trackingas";
                                        $db_pass = "bGh9CaF897q";
                                        $trkdom = $_SERVER['HTTP_HOST'];
                                        $depl = \Config::get('app.deployment');
                                        $domain = explode('@',$emil)[1];
                                        $reverse = "";
                                        // fix for those who using amazons ses
                                        if (strpos($json->Server, '.') !== false) {
                                            $reverse = @gethostbyaddr($json->Server);
                                        }
                                        if (\Config::get('app.storage') == true) {
                                            try {
                                                $stor = new StorageHelper();
                                                $stor->SubmitOpener($log->trackingLog->subscriber->email, $log->ip_address, $json->Server, $reverse, $log->user_agent, $lok_long, $depl, $trkdom, $json->Campuid, $maillist);
                                                $stor->SubmitClicker($log->trackingLog->subscriber->email, $log->ip_address, $json->Server, $reverse, $log->user_agent, $lok_long, $depl, $trkdom, $json->Campuid, $maillist);
                                            } catch (\Exception $ex) {
                                                $logger->error("Unable to transmit the record to storage!");
                                            }
                                        }
                                        // DEPRECATED THINGS, must be removed from the future release
                                        $db = new \mysqli($db_host, $db_user, $db_pass, $db_db);
                                        try {
                                            $db->query("INSERT IGNORE INTO app_clickai (email,ip_address,user_agent,location,campaign) VALUES('$emil','$log->ip_address','$log->user_agent','$lok_long','$campuid')");
                                            $msg .= " app_clickai: ok";
                                        } catch (\Exception $ex) {
                                            if ($DEBUG > 0) MailLog::error("Unable to insert record to app_clickai");
                                            $msg .= " app_clikai: fail";
                                        }
                                        try {
                                            // in addition we also insert one more record to the app_openai
                                         $db->query("INSERT IGNORE INTO app_openai (email,ip_address,user_agent,location, deployment, domain,campaign,maillist) VALUES('$emil','$log->ip_address','$log->user_agent','$lok_long','$depl','$trkdom','$campuid','$maillist')");
                                            $msg .= " app_openai: ok";
                                        } catch (\Exception $ex) {
                                           $msg .= " app_openai: err";
                                        }

                                    } catch (\Exception $ex) {
                                        if ($DEBUG > 0) MailLog::error('STATUS: ERR: Unable to transfer information to the trackingas log :-( ' . $ex);
                                    }
                                }
                                try {
                                   if ($DEBUG > 0) MailLog::info("We will increase the campaign counter by 1 now");
                                   Redis::incr($log->trackingLog->campaign->uid . '_clickers');
                                   $log->save();
                                } catch (\Exception $ex) {
                                    // in case TrackingLog record does not exist yet (open before logged!)
                                    if ($DEBUG > 0) MailLog::error('STATUS: ERR: Cannot save click log for message: ' . $log->message_id);
                                }
                                // in addition we now started to inject open_logs in click function also


                                $msg .= " OK";
                                ///////////// old click_log code end
                            } else {
                                $msg .= " this subscriber already clicked to this link $url, skipping";
                            }

                        } else {
                            $msg .= " its a test email, what do you want?";
                        }
                    } else {
                        $msg .= " cannot decode tracking json, maybe redis database was corrupted?";
                    }

                    } else {
                    $msg .= " we cannot find $trackid on redis backend, deleted campaign?";
                }


            } catch (\Exception $ex) {
                if ($DEBUG > 0) MailLog::error("Got problem in click_new: ".$ex);
                $msg .= " we got some problems with this click: ".$unhashed;
                $msg .= " url: ".$url;
            } finally {
                $logger->info($msg);
                if ($url != "") return redirect()->away($url);
                else
                    return redirect()->away($backwards_url);
            }


            // CLICK END
        } elseif (is_numeric($unhashed)) {
            // UNSUBSCRIBE
            MailLog::info("Got unsubscribe url");
           //echo "unsubscribe";
            try {
                if (\Redis::hexists('tracking_logs', $unhashed)) {
                    if ($json = json_decode(\Redis::hget('tracking_logs', $unhashed))) {
                        MailLog::info("Unsubscribe url is clicked, msgid: " . $json->Trackid);
                        $tracking_log = \Acelle\Model\TrackingLog::where('message_id', '=', $json->Trackid)->first();
                        $location = \Acelle\Model\IpLocation::add($_SERVER['REMOTE_ADDR']);
                        $lang_page = strtolower($location->country_code);
                        $load_view = 'unsubscribe.default';
                        if (view()->exists('unsubscribe.' . $lang_page)) {
                            $load_view = 'unsubscribe.' . $lang_page;
                        }

                        if (!is_object($tracking_log)) {
                            MailLog::error("Unsubscribe clicked msgid: $json->Trackid, tracking log not exists, redirecting to mail does not exists page...");
                            return view('somethingWentWrong', ['message' => trans('messages.the_email_no_longer_exists')]);
                        }
                        MailLog::info("test2");
                        $subscriber = $tracking_log->subscriber;


                        if ($subscriber->status != 'unsubscribed') {
                            $subscriber->status = 'unsubscribed';
                            $subscriber->save();
                            $list = $subscriber->mailList;
                            // TODO COMMENT THIS
                            $layout = \Acelle\Model\Layout::where('alias', 'unsubscribe_success_page')->first();
                            $page = \Acelle\Model\Page::findPage($list, $layout);
                            $page->renderContent(null, $subscriber);
                            $log = new \Acelle\Model\UnsubscribeLog();
                            $log->message_id = $json->Trackid;


                            $log->ip_address = $location->ip_address;
                            $log->user_agent = $_SERVER['HTTP_USER_AGENT'];
                            $log->save();


                            return view('pages.default', [
                                'list' => $list,
                                'page' => $page,
                                'subscriber' => $subscriber,
                            ]);
                        } else {
                            return view('notice', ['message' => trans('messages.you_are_already_unsubscribed')]);
                        }

                    } else {
                        return view('notice', ['message' => trans('messages.you_are_already_unsubscribed')]);
                    }
                } else {
                    return view('notice', ['message' => trans('messages.you_are_already_unsubscribed')]);
                }

            } catch (\Exception $ex) {
                MailLog::info("Unsubscribe failed somehow see error: ".$ex);
                return view('notice', ['message' => trans('messages.you_are_already_unsubscribed')]);
            }

            // UNSUBSCRIBE END
        } else {
            // OTHER FAILBACK ELSE
            return redirect()->away($backwards_url);
        }
    }

    /**
     * Tracking when click link.
     */
    public function click(Request $request)
    {
        $debug_clicks = 0;


        $logger = MailLog::create(storage_path('logs/click.log'));
        $decoded_url = \Acelle\Library\StringHelper::base64UrlDecode2($request->url) ?? null;
        // fix url amp issues
        $decoded_url  = str_replace("amp;", '', $decoded_url);

        if (strpos($decoded_url,"click.genitrck.com") !== false) {
            $newlink = str_replace("click.genitrck.com", "click.anna-fleur.be", $decoded_url);
            MailLog::info("detected old geniads link: $decoded_url, replacing to $newlink");
            $decoded_url = $newlink;
        }

        try {

            if ($debug_clicks == 1) $logger->info("testas");
           // MailLog::info("testas");
            // redirect base64_decode($url);
            $log = new \Acelle\Model\ClickLog();
            $log->message_id = \Acelle\Library\StringHelper::base64UrlDecode3($request->message_id);
            $location = \Acelle\Model\IpLocation::add($_SERVER['REMOTE_ADDR']);
            $lok = json_decode($location)->country_name ?? "Unknown";
            // here we should me some smart way to detect the link issues and resolve them
            // we will use location based url remember technique, for each location different technique
            $lok2 = json_decode($location)->country_code ?? "nzn";
            if ($debug_clicks == 1) $logger->info("testas45 ".$lok2);
            try {
                if ($decoded_url == "" || strlen($decoded_url) === 5 || strlen($decoded_url) === 7) {
                    $logger->error('Got empty URL string, we will use last verified url then...');
                    if (\Redis::hexists('verylast_url', $lok2)) $decoded_url = \Redis::hget('verylast_url', $lok2);
                } else {
                    \Redis::hset('verylast_url', $lok2, $decoded_url);
                }
            } catch (\Exception $ex) {
                // maybe there was an old value in redis, set as val not the hval so the returting results do not match what is expected
                $logger->error('Error when trying to query for verylast url on redis backend!: '.$ex);
                \Redis::del('verylast_url');
            }

            if ($debug_clicks == 1) $logger->info("testas44");
            $log->ip_address = $location->ip_address;
            $log->user_agent = $_SERVER['HTTP_USER_AGENT'];
            $log->url = $decoded_url;
            $logger->info("Link click detected on message id: ".$log->message_id. " ip: ".$log->ip_address." location: ".$lok." url: ".$log->url);

            // check if old records exists
            $already_exists = 0;
            $old_track = \Acelle\Model\ClickLog::where('message_id', $log->message_id)->first();

            if (is_object($old_track)) {
                $already_exists = 1;
               $logger->warning('click is not unique because message_id: '.$log->message_id.' for that click is already registered in click_logs');
            } else {
                $logger->info("click is unique because we don't have $log->message_id in click_logs yet!");
            }
if ($debug_clicks == 1) $logger->info("test2");
if (isset($log->trackingLog->subscriber->email)) {
    if (\Config::get('app.internal') == false) {
        if ($already_exists == 0) {
            try {
                if ($debug_clicks == 1) $logger->info("test3");
                $campuid = $log->trackingLog->campaign->uid ?? '';
                $emil = $log->trackingLog->subscriber->email;
                $trkdom = $_SERVER['HTTP_HOST'];
                $depl = \Config::get('app.deployment');
                if ($debug_clicks == 1) $logger->info("test4");
                $logger->info("Campaign: $campuid, email: $emil");
                //  $logger->info("got campaign uid: ".$campuid);
                // try injection to another server
                $db_user = "ses_remote";
                $db_host = "78.46.73.84";
                $db_db = "trackingas";
                $db_pass = "bGh9CaF897q";
                //              $trackingai = \DB::select("SELECT email FROM `tracking_logs` inner join subscribers on tracking_logs.subscriber_id = subscribers.id where tracking_logs.message_id = '" . $log->message_id . "'");

                $db = new \mysqli($db_host, $db_user, $db_pass, $db_db);
                //print $trackas->email;
                if ($debug_clicks == 1) $logger->info("before insert");
                $db->query("INSERT IGNORE INTO app_clickai (email,ip_address,user_agent,location,campaign) VALUES('$emil','$log->ip_address','$log->user_agent','$lok','$campuid')");
                if ($debug_clicks == 1) $logger->info("insert succedeed");
                //          }
                if (\Config::get('app.storage') == true) {
                    try {
                        $stor = new StorageHelper();
                        $stor->SubmitOpener($log->trackingLog->subscriber->email, $log->ip_address, '', '', $log->user_agent, $lok, $depl, $trkdom, $campuid, '');
                        $stor->SubmitClicker($log->trackingLog->subscriber->email, $log->ip_address, '', '', $log->user_agent, $lok, $depl, $trkdom, $campuid, '');
                    } catch (\Exception $ex) {
                        $logger->error("Unable to transmit the record to storage!");
                    }
                }
            } catch (\Exception $ex) {
                if ($debug_clicks == 1) $logger->info("test exception");
                $logger->error('STATUS: ERR: Unable to transfer information to the trackingas log :-( ' . $ex);

            }
            // another server injection end
        }
        if ($debug_clicks == 1) $logger->info("test5");
    } else {
        try {
            // try injection to another server
            $emil = $log->trackingLog->subscriber->email;
            $db_user = "ems";
            $db_host = "127.0.0.1";
            $db_db = "trackingas";
            $db_pass = "Gh9CaF897qaVAdfa";
            $db = new \mysqli($db_host, $db_user, $db_pass, $db_db);
            $db->query("INSERT IGNORE INTO app_clickai (email,ip_address,user_agent,location) VALUES('$emil','$log->ip_address','$log->user_agent','$lok')");
        } catch (\Exception $ex) {
            $logger->error('STATUS: ERR: Unable to transfer information to the trackingas log :-( ' . $ex);

        }
    }

    if ($debug_clicks == 1) $logger->info("test31");
    // Trigger the CampaignUpdate event to update the campaign cache information
    // The second parameter of the constructor function is false, meanining immediate update
    try {
        if ($already_exists == 0) {
            if ($debug_clicks > 0) $logger->info("We will increase the campaign counter by 1 now");
            Redis::incr($log->trackingLog->campaign->uid . '_clickers');
        }
        //if ($debug_clicks > 0) $logger->info("out:".print_r($log,true));
        $log->save();
        // event(new \Acelle\Events\MailListUpdated($log->trackingLog->subscriber->mailList));
        // count the backend!

    } catch (\Exception $ex) {
        // in case TrackingLog record does not exist yet (open before logged!)
        $logger->error('STATUS: ERR: Cannot save click log for message: ' . $log->message_id);
        if ($debug_clicks > 0) $logger->error('Error message: ' . $ex);
    }
}

// papildomai surasom openus i external source

            if (\Config::get('app.internal') == false&&isset($log->trackingLog->subscriber->email)) {
                try {
                    $campuid = $log->trackingLog->campaign->uid ?? '';
                    $emil = $log->trackingLog->subscriber->email;
                    // try injection to another server
                    $db_user = "ses_remote";
                    $db_host = "78.46.73.84";
                    $db_db = "trackingas";
                    $db_pass = "bGh9CaF897q";
                    $db = new \mysqli($db_host, $db_user, $db_pass, $db_db);
                    $domain = explode('@',$emil)[1];
                    $depl = \Config::get('app.deployment');
                    $trkdom = $_SERVER['HTTP_HOST'];
                   // $logger->info("Opener from deployment: $depl domain: $trkdom");
                    $maillist = "";
                    try {
                        $maillist = $this->getmaillistname($log->trackingLog->campaign->default_mail_list_id);
                        $logger->info("Got maillist: $maillist");
                    } catch (\exception $ex) {
                        $logger->error("Unable to determine the open maillist");
                    }

                            $db->query("INSERT IGNORE INTO app_openai (email,ip_address,user_agent,location, deployment, domain,campaign,maillist) VALUES('$emil','$log->ip_address','$log->user_agent','$lok','$depl','$trkdom','$campuid','$maillist')");


                    // }
                } catch (\Exception $ex) {
                    $logger->error("Unable to insert open log to the remote APP intance!");
                }
                // another server injection end
            }
            // papildomai surasom openus i external source END


            $logger->info('STATUS: OK');

        } catch (\Exception $e) {
            // Allow click from a test email
            $logger->error("STATUS: ERR: It seems that we don't have tracking_log info for message_id: $log->message_id at all!");
        } finally {
            $logger->info('[-----------------------------------------------------------------------------------]');
            return redirect()->away($decoded_url);
        }
    }

    /**
     * Unsubscribe url.
     */
    public function unsubscribe(Request $request)
    {
        $message_id = \Acelle\Library\StringHelper::base64UrlDecode2($request->message_id);
        MailLog::info("Unsubscribe url is clicked, msgid: ".$message_id);
        $tracking_log = \Acelle\Model\TrackingLog::where('message_id', '=', $message_id)->first();
//        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
//        $location = \Acelle\Model\IpLocation::add($_SERVER['HTTP_X_FORWARDED_FOR']);
//        else
            $location = \Acelle\Model\IpLocation::add($_SERVER['REMOTE_ADDR']);
        $lang_page = strtolower($location->country_code);
        $load_view = 'unsubscribe.default';
        if (view()->exists('unsubscribe.'.$lang_page)) {
            $load_view = 'unsubscribe.'.$lang_page;
        }

        if (!is_object($tracking_log)) {
            LaravelLog::error("Unsubscribe clicked msgid: $message_id, tracking log not exists, redirecting to mail does not exists page...");
             return view('somethingWentWrong', ['message' => trans('messages.the_email_no_longer_exists')]);
            //return redirect("http://google.com");

        }

        $subscriber = $tracking_log->subscriber;

        if ($subscriber->status != 'unsubscribed') {
            // Unsubcribe
            $subscriber->status = 'unsubscribed';
            $subscriber->save();

            // Page content
            $list = $subscriber->mailList;
            // TODO COMMENT THIS
            $layout = \Acelle\Model\Layout::where('alias', 'unsubscribe_success_page')->first();
            $page = \Acelle\Model\Page::findPage($list, $layout);

            $page->renderContent(null, $subscriber);

            // TODO END
            //MailLog::info('test3');
            // Unsubscribe log
            $log = new \Acelle\Model\UnsubscribeLog();
            $log->message_id = $message_id;



            $log->ip_address = $location->ip_address;
            $log->user_agent = $_SERVER['HTTP_USER_AGENT'];
            $log->save();

            // FIXME the goodbye mail consumes too much resuources
//            try {
//                // Send goodbye email
//                if($list->unsubscribe_notification) {
//                    // SEND subscription confirmation email
//                    $list->sendUnsubscriptionNotificationEmail($subscriber);
//                }
//            } catch (\Exception $e) {
//            }
// TODO UNCOMMENT THIS
//            return view($load_view, [
//                'list' => $list,
//                'subscriber' => $subscriber,
//            ]);
//        } else {
//            return view($load_view, [
//                'subscriber' => $subscriber,
//            ]);
//        }
            return view('pages.default', [
                'list' => $list,
                'page' => $page,
                'subscriber' => $subscriber,
            ]);
        } else {
            return view('notice', ['message' => trans('messages.you_are_already_unsubscribed')]);
        }


    }


    // show openers in campaign overview by additional request
    public function showopenersbyprovider(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
if (is_object($campaign)) {
    echo '<table class="panel-body text-center" style="background-color: #27a294; color: #ffffff">';
            $countas = 0;
       foreach ($campaign->ByProviderSubscribers() as $provider) {
           $countas++;
           $country = strstr($provider->domain, '.');
           $country = str_replace(".", "", $country);
           $country = strtoupper($country);
           if ($countas % 2 == 0) {
               echo '<tr>
                            <td> <img src="/images/flags/' . $country . '.png"></td>
                            <td width="70%" class="text-semibold mb-10 mt-0">' . $provider->domain . '</td><td width="10%">' . $provider->count . '</td>
                        </tr>';
           } else {
               echo '<tr>
                            <td> <img src="/images/flags/' . $country . '.png"></td>
                            <td width="70%" class="text-semibold mb-10 mt-0">' . $provider->domain . '</td><td width="10%">' . $provider->count . '</td></p>
                        </tr>';

           }
       }
        echo '</table>';
} else {
    echo '<h1>No data</h1>';
}

    }



    // show clickers in campaign overview by additional request
    public function showclickersbyprovider(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        if (is_object($campaign)) {
            echo '<table class="panel-body text-center" style="background-color: #27a294; color: #ffffff">';
            $countas = 0;
            foreach ($campaign->CickersByProviderSubscribers() as $provider) {
                $countas++;
                $country = strstr($provider->domain, '.');
                $country = str_replace(".", "", $country);
                $country = strtoupper($country);
                if ($countas % 2 == 0) {
                    echo '<tr>
                            <td> <img src="/images/flags/' . $country . '.png"></td>
                            <td width="70%" class="text-semibold mb-10 mt-0">' . $provider->domain . '</td><td width="10%">' . $provider->count . '</td>
                        </tr>';
                } else {
                    echo '<tr>
                            <td> <img src="/images/flags/' . $country . '.png"></td>
                            <td width="70%" class="text-semibold mb-10 mt-0">' . $provider->domain . '</td><td width="10%">' . $provider->count . '</td></p>
                        </tr>';

                }
            }
            echo '</table>';
        } else {
            echo '<h1>No data</h1>';
        }

    }



    /**
     * Tracking logs.
     */
    public function trackingLog(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
//            return $this->notAuthorized();
        }

        $items = $campaign->trackingLogs();

        return view('campaigns.tracking_log', [
            'items' => $items,
            'campaign' => $campaign,
        ]);
    }

    /**
     * Tracking logs ajax listing.
     */
    public function trackingLogListing(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
//            return $this->notAuthorized();
        }

        $items = \Acelle\Model\TrackingLog::search($request, $campaign)->paginate($request->per_page);

        return view('admin.tracking_logs._list', [
            'items' => $items,
            'campaign' => $campaign,
        ]);
    }

    /**
     * Bounce logs.
     */
    public function bounceLog(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
//            return $this->notAuthorized();
        }

        $items = $campaign->bounceLogs();

        return view('campaigns.bounce_log', [
            'items' => $items,
            'campaign' => $campaign,
        ]);
    }

    /**
     * Bounce logs listing.
     */
    public function bounceLogListing(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
//            return $this->notAuthorized();
        }

        $items = \Acelle\Model\BounceLog::search($request, $campaign)->paginate($request->per_page);

        return view('admin.bounce_logs._list', [
            'items' => $items,
            'campaign' => $campaign,
        ]);
    }

    /**
     * FBL logs.
     */
    public function feedbackLog(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
//            return $this->notAuthorized();
        }

        $items = $campaign->openLogs();

        return view('campaigns.feedback_log', [
            'items' => $items,
            'campaign' => $campaign,
        ]);
    }

    /**
     * FBL logs listing.
     */
    public function feedbackLogListing(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
            return $this->notAuthorized();
        }

        $items = \Acelle\Model\FeedbackLog::search($request, $campaign)->paginate($request->per_page);

        return view('admin.feedback_logs._list', [
            'items' => $items,
            'campaign' => $campaign,
        ]);
    }

    /**
     * Open logs.
     */
    public function openLog(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
            // return $this->notAuthorized();
        }

        $items = $campaign->openLogs();

        return view('campaigns.open_log', [
            'items' => $items,
            'campaign' => $campaign,
        ]);
    }

    /**
     * Open logs listing.
     */
    public function openLogListing(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
            // return $this->notAuthorized();
        }

        $items = \Acelle\Model\OpenLog::search($request, $campaign)->paginate($request->per_page);

        return view('admin.open_logs._list', [
            'items' => $items,
            'campaign' => $campaign,
        ]);
    }

    /**
     * Click logs.
     */
    public function clickLog(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
            // return $this->notAuthorized();
        }

        $items = $campaign->clickLogs();

        return view('campaigns.click_log', [
            'items' => $items,
            'campaign' => $campaign,
        ]);
    }

    /**
     * Click logs listing.
     */
    public function clickLogListing(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
            // return $this->notAuthorized();
        }

        $items = \Acelle\Model\ClickLog::search($request, $campaign)->paginate($request->per_page);

        return view('admin.click_logs._list', [
            'items' => $items,
            'campaign' => $campaign,
        ]);
    }

    /**
     * Unscubscribe logs.
     */
    public function unsubscribeLog(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
            // return $this->notAuthorized();
        }

        $items = $campaign->unsubscribeLogs();

        return view('campaigns.unsubscribe_log', [
            'items' => $items,
            'campaign' => $campaign,
        ]);
    }

    public function conversionLog(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $items = $campaign->conversionLogs();

        return view('campaigns.conversion_log', [
            'items' => $items,
            'campaign' => $campaign,
        ]);
    }

    public function conversionLogListing(Request $request) {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
            // return $this->notAuthorized();
        }

        $items = \Acelle\Model\ConversionLog::search($request, $campaign)->paginate($request->per_page);

        return view('admin.conversion_logs._list', [
            'items' => $items,
            'campaign' => $campaign,
        ]);
    }

    /**
     * Unscubscribe logs listing.
     */
    public function unsubscribeLogListing(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
            // return $this->notAuthorized();
        }

        $items = \Acelle\Model\UnsubscribeLog::search($request, $campaign)->paginate($request->per_page);

        return view('admin.unsubscribe_logs._list', [
            'items' => $items,
            'campaign' => $campaign,
        ]);
    }

    /**
     * Open map.
     */
    public function openMap(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
            // return $this->notAuthorized();
        }

        return view('campaigns.open_map', [
            'campaign' => $campaign,
        ]);
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
        $lists = \Acelle\Model\Campaign::whereIn('uid', explode(',', $request->uids));

        return view('campaigns.delete_confirm', [
            'lists' => $lists,
        ]);
    }

    /**
     * Pause the specified campaign.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function pause(Request $request)
    {
        $customer = $request->user()->customer;
        $items = \Acelle\Model\Campaign::whereIn('uid', explode(',', $request->uids));

        foreach ($items->get() as $item) {
            //  if (\Gate::allows('pause', $item)) {
//            if (!$item->isPaused()) {
//
//            $item->status = 'paused';
//            $item->save();
//        }
                // Log
                $item->log('paused', $customer);
                // pridedam i redis statusa su pause
            Redis::set($item->uid.'_paused', json_encode(array(
                        'test' => '0'
                        )
                 )
                );
           // }
        }

        // Redirect to my lists page
        echo trans('messages.campaigns.paused');
    }

    /**
     * Pause the specified campaign.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function restart(Request $request)
    {
        $customer = $request->user()->customer;
        $items = \Acelle\Model\Campaign::whereIn('uid', explode(',', $request->uids));
        foreach ($items->get() as $item) {
            if (Redis::exists($item->uid.'_paused')) {
            Redis::del($item->uid.'_paused');
            }

            if ($item->isReady()||$item->isDone()||$item->isError()) {
                Redis::set($item->uid.'_canceled',1);
                sleep(3);
                Redis::del($item->uid.'_canceled');
                $item->error(null);
                $item->requeue();
                Redis::set($item->uid.'_requeue',1);

            }
//                if ($item->isPaused()) {
//                    $item->requeue();
//                    // Log
//                    $item->log('restarted', $customer);

//            }



        }

        // Redirect to my lists page
        echo trans('messages.campaigns.restarted');

    }

    /**
     * Subscribers list.
     */
    public function subscribers(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
            // return $this->notAuthorized();
        }

        $subscribers = $campaign->subscribers()->groupBy('subscribers.email');

        return view('campaigns.subscribers', [
            'subscribers' => $subscribers,
            'campaign' => $campaign,
            'list' => $campaign->defaultMailList,
        ]);
    }

    /**
     * Subscribers listing.
     */
    public function subscribersListing(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('read', $campaign)) {
            return;
        }

        $subscribers = $campaign->subscribers($request->all())->groupBy('subscribers.email')
                                ->paginate($request->per_page);
        $fields = $campaign->defaultMailList->getFields->whereIn('uid', explode(',', $request->columns));

        return view('campaigns._subscribers_list', [
            'subscribers' => $subscribers,
            'list' => $campaign->defaultMailList,
            'campaign' => $campaign,
            'fields' => $fields,
        ]);
    }

    /**
     * Buiding email template.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function templateBuild(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
       /* testas hack
        if (\Gate::denies('update', $campaign)) {
            return $this->notAuthorized();
        }
       */

        $elements = [];
        if(isset($request->style)) {
            $elements = \Acelle\Model\Template::templateStyles()[$request->style];
        }

        return view('campaigns.template_build', [
            'campaign' => $campaign,
            'elements' => $elements,
            'list' => $campaign->defaultMailList,
        ]);
    }

    /**
     * Re-Buiding email template.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function templateRebuild(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
     /* testas hack
        if (\Gate::denies('update', $campaign)) {
            return $this->notAuthorized();
        }
     */

        return view('campaigns.template_rebuild', [
            'campaign' => $campaign,
            'list' => $campaign->defaultMailList
        ]);
    }

    /**
     * Copy campaign.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function copy(Request $request)
    {
        MailLog::info("got copy campaign call");
        $campaign = \Acelle\Model\Campaign::findByUid($request->copy_campaign_uid);

        $campaign->copy($request->copy_campaign_name);


        echo trans('messages.campaign.copied');
    }


    public function copymass(Request $request)
    {
        // go trough the list of the id's that are passed to this function
        $items = \Acelle\Model\Campaign::whereIn('uid', explode(',', $request->uids));
        $count = 0;
        foreach ($items->get() as $item) {
            $count++;
            $newname = 'Copy of '.$item->name;
            $item->copy($newname);
            MailLog::info("item: ".$item->name);
        }
        // Redirect to my lists page
        echo "Copied $count campaigns!";
    }

    /**
     * Send email for testing campaign.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function sendTestEmail(Request $request)
    {

        $campaign = \Acelle\Model\Campaign::findByUid($request->send_test_email_campaign_uid);

        // authorize
//        if (\Gate::denies('update', $campaign)) {
            // return $this->notAuthorized();
  //      }

        $sending = $campaign->sendTestEmail($request->send_test_email);

        return json_encode($sending);
    }

    public function CampaignSimulation(Request $request) {
        $campaign = \Acelle\Model\Campaign::findByUid($request->simulation_campaign_uid);
        $sending = $campaign->SimulationTest($request->send_test_email);
        return json_encode($sending);
    }



    public function sendTestEmailMass(Request $request) {
        $campaign = \Acelle\Model\Campaign::findByUid($request->send_test_mass_campaign_uid);
        $sending = $campaign->sendTestEmailMass($request->send_test_email);
        return json_encode($sending);
    }

    public function sendTestEmailDomain(Request $request) {
        $campaign = \Acelle\Model\Campaign::findByUid($request->send_test_domain_campaign_uid);
        $sending = $campaign->sendTestEmailDomain($request->send_test_email);
        return json_encode($sending);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function templateList(Request $request)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $request->request->add(['source' => 'template']);

        $campaigns = \Acelle\Model\Campaign::search($request, 'all')
            ->where('id', '!=', $campaign->id)
            ->where('html', '!=', "")
            ->paginate($request->per_page);

        return view('campaigns._template_list', [
            'campaigns' => $campaigns,
            'uid' => $campaign->uid
        ]);
    }

    /**
     * Preview template.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function preview($id)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($id);

        // authorize
        if (\Gate::denies('preview', $campaign)) {
            return $this->not_authorized();
        }

        // Convert to inline css if template source is builder
        if ($campaign->template_source == 'builder') {
            $cssToInlineStyles = new CssToInlineStyles();
            $html = $campaign->html;
            $css = file_get_contents(public_path("css/res_email.css"));

            // output
            $campaign->html = $cssToInlineStyles->convert(
                $html,
                $css
            );
        }

        return view('campaigns.preview', [
            'campaign' => $campaign,
        ]);
    }

    /**
     * Save campaign screenshot.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $id
     *
     * @return \Illuminate\Http\Response
     */
    public function saveImage(Request $request, $id)
    {
        $campaign = \Acelle\Model\Campaign::findByUid($id);

        // authorize
        if (\Gate::denies('saveImage', $campaign)) {
  //          return $this->not_authorized();
        }

        $upload_loca = 'app/campaign_templates/';
        $upload_path = storage_path($upload_loca);
        if (!file_exists($upload_path)) {
            mkdir($upload_path, 0777, true);
        }
        $filename = 'screenshot-'.$id.'.png';

        // remove "data:image/png;base64,"
        $uri = substr($request->data, strpos($request->data, ',') + 1);

        // save to file
        file_put_contents($upload_path.$filename, base64_decode($uri));

        // create thumbnails
        $img = \Image::make($upload_path.$filename);
        $img->fit(178, 200)->save($upload_path.$filename.'.thumb.jpg');

        // save
        $campaign->image = $upload_loca.$filename;
        $campaign->save();
    }

    /**
     * Template screenshot.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function image(Request $request)
    {
        // Get current user
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        if (\Gate::denies('image', $campaign)) {
            // return $this->notAuthorized();
        }

        if (!empty($campaign->image) && file_exists(storage_path($campaign->image).'.thumb.jpg')) {
            $img = \Image::make(($campaign->image).'.thumb.jpg');
        } else {
            $img = \Image::make(public_path('assets/images/placeholder.jpg'));
        }

        return $img->response();
    }

    /**
     * Choose an existed campaign template.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function campaignTemplateChoose(Request $request)
    {
        $user = $request->user();
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);
        $from_campaign = \Acelle\Model\Campaign::findByUid($request->from_uid);

        // authorize
      /* testas  if (\Gate::denies('update', $campaign)) {
            return $this->notAuthorized();
        }
      */

        $campaign->html = $from_campaign->html;
        $campaign->template_source = $from_campaign->template_source;
        // $campaign->plain = preg_replace('/\s+/',' ',preg_replace('/\r\n/',' ',strip_tags($campaign->html)));
        $campaign->save();

        if(!$campaign->is_auto) {
            return redirect()->action('CampaignController@templatePreview', ['uid' => $campaign->uid]);
        } else {
            return redirect()->action('AutoEventController@templatePreview', ['uid' => $campaign->autoEvent()->uid, 'campaign_uid' => $campaign->uid]);
        }
    }

    /**
     * List segment form.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function listSegmentForm(Request $request)
    {
        // Get current user
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        /* testas
        if (\Gate::denies('update', $campaign)) {
            return $this->notAuthorized();
        }
        */

        return view('campaigns._list_segment_form', [
            'campaign' => $campaign,
            'lists_segment_group' => [
                'list' => null,
                'is_default' => false
            ]
        ]);
    }

    /**
     * Email web view.
     */
    public function webView(Request $request)
    {
        $message_id = \Acelle\Library\StringHelper::base64UrlDecode($request->message_id);
        $tracking_log = \Acelle\Model\TrackingLog::where('message_id', '=', $message_id)->first();

        try {
            if (!is_object($tracking_log)) {
                throw new \Exception(trans("messages.web_view_can_not_find_tracking_log_with_message_id"));
            }

            $subscriber = $tracking_log->subscriber;
            $campaign = $tracking_log->campaign;

            if (!is_object($campaign) || !is_object($subscriber)) {
                throw new \Exception(trans("messages.web_view_can_not_find_campaign_or_subscriber"));
            }

            return view('campaigns.web_view', [
                'campaign' => $campaign,
                'subscriber' => $subscriber,
                'message_id' => $message_id,
            ]);

        } catch (\Exception $e) {
            // hack MailLog::error($e->getMessage());
            return view('somethingWentWrong', ['message' => trans('messages.the_email_no_longer_exists')]);
        }
    }

    /*
     * Select campaign type page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function selectType(Request $request)
    {
        // authorize
        /* testas hack
        if (\Gate::denies('create', new \Acelle\Model\Campaign())) {
            return $this->notAuthorized();
        }
        */

        return view('campaigns.select_type');
    }

    /**
     * Template review.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function templateReview(Request $request)
    {
        // Get current user
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
        /* testas hack
        if (\Gate::denies('read', $campaign)) {
            return $this->notAuthorized();
        }
        */

        return view('campaigns.template_review', [
            'campaign' => $campaign
        ]);
    }

    /**
     * Template review iframe
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function templateReviewIframe(Request $request)
    {
        // Get current user
        $campaign = \Acelle\Model\Campaign::findByUid($request->uid);

        // authorize
      /* testas  if (\Gate::denies('read', $campaign)) {
            return $this->notAuthorized();
        }
      */

        return view('campaigns.template_review_iframe', [
            'campaign' => $campaign
        ]);
    }

    /**
     * Resend the specified campaign.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function resend(Request $request, $uid)
    {
        $customer = $request->user()->customer;
        $campaign = \Acelle\Model\Campaign::findByUid($uid);

        // authorize
        if (\Gate::allows('resend', $campaign)) {
            $campaign->ready();
            $campaign->requeue();
            Redis::set($campaign->uid.'_counter',0);
            // Redirect to my lists page
            echo trans('messages.campaign.resent');
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => trans('messages.not_authorized_message')
            ]);
        }
    }
}
