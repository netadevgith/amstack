<?php

namespace Acelle\Http\Controllers\Api;

use Acelle\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Acelle\Library\Log as MailLog;
use Illuminate\Support\Facades\Log as LaravelLog;
use Redis;

class NonDBApiController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        parent::__construct();
        $this->middleware('api_auth', ['except' => [
            'campaign_counter','get_data','global_data_counts','hardbounce'
        ]]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
    exit;
    }

    public function get_data(Request $request)
    {
        $show_download = 0;
        if (Redis::exists($request->uid."_sent_data")&&Redis::hlen($request->uid."_sent_data") > 0) $show_download = 1;

        $deferred_enabled = 0;
        if (Redis::exists($request->uid.'_deferred_setting')) {
            $deferred_enabled = 1;
        }
        $data['sent'] = Redis::get($request->uid.'_sent') ?? "0";
        $data['bounce'] = Redis::get($request->uid.'_bounced') ?? "0";
        $data['deferred'] = Redis::get($request->uid.'_deferred') ?? "0";
        $data['deferred_sent'] = Redis::get($request->uid.'_deferred_sent') ?? "0";
        $data['deferred_enabled'] = $deferred_enabled;
        $data['download'] = $show_download;
        // return some items data, to show in the realtime
if (Redis::exists($request->uid.'_undelivered_val')) {
    $keys = Redis::lrange($request->uid . '_undelivered_val', -3, -1);
    $undelivered = array();
    foreach ($keys as $key) {
        if (Redis::hexists($request->uid . '_undelivered_data', $key)) {
            $json = json_decode(Redis::hget($request->uid . '_undelivered_data', $key));
            $json->email = $key;
            $undelivered[] = $json;
        }
    }
    // MailLog::info("data: ".print_r($undelivered,true));
    $data['undelivered'] = $undelivered;
} else {
    $data['undelivered'] = '';
}

        return json_encode($data);
    }


    public function hardbounce(Request $request) {
        if (!Redis::hexists('blacklists',$request->email)) {
            Redis::hset('blacklists',$request->email,json_encode([ 'status'=> 'Catched in parser']));
        }
    }



    public function global_data_counts(Request $request)
    {
        $total_entries = 0;
        $total_sent = 0;
        $keys = Redis::keys('*_static');
        $domains = array();
        foreach ($keys as $key) {
            $uid = explode("_",$key)[1] ?? 0;
            if (Redis::exists($uid)) {
                $total = Redis::get($uid . '_total') ?? 0;
                $sent = Redis::get($uid . '_sent') ?? 0;
                $counter = Redis::get($uid .'_counter') ?? 0;
                $total_entries = ($total_entries + $total);
                $total_sent = ($total_sent + $sent);
                // collect independend domain statistics
                $domstats = json_decode(Redis::get($uid . '_locked'));
                if (is_object($domstats)) {
                    foreach ($domstats as $key => $value) {
                        $que = $total - $counter + $value->kiekis;
                        $q = 0;
                        if (array_key_exists($key,$domains)) {
                            if ($value->current > 0) {
                                if (isset($domains[$key]->count) && $domains[$key]->queue > 0) {
                                    $q = $que + $domains[$key]->queue;
                                } else {
                                    $q = $que;
                                }

                            }
                            if (isset($domains[$key]->count)) $doms = $domains[$key]->count + $value->kiekis;
                            else $doms = $value->kiekis;
                            //MailLog::info('key '.$doms);
                            $domains[$key] = ['domain' => $key, 'count' => $doms, 'current' => $value->current, 'queue' => $q ];
                        } else {
                            if ($value->current > 0) {
                                $q = $que;
                            }
                            $domains[$key] = ['domain' => $key, 'count' => $value->kiekis, 'current' => $value->current, 'queue' => $q ];
                        }
                    }
                }


            }
        }
        $queue = ($total_entries - $total_sent);
        return json_encode([ 'total_entries' => $total_entries, 'total_sent' => $total_sent, 'queue' => $queue, 'domains' => $domains ]);
    }


    // this function communicates with parser, parser posts the delivery entry, to be added to specified campaign counters
    public function campaign_counter(Request $request)
    {
        if ($request->isMethod('post')) {
            if (isset($request->type) && isset($request->uid) && $request->uid != "" && $request->type != "" && Redis::exists($request->uid)) {
             //   MailLog::info("Got campaign counters from parser API: campaign: " . $request->uid. " type: ".$request->type);
                if ($request->type != "sent") {
                    // pirma patikrinam ar jau neegzsistuoja ir tik tada dedam, jeigu pakartotinai toks irasas yra - tada nededam
                    if (!Redis::hexists($request->uid."_undelivered_data", $request->email)) {
                        // jeigu yra nustatymas deferred uzdetas ant campaign, nenaujinam jokiu counteriu o grazinam iskart i eile kontakta
                        if (Redis::exists($request->uid.'_deferred_setting') && $request->type == "deferred") {
                            if (Redis::hexists('campaign_'.$request->uid.'_static',$request->email)) {
                                $tmprec = Redis::hget('campaign_'.$request->uid.'_static',$request->email);
                                Redis::sAdd("campaign_" . $request->uid . "_deferreds", $tmprec);
                                Redis::hdel('campaign_'.$request->uid.'_static',$request->email);
                                //Redis::decr($request->uid."_counter");
                            }
                        } else {
                            Redis::incr($request->uid . "_" . $request->type);
                            //MailLog::info("Not sent to the subscriber $request->email Campaign: $request->uid status: $request->status");
                            Redis::hset($request->uid . "_undelivered_data", $request->email, json_encode(['status' => $request->status, 'type' => $request->type]));
                            Redis::rpush($request->uid . '_undelivered_val', $request->email);
                        }
                    }
                }
                // jeigu maila veliau vis del to pavyko nusiusti turim susitvarkyti, kad jau uzsetintas irasas butu nusentintas bei decreasinti reikiami buve uzsetinti tipai
                elseif ($request->type == "sent") {
                    if (Redis::hexists($request->uid . "_undelivered_data", $request->email)) {
                        // TODO debug this for REAL - OK
                     //   MailLog::info("Decreasing value of: ".$request->uid."_".json_decode(Redis::hget($request->uid . "_undelivered_data", $request->email))->type);
                        Redis::decr($request->uid."_".json_decode(Redis::hget($request->uid . "_undelivered_data", $request->email))->type);
                        Redis::hdel($request->uid . "_undelivered_data", $request->email);
                    }
                    // set deliveries to uid_sent_data hset list
                        Redis::hset($request->uid."_sent_data", $request->email,$request->status);
                        Redis::incr($request->uid . "_sent");
                    }
                }
            }

    }




}
