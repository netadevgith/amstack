<?php

/**
 * Abstract Campaign class
 *
 * Model class for campaigns related functionalities.
 * This is the center of the application
 *
 * LICENSE: This product includes software developed at
 * the Acelle Co., Ltd. (http://acellemail.com/).
 *
 * @category   MVC Model
 *
 * @author     N. Pham <n.pham@acellemail.com>
 * @author     L. Pham <l.pham@acellemail.com>
 * @copyright  Acelle Co., Ltd
 * @license    Acelle Co., Ltd
 *
 * @version    1.0
 *
 * @link       http://acellemail.com
 */

namespace Acelle\Model;

use Acelle\Library\TaskRunner;
use Illuminate\Database\Eloquent\Model;
use Acelle\Library\Log as MailLog;
use Acelle\Library\StringHelper;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;
use DB;
use Carbon\Carbon;
use Acelle\Library\CloudFlareHelper;
use Acelle\Model\SystemJob;
use Acelle\Exceptions\CampaignPausedException;
use Acelle\Exceptions\CampaignErrorException;
use Illuminate\Support\Facades\Storage;

use Redis;


abstract class AbsCampaign extends Model
{
    // Campaign status
    const STATUS_NEW = 'new'; # naujas campaign, neparuostas siuntimui
    const STATUS_SCHEDULED = 'scheduled'; # naujas campaign, paruostas siuntimui ateityje
    const STATUS_READY = 'ready'; // naujas campaign paruostas siuntimui equiv. to 'queue'
    const STATUS_SENDING = 'sending'; // statusas kada vykdomas realus siuntimas
    const STATUS_PREPARING = 'preparing'; // statusas kada siuntimas yra ruosiamas
    const STATUS_ERROR = 'error';
    const STATUS_DONE = 'done';
    const STATUS_PAUSED = 'paused';

    // Campaign types
    const TYPE_REGULAR = 'regular';
    const TYPE_PLAIN_TEXT = 'plain-text';

    // Campaign settings
    const WORKER_DELAY = 1;
    const DKIM_SELECTOR = 'mailer';

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['created_at', 'updated_at', 'run_at'];

    /**
     * Get campaign's default mail list
     */
    public function defaultMailList()
    {
        if ($this->isAuto()) {
            return $this->autoEvent()->automation->belongsTo('Acelle\Model\MailList', 'default_mail_list_id');
        } else {
            return $this->belongsTo('Acelle\Model\MailList', 'default_mail_list_id');
        }
    }

    /**
     * Get campaign's associated mail list
     */
    public function mailLists()
    {
        return $this->belongsToMany('Acelle\Model\MailList', 'campaigns_lists_segments');
    }


    public function ByProviderSubscribers()
    {
        // $ids = $this->belongsToMany('Acelle\Model\MailList', 'campaigns_lists_segments');
        // MailLog::info("INFO: ".print_r($ids,true));
        //  if ($this->ids) {
        //$list = DB::select("select (SUBSTRING_INDEX(SUBSTR(email, INSTR(email, '@') + 1), '.',2)) as domain, count(*) as count from subscribers inner join campaigns_lists_segments on campaigns_lists_segments.mail_list_id=subscribers.mail_list_id where campaigns_lists_segments.campaign_id = $this->id group by domain order by count desc");
        $list = DB::select("select (SUBSTRING_INDEX(SUBSTR(email, INSTR(email, '@') + 1), '.',2)) as domain, count(*) as count, subscribers.opened_at as atidaryta from subscribers inner join tracking_logs on subscribers.id = tracking_logs.subscriber_id inner join open_logs on tracking_logs.message_id = open_logs.message_id inner join campaigns_lists_segments on campaigns_lists_segments.mail_list_id=subscribers.mail_list_id where campaigns_lists_segments.campaign_id = $this->id group by domain order by count desc");
        return $list;
        // }
        // return null;
    }

    public function CickersByProviderSubscribers() {

        $list = DB::select("select (SUBSTRING_INDEX(SUBSTR(email, INSTR(email, '@') + 1), '.',2)) as domain, count(*) as count, 
subscribers.opened_at as atidaryta from subscribers inner join tracking_logs on subscribers.id = tracking_logs.subscriber_id inner join click_logs on 
tracking_logs.message_id = click_logs.message_id inner join campaigns_lists_segments on campaigns_lists_segments.mail_list_id=subscribers.mail_list_id where campaigns_lists_segments.campaign_id = $this->id group by domain order by count desc");
        return $list;

        }



    /**
     * Get campaign validation rules.
     */
    public function rules()
    {
        return array(
            'name' => 'required',
            'subject' => 'required',
            'from_email' => 'required|email',
            'from_name' => 'required',
            'reply_to' => 'required|email',
        );
    }

    /**
     * Get campaign validation rules.
     */
    public function automatedCampaignRules()
    {
        return array(
            'type' => 'required',
            'name' => 'required',
            'subject' => 'required',
            'from_email' => 'required|email',
            'from_name' => 'required',
            'reply_to' => 'required|email',
        );
    }

    /**
     * Get the auto events of the campaign.
     */
    public function autoEvents()
    {
        return $this->belongsToMany('Acelle\Model\AutoEvent', 'auto_campaigns');
    }

    /**
     * Get the auto event of the campaign.
     */
    public function autoEvent()
    {
        return $this->autoEvents()->first();
    }

    /**
     * Get the links for campaign.
     */
    public function links()
    {
        return $this->belongsToMany('Acelle\Model\Link', 'campaign_links');
    }

    /**
     * Get the customer.
     */
    public function customer()
    {
        return $this->belongsTo('Acelle\Model\Customer');
    }

    /**
     * Get campaign tracking logs.
     *
     * @return mixed
     */
    public function trackingLogs()
    {
        return $this->hasMany('Acelle\Model\TrackingLog');
    }

    /**
     * Get campaign bounce logs.
     *
     * @return mixed
     */
    public function bounceLogs()
    {
        return BounceLog::select('bounce_logs.*')->leftJoin('tracking_logs', 'tracking_logs.message_id', '=', 'bounce_logs.message_id')
            ->where('tracking_logs.campaign_id', '=', $this->id);
    }

    /**
     * Get campaign open logs.
     *
     * @return mixed
     */
    public function openLogs()
    {
        return OpenLog::select('open_logs.*')->leftJoin('tracking_logs', 'tracking_logs.message_id', '=', 'open_logs.message_id')
            ->where('tracking_logs.campaign_id', '=', $this->id);
    }

    /**
     * Get campaign click logs.
     *
     * @return mixed
     */
    public function clickLogs()
    {
        return ClickLog::select('click_logs.*')->leftJoin('tracking_logs', 'tracking_logs.message_id', '=', 'click_logs.message_id')
            ->where('tracking_logs.campaign_id', '=', $this->id);
    }

    /**
     * Get campaign feedback loop logs.
     *
     * @return mixed
     */
    public function feedbackLogs()
    {
        return FeedbackLog::select('feedback_logs.*')->leftJoin('tracking_logs', 'tracking_logs.message_id', '=', 'feedback_logs.message_id')
            ->where('tracking_logs.campaign_id', '=', $this->id);
    }

    /**
     * Get campaign unsubscribe logs.
     *
     * @return mixed
     */
    public function unsubscribeLogs()
    {
        return UnsubscribeLog::select('unsubscribe_logs.*')->leftJoin('tracking_logs', 'tracking_logs.message_id', '=', 'unsubscribe_logs.message_id')
            ->where('tracking_logs.campaign_id', '=', $this->id);
    }

    public function conversionLogs()
    {
        return ConversionLog::select('campaigns_conversions.*')->leftJoin('campaigns','campaigns_conversions.campaign_uid','=','campaigns.uid')
            ->where('campaigns_conversions.campaign_uid','=',$this->uid);
    }

    /**
     * Get campaign list segment.
     *
     * @return mixed
     */
    public function listsSegments()
    {
        return $this->hasMany('Acelle\Model\CampaignsListsSegment');
    }

    /**
     * Get campaign lists segments.
     *
     * @return mixed
     */
    public function getListsSegments()
    {
        if($this->isAuto()) {
            return $this->autoEvent()->automation->getListsSegments();
        }

        $lists_segments = $this->listsSegments;

        if($lists_segments->isEmpty()) {
            $lists_segment = new CampaignsListsSegment();
            $lists_segment->campaign_id = $this->id;
            $lists_segment->is_default = true;

            $lists_segments->push($lists_segment);
        }

        return $lists_segments;
    }


    /**
     *  Get groups available for the campaigns
     *
     */

    public function getCampaignGroups()
    {
        $listas = DB::table('groups')->get();
        return $listas;
    }


    /**
     * Get campaign lists segments group by list.
     *
     * @return mixed
     */
    public function getListsSegmentsGroups()
    {
        if($this->isAuto()) {
            return $this->autoEvent()->automation->getListsSegmentsGroups();
        }

        $lists_segments = $this->getListsSegments();
        $groups = [];

        foreach($lists_segments as $lists_segment) {
            if(!isset($groups[$lists_segment->mail_list_id])) {
                $groups[$lists_segment->mail_list_id] = [];
                $groups[$lists_segment->mail_list_id]['list'] = $lists_segment->mailList;
                if($this->default_mail_list_id == $lists_segment->mail_list_id) {
                    $groups[$lists_segment->mail_list_id]['is_default'] = true;
                } else {
                    $groups[$lists_segment->mail_list_id]['is_default'] = false;
                }
                $groups[$lists_segment->mail_list_id]['segment_uids'] = [];
            }
            if(is_object($lists_segment->segment) && !in_array($lists_segment->segment->uid, $groups[$lists_segment->mail_list_id]['segment_uids'])) {
                $groups[$lists_segment->mail_list_id]['segment_uids'][] = $lists_segment->segment->uid;
            }
        }

        return $groups;
    }

    /**
     * Prepare the email content using Swift Mailer.
     *
     * @input object subscriber
     * @input object sending server
     *
     * @return MIME text message
     */
    public function prepareEmail_orig($subscriber, $server)
    {
        MailLog::info("prepareEmail function called internal");


        // build the message
        $customHeaders = $this->getCustomHeaders($subscriber, $this);
        $msgId = $customHeaders['X-My-Message-Id'];
        $message = \Swift_Message::newInstance();
        $message->setId($msgId);

        if ($this->type == self::TYPE_REGULAR) {
            $message->setContentType('text/html; charset=utf-8');
        } else {
            $message->setContentType('text/plain; charset=utf-8');
        }

        foreach($customHeaders as $key => $value) {
// headeriu pridejimas
            $message->getHeaders()->addTextHeader($key, $value);
        }

        // @TODO for AWS, setting returnPath requires verified domain or email address
        if ($server->allowCustomReturnPath()) {
            $returnPath = $server->getVerp($subscriber->email);
            if ($returnPath) {
                $message->setReturnPath($returnPath);
            }
        }
        $message->setSubject($this->getSubject($subscriber, $msgId));
        $message->setFrom(array($this->from_email => $this->from_name));
        $message->setTo($subscriber->email);
        $message->setReplyTo($this->reply_to);
        $message->setEncoder(\Swift_Encoding::get8bitEncoding());
        $message->addPart($this->getPlainContent($subscriber, $msgId, $server), 'text/plain');
        if ($this->type == self::TYPE_REGULAR) {
            $message->addPart($this->getHtmlContent($subscriber, $msgId, $server), 'text/html');
        }

        if ($this->sign_dkim) {
            $message = $this->sign($message);
        }

        // @todo attachment
        //$message->attach(Swift_Attachment::fromPath('/tmp/gaugau.csv'));
        return array($message, $msgId);
    }


// Function mod
    public function prepareEmail($subscriber, $server)
    {
        MailLog::info("prepareEmail function called internal");


        // build the message
        $customHeaders = $this->getCustomHeaders($subscriber, $this);
        $msgId = $customHeaders['X-My-Message-Id'];

        $data['custom_headers'] = $customHeaders;

        // padarom normalu outputa
      /*  $data['from'] = $this->from_email;
        $data['subject'] = $this->getSubject($subscriber, $msgId);
        $data['to'] = $subscriber->email;
        $data['reply'] = $this->reply_to;
*/

        $message = \Swift_Message::newInstance();
        $message->setId($msgId);

        if ($this->type == self::TYPE_REGULAR) {
            $message->setContentType('text/html; charset=utf-8');
            $data['content_type'] = 'text/html; charset=utf-8';
        } else {
            $message->setContentType('text/plain; charset=utf-8');
            $data['content_type'] = 'text/plain; charset=utf-8';
        }



        foreach($customHeaders as $key => $value) {
// headeriu pridejimas
            $message->getHeaders()->addTextHeader($key, $value);
        }

        // @TODO for AWS, setting returnPath requires verified domain or email address
        if ($server->allowCustomReturnPath()) {
            $returnPath = $server->getVerp($subscriber->email);
            if ($returnPath) {
                $message->setReturnPath($returnPath);
            }
        }
        $message->setSubject($this->getSubject($subscriber, $msgId));
        $message->setFrom(array($this->from_email => $this->from_name));
        $message->setTo($subscriber->email);
        $message->setReplyTo($this->reply_to);
        $message->setEncoder(\Swift_Encoding::get8bitEncoding());
        $message->addPart($this->getPlainContent($subscriber, $msgId, $server), 'text/plain');
        if ($this->type == self::TYPE_REGULAR) {
            $message->addPart($this->getHtmlContent($subscriber, $msgId, $server), 'text/html');
        }

        if ($this->sign_dkim) {
            $message = $this->sign($message);
        }

        // @todo attachment
        //$message->attach(Swift_Attachment::fromPath('/tmp/gaugau.csv'));
        return array($message, $msgId);
    }

    /**
     * Reset max_execution_time so that command can run for a long time without being terminated
     *
     * @return mixed
     */
    public static function resetMaxExecutionTime() {
        try {
            set_time_limit(0);
            ini_set('max_execution_time', 0);
            ini_set('memory_limit', '-1');
        } catch (\Exception $e) {
            // hack MailLog::warning('Cannot reset max_execution_time: '.$e->getMessage());
        }
    }

    /**
     * Mark the campaign as 'done' or 'sent'.
     */
    public function done()
    {
        $this->status = self::STATUS_DONE;
        $this->save();
        $camp = json_decode(Redis::get($this->uid));
        if (!$camp) $camp = (object)[];
        $camp->status = self::STATUS_DONE;
        Redis::set($this->uid,json_encode($camp));
    }

    /**
     * Mark the campaign as 'sending'.
     */
    public function sending()
    {
        $this->status = self::STATUS_SENDING;
        $this->delivery_at = \Carbon\Carbon::now();
        $this->save();
        $camp = json_decode(Redis::get($this->uid));
        if (!$camp) $camp = (object)[];
        $camp->status = self::STATUS_SENDING;
        Redis::set($this->uid,json_encode($camp));
    }

    public function preparing()
    {
        $this->status = self::STATUS_PREPARING;
        $this->save();
        $camp = json_decode(Redis::get($this->uid));
        if (!$camp) $camp = (object)[];
        $camp->status = self::STATUS_PREPARING;
        Redis::set($this->uid,json_encode($camp));
    }

    /**
     * Check if the campaign is in the "SENDING" status;
     */
    public function isSending()
    {
        return $this->status == self::STATUS_SENDING;
    }

    /**
     * Check if the campaign is in the "DONE" status;
     */
    public function isDone()
    {
        return $this->status == self::STATUS_DONE;
    }

    public function isError()
    {
        return $this->status == self::STATUS_ERROR;
    }

    /**
     * Check if the campaign is ready to start
     */
    public function isReady()
    {
        return $this->status == self::STATUS_READY;
    }

    public function isScheduled()
    {
        return $this->status == self::STATUS_SCHEDULED;
    }

    public function isNew()
    {
        return $this->status == self::STATUS_NEW;
    }

    public function scheduled() {
        $this->status = self::STATUS_SCHEDULED;
        $this->save();
    }

    /**
     * Mark the campaign as 'ready' (which is equiv. to 'queued')
     */
    public function ready()
    {
        $this->status = self::STATUS_READY;
        $this->save();
        $camp = json_decode(Redis::get($this->uid));
        if (!$camp) $camp = (object)[];
        $camp->status = self::STATUS_READY;
        Redis::set($this->uid,json_encode($camp));
    }

    /**
     * Mark the campaign as 'done' or 'sent'.
     */
    public function error($error = null)
    {
        $this->status = self::STATUS_ERROR;
        $this->last_error = $error;
        $this->save();
        $camp = json_decode(Redis::get($this->uid));
        if (!$camp) $camp = (object)[];
        $camp->status = self::STATUS_ERROR;
        $camp->last_error = $error;
        Redis::set($this->uid,json_encode($camp));
    }

    /**
     * Mark the campaign as 'done' or 'sent'.
     */
    public function refreshStatus()
    {
      //  MailLog::info("refresh status");
        $me = self::find($this->id);
        $this->status = $me->status;
        $this->save();
        $camp = json_decode(Redis::get($this->uid));
        if (!$camp) $camp = (object)[];
        $camp->status = $me->status;
        Redis::set($this->uid,json_encode($camp));
        return $this;
    }

    /**
     * Log delivery message, used for later tracking.
     *
     */
    public function trackMessage($response, $subscriber, $server, $msgId)
    {
        // @todo: customerneedcheck
        $params = array_merge(array(
                'campaign_id' => $this->id,
                'message_id' => $msgId,
                'subscriber_id' => $subscriber->id,
                'sending_server_id' => $server->id,
                'customer_id' => $this->customer->id,
            ), $response);

        if (!isset($params['runtime_message_id'])) {
            $params['runtime_message_id'] = $msgId;
        }

        // create tracking log for message
        TrackingLog::create($params);

        // increment customer quota usage
   //     $this->customer->countUsage();
   //     $server->countUsage();
    }

    /**
     * Get tagged Subject
     *
     * @return String
     */
    public function getSubject($subscriber, $msgId) {
        return $this->tagMessage($this->subject, $subscriber, $msgId, null);
    }

    /**
     * Append Open Tracking URL
     * Append open-tracking URL to every email message.
     */
    public function appendOpenTrackingUrl($body, $msgId)
    {
        $openTrackingBaseURL = Setting::get('url_open_track');
        $tracking_url = str_replace('MESSAGE_ID', StringHelper::base64UrlEncode($msgId), $openTrackingBaseURL);

        return $body.'<img src="'.$tracking_url.'" width="0" height="0" alt="" style="visibility:hidden" />';
    }

    /**
     * Build Email Custom Headers
     *
     * @return Hash list of custom headers
     */
    public function getCustomHeaders($subscriber, $server) {
        $msgId = StringHelper::generateMessageId(StringHelper::getDomainFromEmail($this->from_email));

        return array(
            'X-Acelle-Campaign-Id' => $this->uid,
            'X-Acelle-Subscriber-Id' => $subscriber->uid,
            'X-Acelle-Customer-Id' => $this->customer->uid,
            'X-My-Message-Id' => $msgId,
            'X-Acelle-Sending-Server-Id' => $server->uid,
            'List-Unsubscribe' => '<'.str_replace('MESSAGE_ID', StringHelper::base64UrlEncode($msgId), Setting::get('url_unsubscribe')).'>',
            'Precedence' => 'bulk'
        );
    }

    /**
     * Build Email HTML content
     *
     * @return String
     */
    public function getHtmlContent($subscriber, $msgId, $server = null) {
        // @note: IMPORTANT: the order must be as follows
        // * addTrackingURL
        // * appendOpenTrackingUrl
        // * tagMessage

        // @note: addTrackingUrl() must go before appendOpenTrackingUrl()
        $body = $this->html;

        // Enable click tracking
      /*  if ($this->track_click) {
            $body = $this->addTrackingUrl($body, $msgId);
        }

        // Enable open tracking
        if ($this->track_open) {
            $body = $this->appendOpenTrackingUrl($body, $msgId);
        }
*/
        // Transform tags

      // $body = $this->tagMessage($body, $subscriber, $msgId, $server);

        // Transform CSS/HTML content to inline CSS
        // sudeda css i pati html
        $body = $this->inlineHtml($body);

        return $body;
    }

    /**
     * Build Email HTML content
     *
     * @return String
     */
    public function getPlainContent($subscriber, $msgId, $server = null) {
        $plain = $this->tagMessage($this->plain, $subscriber, $msgId, $server);
        return $plain;
    }

    /**
     * Find sending domain from email.
     *
     * @return mixed
     */
    public function findSendingDomain($email)
    {
        $domain = substr(strrchr($email, '@'), 1);

        return SendingDomain::where('name', $domain)->first();
    }

    /**
     * Sign the message with DKIM.
     *
     * @return mixed
     */
    public function sign($message)
    {
        $sendingDomain = $this->findSendingDomain($this->from_email);

        if (empty($sendingDomain)) {
            return $message;
        }

        $privateKey = $sendingDomain->dkim_private;
        $domainName = $sendingDomain->name;
        $selector = self::DKIM_SELECTOR;
        $signer = new \Swift_Signers_DKIMSigner($privateKey, $domainName, $selector);
        $signer->ignoreHeader('Return-Path');
        $message->attachSigner($signer);

        return $message;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'subject', 'from_name', 'from_email', 'trackurl','tracktype', 'auto_pause',
        'reply_to', 'track_open',
        'track_click', 'sign_dkim', 'track_fbl',
        'html', 'plain', 'template_source',
    ];

    /**
     * The rules for validation.
     *
     * @var array
     */
    public static $rules = array(
        'mail_list_uid' => 'required',
    );

    /**
     * Items per page.
     *
     * @var array
     */
    public static $itemsPerPage = 25;

    /**
     * Get all items.
     *
     * @return collect
     */
    public static function getAll()
    {
        // FIXME OPTIMIZE DELETE BY LIMIT
        return self::select('campaigns.*')->whereNull('del_date');
    }

    /**
     * Get select options.
     *
     * @return array
     */
    public static function getSelectOptions($customer = null, $status = null)
    {
        $query = self::getAll();
        if (is_object($customer)) {
            $query = $query->where('customer_id', '=', $customer->id);
        }
        if (isset($status)) {
            $query = $query->where('status', '=', $status);
        }
        $options = $query->orderBy('created_at', 'DESC')->get()->map(function ($item) {
            return ['value' => $item->uid, 'text' => $item->name];
        });

        return $options;
    }

    /**
     * Bootstrap any application services.
     */
    public static function boot()
    {
        parent::boot();

        // Create uid when creating list.
        static::creating(function ($item) {
            // Create new uid
            $uid = uniqid();
            while (Campaign::where('uid', '=', $uid)->count() > 0) {
                $uid = uniqid();
            }
            $item->uid = $uid;

            // Update custom order
            Campaign::getAll()->increment('custom_order', 1);
            $item->custom_order = 0;
        });

        // Created
        static::created(function ($item) {
            // Update links
            $item->updateLinks();
        });

        static::updating(function ($item) {
            // Update links
            $item->updateLinks();
        });
    }

    /**
     * Get current links of campaign.
     */
    public function getLinks()
    {
        return $this->links()->whereIn('url', $this->getUrls())->get();
    }

    /**
     * Get urls from campaign html.
     */
    public function getUrls()
    {
        // Find all links in campaign content
        preg_match_all('/<a[^>]*href=["\'](?<url>http[^"\']*)["\']/i', $this->html, $matches);
        $hrefs = array_unique($matches['url']);

        $urls = [];
        foreach ($hrefs as $href) {
            if (preg_match('/^http/i', $href) && strpos($href, '{UNSUBSCRIBE_URL}') === false) {
                $urls[] = trim($href);
            }
        }

        return $urls;
    }

    /**
     * Update campaign links.
     */
    public function updateLinks()
    {
        foreach ($this->getUrls() as $url) {
            $link = Link::where('url', '=', $url)->first();
            if (!is_object($link)) {
                $link = new Link();
                $link->url = $url;
                $link->save();
            }

            // Campaign link
            if ($this->links()->where('url', '=', $url)->count() == 0) {
                $cl = new CampaignLink();
                $cl->campaign_id = $this->id;
                $cl->link_id = $link->id;
                $cl->save();
            }
            $all_links = Link::AllLinks()->get();
            foreach ($all_links as $lnk) {
                 \Redis::hset("campaigns_links",$lnk->id,$lnk->url);
            }
        }
    }

    /**
     * Find item by uid.
     *
     * @return object
     */
    public static function findByUid($uid)
    {
        return self::where('uid', '=', $uid)->first();
    }

    /**
     * CHeck UNSUBSCRIBE_URL.
     *
     * @return object
     */
    public function unsubscribe_url_valid()
    {
return true;
/* TESTAS       if($this->type != 'plain-text' &&
           \Auth::user()->customer->getOption('unsubscribe_url_required') == 'yes' &&
            strpos($this->html, '{UNSUBSCRIBE_URL}') == false
        ) {
            return false;
        } else {
            return true;
        } */
    }

    /**
     * Get max step.
     *
     * @return object
     */
    public function step()
    {
        $step = 0;

        // Step 1
        if (is_object($this->defaultMailList)) {
            $step = 1;
        } else {
            return $step;
        }

        // Step 2
        if (!empty($this->name) && !empty($this->subject) && !empty($this->from_name)
                && !empty($this->from_email) && !empty($this->reply_to)) {
            $step = 2;
        } else {
            return $step;
        }

        // Step 3
        if ((!empty($this->html) || $this->type == 'plain-text') && !empty($this->plain) && $this->unsubscribe_url_valid()) {
            $step = 3;
        } else {
            return $step;
        }

        // Step 4
        if (isset($this->run_at) && $this->run_at != '0000-00-00 00:00:00') {
            $step = 4;
        } else {
            return $step;
        }

        // Step 5
        // @todo: consider removing this check!
        if ( is_object($this->subscribers([], [])->limit(1)->first()) ) {
            $step = 5;
        } else {
            return $step;
        }

        return $step;
    }

    /**
     * Filter items.
     *
     * @return collect
     */
    public static function filter($request)
    {
        $customer = $request->user()->customer;
        $query = self::where('campaigns.customer_id', '=', $customer->id);


        // Get campaign from ... (all|normal|automated)
        if($request->source == 'template') {
            $query = $query->where('html', '!=', null);
        } else {
            $query = $query->where('is_auto', '=', false);
        }

        // Keyword
        if (!empty(trim($request->keyword))) {
            $query = $query->where('name', 'like', '%'.$request->keyword.'%');
        }

        if (!empty(trim($request->country)) && $request->country > 0) {
            $query = $query->leftJoin('campaigns_lists_segments', 'campaigns_lists_segments.campaign_id', '=', 'campaigns.id')
                ->select(['campaigns_lists_segments.created_at as creatas','contacts.created_at as creatas2',
                'campaigns.id as id',
                'campaigns.customer_id as customer_id',
                'campaigns.uid as uid',
                'campaigns.type as type',
                'campaigns.name as name',
                'campaigns.from_email as from_email',
                'campaigns.is_auto as is_auto',
                'campaigns.html as html',
                'campaigns.plain as plain',
                'campaigns.subject as subject',
                'campaigns.from_name as from_name',
                'campaigns.reply_to as reply_to',
                'campaigns.status as status',
                'campaigns.run_at as run_at',
                'campaigns.delivery_at as delivery_at',
                'campaigns.created_at as created_at',
                'campaigns.updated_at as updated_at',
                'campaigns.default_mail_list_id as default_mail_list_id',
                'campaigns.cache as cache',
                'campaigns.del_date as del_date',
                'mail_lists.del_date as del_date2',
                'mail_lists.custom_order as custom_order',
                'contacts.country_id as country_id' ])
          ->join('mail_lists','campaigns_lists_segments.mail_list_id','=','mail_lists.id')
          ->join('contacts','mail_lists.contact_id', '=','contacts.id');
            $query = $query->where('country_id', '=', $request->country);
        }

        if (isset($request->type) && $request->type == "archive") {
         $query = $query->whereNotNull('campaigns.archived');
         $query = $query->whereNull('campaigns.del_date');

        } else {
            $query = $query->whereNull('campaigns.del_date');
            $query = $query->whereNull('campaigns.archived');
        }
        // Status
        if (!empty(trim($request->statuses))) {
            $query = $query->whereIn('status', explode(",", $request->statuses));
        }

        return $query;
    }

    /**
     * Search items.
     *
     * @return collect
     */
    public static function search($request)
    {
        $query = self::filter($request);

        $query = $query->orderBy($request->sort_order, $request->sort_direction);

        return $query;
    }

    /**
     * Create customer action log.
     *
     * @param string $cat
     * @param Customer   $customer
     * @param array  $add_datas
     */
    public function log($name, $customer, $add_datas = [])
    {
        $data = [
                'id' => $this->id,
                'name' => $this->name,
        ];

        if (is_object($this->defaultMailList)) {
            $data['list_id'] = $this->default_mail_list_id;
            $data['list_name'] = $this->defaultMailList->name;
        }

        if (is_object($this->segment)) {
            $data['segment_id'] = $this->segment_id;
            $data['segment_name'] = $this->segment->name;
        }

        $data = array_merge($data, $add_datas);

        \Acelle\Model\Log::create([
                                'customer_id' => $customer->id,
                                'type' => 'campaign',
                                'name' => $name,
                                'data' => json_encode($data),
                            ]);
    }

    /**
     * Count delivery processed.
     *
     * @return number
     */
    public function trackingCount()
    {
        return $this->trackingLogs()->count();
    }

    /**
     * Count delivery processed.
     *
     * @return number
     */
    public function deliveredCount()
    {
        return $this->trackingLogs()->where('status', '=', 'sent')->count();
    }

    /**
     * Count failed processed.
     *
     * @return number
     */
    public function failedCount()
    {
        return $this->trackingLogs()->where('status', '=', 'failed')->count();
    }

    /**
     * Count delivery success rate.
     *
     * @return number
     */
    public function deliveredRate($cache = false)
    {
        $total = $this->subscribersCount($cache);

        if ($total == 0) {
            return 0;
        }
        $delivered = 0;
        if (Redis::exists($this->uid.'_counter')) $delivered = Redis::get($this->uid.'_counter');
        else
            $delivered = $this->deliveredCount();

        //return $this->deliveredCount() / $total;
        //MailLog::info("KONFIGAS: ".config('app.shared'));
        return $delivered / $total;
    }

    /**
     * Count click.
     *
     * @return number
     */
    public function clickCount($start = null, $end = null)
    {
        $query = $this->clickLogs();

        if (isset($start)) {
            $query = $query->where('click_logs.created_at', '>=', $start);
        }
        if (isset($end)) {
            $query = $query->where('click_logs.created_at', '<=', $end);
        }

        return $query->count();
    }

    /**
     * Url count.
     *
     * @return number
     */
    public function urlCount()
    {
        return $this->links()->count();
    }

    /**
     * Click rate.
     *
     * @return number
     */
    public function clickedLinkCount()
    {
        return $this->clickLogs()->distinct('url')->count('url');
    }

    /**
     * Click rate.
     *
     * @return number
     */
    public function clickRate()
    {
        $url_count = $this->urlCount();

        if ($url_count == 0) {
            return 0;
        }

        return round(($this->clickedLinkCount() / $url_count) * 100, 0);
    }




    /**
     * Count unique clicked opened emails.
     *
     * @return number
     */
    public function clickedEmailsCount()
    {
        $query = $this->clickLogs();

        return $query->distinct('subscriber_id')->count('subscriber_id');
    }

    /**
     * Click a link rate.
     *
     * @return number
     */
    public function clickALinkRate()
    {

        $delivered_count = $this->deliveredCount();

        if ($delivered_count == 0) {
            return 0;
        }

/*

        $open_count = $this->openCount();

        if ($open_count == 0) {
            return 0;
        }

        return round(($this->clickCount() / $open_count) * 100, 0);
*/
        return round($this->clickCount() / $delivered_count * 100, 0);
    }

    /**
     * Clicked emails count.
     *
     * @return number
     */
    public function clickedEmailsRate()
    {
        $delivered_count = $this->deliveredCount();

        if ($delivered_count == 0) {
            return 0;
        }



       /* $open_count = $this->openUniqCount();

        if ($open_count == 0) {
            return 0;
        }
       */

        //return round(($this->clickedEmailsCount() / $open_count) * 100, 2);
        return round(($this->clickedEmailsCount() / $delivered_count) * 100, 2);
    }

    /**
     * Count click.
     *
     * @return number
     */
    public function clickPerUniqOpen()
    {
        $open_count = $this->openCount();

        if ($open_count == 0) {
            return 0;
        }

        return round(($this->clickCount() / $open_count) * 100, 0);
    }

    /**
     * Count abuse feedback.
     *
     * @return number
     */
    public function abuseFeedbackCount()
    {
        return $this->feedbackLogs()->where('feedback_type', '=', 'abuse')->count();
    }

    /**
     * Count open.
     *
     * @return number
     */
    public function openCount()
    {
        return $this->openLogs()->count();
    }

    /**
     * Not open count.
     *
     * @return number
     */
    public function notOpenCount($cache = false)
    {
        return $this->subscribersCount($cache) - $this->openUniqCount();
    }

    /**
     * Count unique open.
     *
     * @return number
     */
    public function openUniqCount($start = null, $end = null)
    {
        $query = $this->openLogs();
        if (isset($start)) {
            $query = $query->where('open_logs.created_at', '>=', $start);
        }
        if (isset($end)) {
            $query = $query->where('open_logs.created_at', '<=', $end);
        }

        return $query->distinct('subscriber_id')->count('subscriber_id');
    }



    /**
     * Open rate.
     *
     * @return number
     */
    public function openRate()
    {
        $delivered_count = $this->deliveredCount();

        if ($delivered_count == 0) {
            return 0;
        }

        return round(($this->openCount() / $delivered_count) * 100, 0);
    }

    /**
     * Not open rate.
     *
     * @return number
     */
    public function notOpenRate($cache = false)
    {
        $total = $this->subscribersCount($cache);

        if ($total == 0) {
            return 0;
        }

        return $this->notOpenCount($cache) / $total;
    }

    /**
     * Count unique open rate.
     *
     * @return number
     */
    public function openUniqRate()
    {
        $delivered_count = $this->deliveredCount();

        if ($delivered_count == 0) {
            return 0;
        }

        return round(($this->openUniqCount() / $delivered_count) * 100, 2);
    }

    /**
     * Count bounce back.
     *
     * @return number
     */
    public function feedbackCount()
    {
        return $this->feedbackLogs()->distinct('subscriber_id')->count('subscriber_id');
    }

    /**
     * Count feedback rate.
     *
     * @return number
     */
    public function feedbackRate()
    {
        $delivered_count = $this->deliveredCount();

        if ($delivered_count == 0) {
            return 0;
        }

        return round(($this->feedbackCount() / $delivered_count) * 100, 0);
    }

    /**
     * Count bounce back.
     *
     * @return number
     */
    public function bounceCount()
    {
        return $this->bounceLogs()->distinct('subscriber_id')->count('subscriber_id');
    }

    /**
     * Count bounce rate.
     *
     * @return number
     */
    public function bounceRate()
    {
        $delivered_count = $this->deliveredCount();

        if ($delivered_count == 0) {
            return 0;
        }

        return round(($this->bounceCount() / $delivered_count) * 100, 0);
    }

    /**
     * Count hard bounce.
     *
     * @return number
     */
    public function hardBounceCount()
    {
        return $this->campaign_bounce_logs()->where('bounce_type', '=', 'hard')->distinct('subscriber_id')->count('subscriber_id');
    }

    /**
     * Count hard bounce rate.
     *
     * @return number
     */
    public function hardBounceRate()
    {
        $delivered_processed_count = $this->deliveryProcessedCount();

        if ($delivered_processed_count == 0) {
            return 0;
        }

        return round(($this->hardBounceCount() / $delivered_processed_count) * 100, 0);
    }

    /**
     * Count soft bounce.
     *
     * @return number
     */
    public function softBounceCount()
    {
        return $this->campaign_bounce_logs()->where('bounce_type', '=', 'soft')->distinct('subscriber_id')->count('subscriber_id');
    }

    /**
     * Count soft bounce rate.
     *
     * @return number
     */
    public function softBounceRate()
    {
        $tracking_count = $this->trackingCount();

        if ($tracking_count == 0) {
            return 0;
        }

        return round(($this->softBounceCount() / $tracking_count) * 100, 0);
    }

    /**
     * Count unsubscibe.
     *
     * @return number
     */
    public function unsubscribeCount()
    {
        return $this->unsubscribeLogs()->distinct('subscriber_id')->count('subscriber_id');
    }

    /**
     * Count unsubscibe rate.
     *
     * @return number
     */
    public function unsubscribeRate()
    {
        $delivered_count = $this->deliveredCount();

        if ($delivered_count == 0) {
            return 0;
        }

        return round(($this->unsubscribeCount() / $delivered_count) * 100, 0);
    }

    /**
     * Get last click.
     *
     * @param number $number
     *
     * @return collect
     */
    public function lastClick()
    {
        return $this->clickLogs()->orderBy('created_at', 'desc')->first();
    }

    /**
     * Get last open.
     *
     * @param number $number
     *
     * @return collect
     */
    public function lastOpen()
    {
        return $this->openLogs()->orderBy('created_at', 'desc')->first();
    }

    /**
     * Get last open list.
     *
     * @param number $number
     *
     * @return collect
     */
    public function lastOpens($number)
    {
        return $this->openLogs()->orderBy('created_at', 'desc')->limit($number);
    }

    /**
     * Get most open subscribers.
     *
     * @param number $number
     *
     * @return collect
     */
    public function mostOpenSubscribers($number)
    {
        return \Acelle\Web\Subscriber::selectRaw(\DB::getTablePrefix().'list_subscriber.*, COUNT('.\DB::getTablePrefix().'campaign_track_unsubscribe.id) AS openCount')
                            ->leftJoin('campaign_track_unsubscribe', 'campaign_track_unsubscribe.subscriber_id', '=', 'list_subscriber.subscriber_id')
                            ->where('campaign_track_unsubscribe.campaign_id', '=', $this->campaign_id)
                            ->groupBy('list_subscriber.subscriber_id')
                            ->orderBy('openCount', 'desc')
                            ->limit($number);
    }

    /**
     * Get last opened time.
     *
     * @return datetime
     */
    public function getLastOpen()
    {
        $last = $this->campaign_track_opens()->orderBy('created_at', 'desc')->first();

        return is_object($last) ? $last->created_at : null;
    }

    /**
     * Campaign top 5 opens.
     *
     * @return datetime
     */
    public static function topOpens($number = 5, $customer = null)
    {
        $records = self::select(\DB::raw(\DB::getTablePrefix().'campaigns.*, count(*) as `aggregate`'))
            ->join('tracking_logs', 'tracking_logs.campaign_id', '=', 'campaigns.id')
            ->join('open_logs', 'open_logs.message_id', '=', 'tracking_logs.message_id');

        if (isset($customer)) {
            $records = $records->where('campaigns.customer_id', '=', $customer->id);
        }

        $records = $records->groupBy('campaigns.id')
            ->orderBy('aggregate', 'desc');

        return $records->take($number);
    }

    /**
     * Campaign top 5 clicks.
     *
     * @return datetime
     */
    public static function topClicks($number = 5, $customer = null)
    {
        $records = self::select(\DB::raw(\DB::getTablePrefix().'campaigns.*, count(*) as `aggregate`'))
            ->join('tracking_logs', 'tracking_logs.campaign_id', '=', 'campaigns.id')
            ->join('click_logs', 'click_logs.message_id', '=', 'tracking_logs.message_id');

        if (isset($customer)) {
            $records = $records->where('campaigns.customer_id', '=', $customer->id);
        }

        $records = $records->groupBy('campaigns.id')
                    ->orderBy('aggregate', 'desc');

        return $records->take($number);
    }

    /**
     * Campaign top 5 clicks.
     *
     * @return datetime
     */
    public static function topLinks($number = 5, $customer = null)
    {
        $records = Link::select(\DB::raw(\DB::getTablePrefix().'links.*, count(*) as `aggregate`'))
            ->join('campaign_links', 'campaign_links.link_id', '=', 'links.id')
            ->join('tracking_logs', 'tracking_logs.campaign_id', '=', 'campaign_links.campaign_id')
            ->join('click_logs', function ($join) {
                $join->on('click_logs.message_id', '=', 'tracking_logs.message_id')
                ->on('click_logs.url', '=', 'links.url');
            });

        if (isset($customer)) {
            $records = $records->join('campaigns', 'campaign_links.campaign_id', '=', 'campaigns.id')
                ->where('campaigns.customer_id', '=', $customer->id);
        }

        $records = $records->groupBy('links.id')
            ->orderBy('aggregate', 'desc');

        return $records->take($number);
    }

    /**
     * Campaign top 5 clicks.
     *
     * @return datetime
     */
    public function getTopLinks($number = 5)
    {
        $records = ClickLog::select(\DB::raw(\DB::getTablePrefix().'click_logs.*, count(*) as `aggregate`'))
            ->leftJoin('tracking_logs', 'tracking_logs.message_id', '=', 'click_logs.message_id')
            ->where('tracking_logs.campaign_id', '=', $this->id);

        $records = $records->groupBy('click_logs.url')
            ->orderBy('aggregate', 'desc');

        return $records->take($number);
    }

    /**
     * Campaign top 5 clicks.
     *
     * @return datetime
     */
    public function getTopOpenSubscribers($number = 5)
    {
        $records = Subscriber::select(\DB::raw(\DB::getTablePrefix().'subscribers.*, count(*) as `aggregate`'))
            ->join('tracking_logs', 'tracking_logs.subscriber_id', '=', 'subscribers.id')
            ->join('open_logs', 'open_logs.message_id', '=', 'tracking_logs.message_id')
            ->where('campaign_id', '=', $this->id);

        $records = $records->groupBy('tracking_logs.message_id')
            ->orderBy('aggregate', 'desc');

        return $records->take($number);
    }

    /**
     * Recent subscriber opens.
     *
     * @return datetime
     */
    public function getRecentOpenSubscribers($number = 5)
    {
        $records = Subscriber::select(\DB::raw(\DB::getTablePrefix().'subscribers.*, count(*) as `aggregate`'))
            ->join('tracking_logs', 'tracking_logs.subscriber_id', '=', 'subscribers.id')
            ->join('open_logs', 'open_logs.message_id', '=', 'tracking_logs.message_id')
            ->where('campaign_id', '=', $this->id);

        $records = $records->groupBy('tracking_logs.message_id')
            ->orderBy('aggregate', 'desc');

        return $records->take($number);
    }

    /**
     * Campaign top 5 open location.
     *
     * @return datetime
     */
    public function topLocations($number = 5, $customer = null)
    {
        $records = IpLocation::select(\DB::raw(\DB::getTablePrefix().'ip_locations.*, count(*) as `aggregate`'))
            ->join('open_logs', 'open_logs.ip_address', '=', 'ip_locations.ip_address')
            ->join('tracking_logs', 'open_logs.message_id', '=', 'tracking_logs.message_id')
            ->where('tracking_logs.campaign_id', '=', $this->id);

        if (isset($customer)) {
            $records = $records->join('campaigns', 'tracking_logs.campaign_id', '=', 'campaigns.id')
                ->where('campaigns.customer_id', '=', $customer->id);
        }

        $records = $records->groupBy('open_logs.ip_address')
            ->orderBy('aggregate', 'desc');

        return $records->take($number);
    }

    /**
     * Campaign top 5 open countries.
     *
     * @return datetime
     */
    public function topCountries($number = 5, $customer = null)
    {
        $records = IpLocation::select(\DB::raw(\DB::getTablePrefix().'ip_locations.*, count(*) as `aggregate`'))
            ->join('open_logs', 'open_logs.ip_address', '=', 'ip_locations.ip_address')
            ->join('tracking_logs', 'open_logs.message_id', '=', 'tracking_logs.message_id')
            ->where('tracking_logs.campaign_id', '=', $this->id);

        if (isset($customer)) {
            $records = $records->join('campaigns', 'tracking_logs.campaign_id', '=', 'campaigns.id')
                ->where('campaigns.customer_id', '=', $customer->id);
        }

        $records = $records->groupBy('ip_locations.country_name')
            ->orderBy('aggregate', 'desc');

        return $records->take($number);
    }


    public function topPlatforms($number = 5, $customer = null)
    {
        $records = OpenLog::select(\DB::raw(\DB::getTablePrefix().'open_logs.*, count(*) as `aggregate`'))
           // ->join('open_logs', 'open_logs.ip_address', '=', 'ip_locations.ip_address')
            ->join('tracking_logs', 'open_logs.message_id', '=', 'tracking_logs.message_id')
            ->where('tracking_logs.campaign_id', '=', $this->id);

        if (isset($customer)) {
            $records = $records->join('campaigns', 'tracking_logs.campaign_id', '=', 'campaigns.id')
                ->where('campaigns.customer_id', '=', $customer->id);
        }

        $records = $records->groupBy('open_logs.user_agent')
            ->orderBy('aggregate', 'desc');

        return $records->take($number);
    }


    /**
     * Campaign top 5 click countries.
     *
     * @return datetime
     */
    public function topClickCountries($number = 5, $customer = null)
    {
        $records = IpLocation::select(\DB::raw(\DB::getTablePrefix().'ip_locations.*, count(*) as `aggregate`'))
            ->join('click_logs', 'click_logs.ip_address', '=', 'ip_locations.ip_address')
            ->join('tracking_logs', 'click_logs.message_id', '=', 'tracking_logs.message_id')
            ->where('tracking_logs.campaign_id', '=', $this->id);

        if (isset($customer)) {
            $records = $records->join('campaigns', 'tracking_logs.campaign_id', '=', 'campaigns.id')
                ->where('campaigns.customer_id', '=', $customer->id);
        }

        $records = $records->groupBy('ip_locations.country_name')
            ->orderBy('aggregate', 'desc');

        return $records->take($number);
    }

    /**
     * Campaign locations.
     *
     * @return datetime
     */
    public function locations()
    {
        $records = IpLocation::select('ip_locations.*', 'open_logs.created_at as open_at', 'subscribers.email as email')
            ->leftJoin('open_logs', 'open_logs.ip_address', '=', 'ip_locations.ip_address')
            ->leftJoin('tracking_logs', 'open_logs.message_id', '=', 'tracking_logs.message_id')
            ->leftJoin('subscribers', 'subscribers.id', '=', 'tracking_logs.subscriber_id')
            ->where('tracking_logs.campaign_id', '=', $this->id);

        return $records;
    }

    /**
     * Replace link in text by click tracking url.
     *
     * @return text
     * @note addTrackingUrl() must go before appendOpenTrackingUrl()
     */
    public function addTrackingUrl($email_html_content, $msgId)
    {
        if (preg_match_all('/<a[^>]*href=["\'](?<url>http[^"\']*)["\']/i', $email_html_content, $matches)) {
            foreach ($matches[0] as $key => $href) {
                $url = $matches['url'][$key];

                $newUrl = str_replace('URL', StringHelper::base64UrlEncode($url), Setting::get('url_click_track'));
                $newUrl = str_replace('MESSAGE_ID', StringHelper::base64UrlEncode($msgId), $newUrl);
                $newHref = str_replace($url, $newUrl, $href);

                // if the link contains UNSUBSCRIBE URL tag
                if (strpos($href, '{UNSUBSCRIBE_URL}') !== false) {
                    // just do nothing
                } else if (preg_match("/{[A-Z0-9_]+}/", $href)) {
                    // just skip if the url contains a tag. For example: {UPDATE_PROFILE_URL}
                    // @todo: do we track these clicks?
                } else {
                    $email_html_content = str_replace($href, $newHref, $email_html_content);
                }
            }
        }
        return $email_html_content;
    }

    /**
     * Type of campaigns.
     *
     * @return object
     */
    public static function types()
    {
        return [
            'regular' => [
                'icon' => 'icon-magazine'
            ],
            'plain-text' => [
                'icon' => 'icon-file-text2'
            ],
        ];
    }

    /**
     * Copy new campaign.
     */
    public function copy($name)
    {
        $copy = $this->replicate(['cache', 'last_error', 'run_at']);
        $copy->name = $name;
        $copy->created_at = \Carbon\Carbon::now();
        $copy->updated_at = \Carbon\Carbon::now();
        $copy->status = self::STATUS_NEW;
        $copy->custom_order = 0;
        $copy->save();

        MailLog::info("Old campaign uid: ".$this->uid." new campaign uid: ".$copy->uid);

        if (Redis::Exists($this->uid."_headers")) {
            $val = Redis::Get($this->uid."_headers");
            Redis::Set($copy->uid."_headers",$val);
        }

        // Lists segments
        foreach ($this->listsSegments as $lists_segment) {
            $new_lists_segment = $lists_segment->replicate();
            $new_lists_segment->campaign_id = $copy->id;
            $new_lists_segment->save();
        }

        // refresh to update cache (otherwise, list-segment information will not be available yet)
        Campaign::find($copy->id)->updateCache();
    }

    /**
     * Convert html to inline.
     * @todo not very OOP here, consider moving this to a Helper instead
     *
     */
    public function inlineHtml($html)
    {
        // Convert to inline css if template source is builder
        if ($this->template_source == 'builder') {
            $cssToInlineStyles = new CssToInlineStyles();

            $css = file_get_contents(public_path("css/res_email.css"));

            // output
            $html = $cssToInlineStyles->convert(
                $html,
                $css
            );
        }

        return $html;
    }

    private function get_server_details($id) {
        return \DB::table('sending_servers')->where('id', $id)->first();
    }

    private function getSecKey($mail,$action) {
        $string = $mail . $action . config('app.key');

        return md5($string);
    }


    public function SimulationTest($email) {
        MailLog::info("Simulation request for campaign: ".$this->uid." using email: ".$email);
        exec("\$HOME/public_html/tools/campaign_simulation $this->uid $email > /dev/null 2>&1 &");
        return [
            "status" => "success",
            "message" => "The campaign simulation have been started!"
        ];
    }

    private function simulationstatusupdate($text) {
        try {
            $uid = $this->uid;
            if (!\Redis::exists($uid . '_simulation')) \Redis::set($uid . '_simulation', '');
            $old = \Redis::get($uid . '_simulation');
            $old = $old . $text . "\n";
            \Redis::set($uid . '_simulation',$old);
        } catch (\Exception $ex) {
            MailLog::error("Got problem in simulationstatusupdate function: ".$ex);
        }
    }

    public function CampaignSimulationSend($email) {
        MailLog::info("Testing simulation using $email and campaign: ".$this->uid);
        try {
            $use_sleep = 0;
            $sleep_timeout = 3000000; // 2 seconds are 2000000
            $debug = 1;
            MailLog::info("We are now sending mass email test to all servers!");
            // generate email
            $subscriber = $this->createStdClassSubscriber(['email' => $email, 'status' => 'subscribed']);
            if (!Redis::exists($this->uid)) Redis::set($this->uid,json_encode($this));
            MailLog::info("Starting test mail the campaign: ".$this->name);
            \DB::connection()->disableQueryLog();
            // we replace the pont "." with the server on the sending backend
            $message_id = StringHelper::generateMessageId("");
            $subscriber->open_tracking = $message_id ."{SRVBL}";
            $message_id = str_replace('{SRVBL}','.',$subscriber->open_tracking);
            $subscriber->message_id = $message_id;
            $subscriber->track_url = Setting::get('global_tracking');
            $subscriber->tracking_url = str_replace('{CAMPAIGN}', StringHelper::generateCampaignName(), Setting::get('url_open_track'));
            $subscriber->unsubscribe_url = str_replace(   '{CAMPAIGN}', StringHelper::generateUnsubscribeUrl(), str_replace('MESSAGE_ID', StringHelper::base64UrlEncode2($message_id), Setting::get('url_unsubscribe')));
            $subscriber->update_url = str_replace( '{RANDOM}', StringHelper::generateCampaignName(), str_replace('LIST_UID', $this->defaultMailList->uid, str_replace('SUBSCRIBER_UID', 0, str_replace('SECURE_CODE', $this->getSecKey($subscriber->email, 'update-profile'), Setting::get('url_update_profile')))));
            $subscriber->msgid1 = StringHelper::base64UrlEncode($message_id);
            $subscriber->msgid2 = StringHelper::base64UrlEncode3($message_id);
            // clean redis test queue before sendout
            if (Redis::exists("campaign_".$this->uid."_subscribers_test")) Redis::del("campaign_".$this->uid."_subscribers_test");
            // detect all servers assigned to the campaign
            if (DB::table('campaigns')->select('all_sending_servers')
                    ->join('campaigns_lists_segments','campaigns.id','=','campaigns_lists_segments.campaign_id')
                    ->join('mail_lists','campaigns_lists_segments.mail_list_id','=','mail_lists.id')->where('mail_lists.id',$this->defaultMailList->id)->first()->all_sending_servers == 1) {
                $servers = \DB::table('sending_servers')->select('sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port','smtp_protocol','smtp_username','smtp_password')
                    ->where('status', 'active')->get();
                MailLog::info("all servers assigend");
            } else {
                $servers = \DB::table('campaigns')->select('sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port', 'fitness','smtp_protocol','smtp_username','smtp_password')
                    ->join('campaigns_lists_segments', 'campaigns.id', '=', 'campaigns_lists_segments.campaign_id')
                    ->join('mail_lists_sending_servers', 'campaigns_lists_segments.mail_list_id', '=', 'mail_lists_sending_servers.mail_list_id')
                    ->join('sending_servers', 'mail_lists_sending_servers.sending_server_id', '=', 'sending_servers.id')
                    ->where('campaigns.uid', $this->uid)->get();
                MailLog::info("servers assigned by hand");
            }
            MailLog::info("Servers detected: ".count($servers));
            $base_subject = $this->subject;
            // foreach cycle with all servers
            foreach ($servers as $serv) {
                $ssl = "";
                if ($serv->smtp_protocol != "") $ssl = "--ssl";
                if ($this->defaultMailList->all_sending_servers == 1) $serv->fitness = $this->defaultMailList->speed ? $serv->fitness = $this->defaultMailList->speed : \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
                if (!isset($serv->fitness)) $serv->fitness = \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
                // add subscriber to the redis queue
                $this->subject = $base_subject . " -> " . $serv->host;
                Redis::set($this->uid, json_encode($this));
                Redis::sAdd("campaign_" . $this->uid . "_subscribers_test", \json_encode($subscriber));

                // maybe we need to wait some time to sync the processes
                $randid = rand(33334234, 2343245890435345);
                switch ($serv->type) {

                    case "smtp":
                        if ($debug > 0) MailLog::info("./gosender --send --campuid $this->uid --smtphost $serv->host --smtpport $serv->smtp_port --smtpspeed 8000 --username $serv->smtp_username --password $serv->smtp_password $ssl --app $randid --test --nodone");
                        MailLog::info("Trying simulate email sendout to server: " . $serv->host . ":" . $serv->smtp_port);
                        $this->simulationstatusupdate("Testing server: $serv->host send address: $this->from_email trackurl: $this->trackurl email: $email");
                        exec("\$HOME/gosender --send --campuid $this->uid --smtphost $serv->host --smtpport $serv->smtp_port --smtpspeed 2000000 --username $serv->smtp_username --password $serv->smtp_password $ssl --app $randid --test --nodone");
                        break;
                    case "amazon-api":
                        break;
                }
                if ($use_sleep > 0) usleep($sleep_timeout);
                MailLog::info("Gathering information from the server...");
                exec('$HOME/public_html/tools/check_server_deliverability_v2 --host ' . $serv->host . ' --user ' . $serv->smtp_username . ' --pass ' . $serv->smtp_password . ' --port 22 --uid ' . $randid . ' 2>&1', $retArr, $retVal);
                foreach ($retArr as $output) {
                    if (strpos($output, "JSON: ") > -1) {
                        $output = preg_replace('/JSON: /', '', $output);
                        MailLog::info("Deliverability script returned: $output");


                    }
                }

                try {
                    $json = @json_decode($output);
                    MailLog::info("OUT:" . print_r($json, true));
                    if (is_object($json) && $json->status == 1) {
                        $this->simulationstatusupdate("Delivered: " . $json->msg);
                    } else {
                        if (isset($json->msg)) {
                            $this->simulationstatusupdate("Failed: " . $json->msg);
                        } else {
                            $this->simulationstatusupdate("We did not received any output from postfix regarding $serv->host and $email, the server can be blocked via firewall");
                        }
                    }
                    return $output;
                } catch (\Exception $ex) {
                    MailLog::error("Error in AbsCampaign::CampaignSimulationSend when receiving delivery info via json: " . $ex);
                }
            }



            MailLog::info("All test sendouts for campaign: ".$this->uid." is finished now!");

            // send the result as ok to the frontend
            $result['status'] = 'sent';
            if(array_has($result, 'error')) {
                throw new \Exception($result['error']);
            }

            return [
                "status" => "success",
                "message" => trans('messages.campaign.test_sent')
            ];
        } catch (\Exception $e) {
            return [
                "status" => "error",
                "message" => $e->getMessage()
            ];
        }
    }

    private function check_if_sending($uid) {
        $exists= false;
        exec("ps -C gosender -o args| grep \"$uid\"|grep \"\--test\"", $pids);
        if (count($pids) > 0) {
            $exists = true;
        }
        return $exists;
    }

    public function sendTestEmailDomain($email) {
        try {
            $speed = 9000;

            if (!Redis::exists($this->uid)) Redis::set($this->uid, json_encode($this));
            //$this->sending();
            MailLog::info("Starting test mail the campaign: " . $this->name);
            // sudedam visa maillista i redis
            // HACK TO make more performance
            \DB::connection()->disableQueryLog();
            // we replace the pont "." with the server on the sending backend





            $campaign_backup = Redis::get($this->uid);
            $camp_json = json_decode($campaign_backup);
            //MailLog::info("campaign debug: ".print_r($camp_json,true));

            //MailLog::info("Servers detected: " . count($servers));
            $cloudflare = new CloudFlareHelper();
            $domains = $cloudflare->get_domains();
            $dom_count = 0;
            foreach ($domains as $dom) {
                $dom_count++;
              //  if ($dom_count > 5) break;
                MailLog::info("Got domain: " . $dom->name);
                // build a temporary subscriber oject used to pass through the sending methods
                $subscriber = $this->createStdClassSubscriber(['email' => $email, 'status' => 'subscribed']);
                $camp_json->trackurl = $dom->name;
                $camp_json->reply_to = explode("@", $camp_json->reply_to)[0]."@".$dom->name;
                $camp_json->from_email = explode("@", $camp_json->from_email)[0]."@".$dom->name;
                Redis::set($this->uid,json_encode($camp_json));
                //MailLog::info("Saving modified campaign json: ".print_r($camp_json,true));

                $message_id = StringHelper::generateMessageId("");
                $subscriber->open_tracking = $message_id . "{SRVBL}";
                $message_id = str_replace('{SRVBL}', '.', $subscriber->open_tracking);

                $subscriber->message_id = $message_id;
                //$subscriber->track_url = $dom->name;
                $subscriber->tracking_url = str_replace('{CAMPAIGN}', StringHelper::generateCampaignName(), Setting::get('url_open_track'));
                $subscriber->unsubscribe_url = str_replace('{CAMPAIGN}', StringHelper::generateUnsubscribeUrl(), str_replace('MESSAGE_ID', StringHelper::base64UrlEncode2($message_id), Setting::get('url_unsubscribe')));
                $subscriber->update_url = str_replace('{RANDOM}', StringHelper::generateCampaignName(), str_replace('LIST_UID', $this->defaultMailList->uid, str_replace('SUBSCRIBER_UID', 0, str_replace('SECURE_CODE', $this->getSecKey($subscriber->email, 'update-profile'), Setting::get('url_update_profile')))));
                $subscriber->msgid1 = StringHelper::base64UrlEncode($message_id);
                $subscriber->msgid2 = StringHelper::base64UrlEncode3($message_id);


                // sudedam i array subscriberi
                if (Redis::exists("campaign_" . $this->uid . "_subscribers_test")) Redis::del("campaign_" . $this->uid . "_subscribers_test");
                Redis::sAdd("campaign_" . $this->uid . "_subscribers_test", \json_encode($subscriber));

                // get all assigned servers
                if (DB::table('campaigns')->select('all_sending_servers')
                        ->join('campaigns_lists_segments', 'campaigns.id', '=', 'campaigns_lists_segments.campaign_id')
                        ->join('mail_lists', 'campaigns_lists_segments.mail_list_id', '=', 'mail_lists.id')->where('mail_lists.id', $this->defaultMailList->id)->first()->all_sending_servers == 1) {
                    $servers = \DB::table('sending_servers')->select('sending_servers.uid', 'sending_servers.type', 'host', 'aws_access_key_id', 'aws_secret_access_key', 'aws_region', 'api_key', 'smtp_port', 'smtp_protocol', 'smtp_username', 'smtp_password')
                        ->where('status', 'active')->get();
                    MailLog::info("all servers assigend");
                } else {
                    $servers = \DB::table('campaigns')->select('sending_servers.uid', 'sending_servers.type', 'host', 'aws_access_key_id', 'aws_secret_access_key', 'aws_region', 'api_key', 'smtp_port', 'fitness', 'smtp_protocol', 'smtp_username', 'smtp_password')
                        ->join('campaigns_lists_segments', 'campaigns.id', '=', 'campaigns_lists_segments.campaign_id')
                        ->join('mail_lists_sending_servers', 'campaigns_lists_segments.mail_list_id', '=', 'mail_lists_sending_servers.mail_list_id')
                        ->join('sending_servers', 'mail_lists_sending_servers.sending_server_id', '=', 'sending_servers.id')
                        ->where('campaigns.uid', $this->uid)->get();
                    MailLog::info("servers assigned by hand");
                }

            foreach ($servers as $serv) {
                $ssl = "";
                if ($serv->smtp_protocol != "") $ssl = "--ssl";
                if ($this->defaultMailList->all_sending_servers == 1) $serv->fitness = $this->defaultMailList->speed ? $serv->fitness = $this->defaultMailList->speed : \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
                if (!isset($serv->fitness)) $serv->fitness = \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
                switch ($serv->type) {
                    case "smtp":
                        MailLog::info("./gosender --send --campuid $this->uid --smtphost $serv->host --smtpport $serv->smtp_port --smtpspeed $speed --username $serv->smtp_username --password $serv->smtp_password $ssl --test --nodone");
                        $descriptors = array(   0 => array('pipe', 'r'), // stdin
                            1 => array('pipe', 'w'), // stdout
                            2 => array('pipe', 'a')  // stderr
                        );
                        $procas=proc_open("\$HOME/gosender --send --campuid $this->uid --smtphost $serv->host --smtpport $serv->smtp_port --smtpspeed $speed --username $serv->smtp_username --password $serv->smtp_password $ssl --test --nodone > /dev/null 2>&1",$descriptors,$pipes);
                        while (1) {
                            MailLog::info("Waiting for process to finish...");
                            sleep(1);
                            $status = proc_get_status($procas);
                            if (!$status['running']) {
                                break;
                            }
                        }
                        MailLog::info("Parent process finished!");
                        break;
                    case "amazon-api":
                        // we should setup SNS handlers here :|
                        $servas = \Acelle\Model\SendingServer::findByUid($serv->uid);
                        $message = \Swift_Message::newInstance();
                        $message->setFrom($this->from_email);
                        $message->setTo($email);
                        $servas->initializeSns($message);
                        MailLog::info("Restarting amazon-api sender service...");
                        MailLog::info("\$HOME/gosender --send --campuid $this->uid --type $serv->type --accesskey $serv->aws_access_key_id --secretkey $serv->aws_secret_access_key --region $serv->aws_region --smtpspeed $speed --test --nodone");
                        exec("setsid \$HOME/gosender --send --campuid $this->uid --type $serv->type --accesskey $serv->aws_access_key_id --secretkey $serv->aws_secret_access_key --region $serv->aws_region --smtpspeed $speed --test --nodone < /dev/null > /dev/null 2>&1 &");
                        break;
                    case "sendgrid-api":
                        MailLog::info("Restarting sendgrid-api sender service $serv->api_key for campaign $this->uid");
                        MailLog::info("\$HOME/gosender --send --campuid $this->uid --type $serv->type --accesskey $serv->api_key --smtpspeed $speed --test --nodone > /dev/null 2>&1 &");
                        exec("setsid \$HOME/gosender --send --campuid $this->uid --type $serv->type --accesskey $serv->api_key --smtpspeed $speed --test --nodone < /dev/null > /dev/null 2>&1 &");
                        break;
                }
            }

        }

            while (true) {
                if (!$this->check_if_sending($this->uid)) break;
                sleep(5);
                MailLog::info("Waiting for test sending using all domains when will be finished...");
            }


            MailLog::info("Total domains tested using domain test tool: ".$dom_count);
            Redis::set($this->uid,$campaign_backup);

            $result['status'] = 'sent';
            // examine the result from sending server
            if(array_has($result, 'error')) {
                throw new \Exception($result['error']);
            }

            return [
                "status" => "success",
                "message" => trans('messages.campaign.test_sent')
            ];
        } catch (\Exception $e) {
            return [
                "status" => "error",
                "message" => $e->getMessage()
            ];
        }
    }

    public function sendTestEmailMass($email) {
        try {
            $use_sleep = 0;
            $sleep_timeout = 100000; // 2 seconds are 2000000
            $debug = 0;
            MailLog::info("We are now sending mass email test to all servers!");
            // generate email
            $subscriber = $this->createStdClassSubscriber(['email' => $email, 'status' => 'subscribed']);
            if (!Redis::exists($this->uid)) Redis::set($this->uid,json_encode($this));
            MailLog::info("Starting test mail the campaign: ".$this->name);
            \DB::connection()->disableQueryLog();
            // we replace the pont "." with the server on the sending backend
            $message_id = StringHelper::generateMessageId("");
            $subscriber->open_tracking = $message_id ."{SRVBL}";
            $message_id = str_replace('{SRVBL}','.',$subscriber->open_tracking);
            $subscriber->message_id = $message_id;
            $subscriber->track_url = Setting::get('global_tracking');
            $subscriber->tracking_url = str_replace('{CAMPAIGN}', StringHelper::generateCampaignName(), Setting::get('url_open_track'));
            $subscriber->unsubscribe_url = str_replace(   '{CAMPAIGN}', StringHelper::generateUnsubscribeUrl(), str_replace('MESSAGE_ID', StringHelper::base64UrlEncode2($message_id), Setting::get('url_unsubscribe')));
            $subscriber->update_url = str_replace( '{RANDOM}', StringHelper::generateCampaignName(), str_replace('LIST_UID', $this->defaultMailList->uid, str_replace('SUBSCRIBER_UID', 0, str_replace('SECURE_CODE', $this->getSecKey($subscriber->email, 'update-profile'), Setting::get('url_update_profile')))));
            $subscriber->msgid1 = StringHelper::base64UrlEncode($message_id);
            $subscriber->msgid2 = StringHelper::base64UrlEncode3($message_id);
            // clean redis test queue before sendout
            if (Redis::exists("campaign_".$this->uid."_subscribers_test")) Redis::del("campaign_".$this->uid."_subscribers_test");
            // detect all servers assigned to the campaign
            if (DB::table('campaigns')->select('all_sending_servers')
                    ->join('campaigns_lists_segments','campaigns.id','=','campaigns_lists_segments.campaign_id')
                    ->join('mail_lists','campaigns_lists_segments.mail_list_id','=','mail_lists.id')->where('mail_lists.id',$this->defaultMailList->id)->first()->all_sending_servers == 1) {
                $servers = \DB::table('sending_servers')->select('sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port','smtp_protocol','smtp_username','smtp_password')
                    ->where('status', 'active')->get();
                MailLog::info("all servers assigend");
            } else {
                $servers = \DB::table('campaigns')->select('sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port', 'fitness','smtp_protocol','smtp_username','smtp_password')
                    ->join('campaigns_lists_segments', 'campaigns.id', '=', 'campaigns_lists_segments.campaign_id')
                    ->join('mail_lists_sending_servers', 'campaigns_lists_segments.mail_list_id', '=', 'mail_lists_sending_servers.mail_list_id')
                    ->join('sending_servers', 'mail_lists_sending_servers.sending_server_id', '=', 'sending_servers.id')
                    ->where('campaigns.uid', $this->uid)->get();
                MailLog::info("servers assigned by hand");
            }
            MailLog::info("Servers detected: ".count($servers));
            $base_subject = $this->subject;
            // foreach cycle with all servers
            foreach ($servers as $serv) {
                $ssl = "";
                if ($serv->smtp_protocol != "") $ssl = "--ssl";
                if ($this->defaultMailList->all_sending_servers == 1) $serv->fitness = $this->defaultMailList->speed ? $serv->fitness = $this->defaultMailList->speed : \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
                if (!isset($serv->fitness)) $serv->fitness = \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
                // add subscriber to the redis queue
                $this->subject = $base_subject . " -> ".$serv->host;
                Redis::set($this->uid,json_encode($this));
                Redis::sAdd("campaign_".$this->uid."_subscribers_test",\json_encode($subscriber));

                // maybe we need to wait some time to sync the processes

                switch ($serv->type) {
                    case "smtp":
                        if ($debug > 0) MailLog::info("./gosender --send --campuid $this->uid --smtphost $serv->host --smtpport $serv->smtp_port --smtpspeed 8000 --username $serv->smtp_username --password $serv->smtp_password $ssl --test --nodone");
                        MailLog::info("Trying test email sendout to server: ".$serv->host.":".$serv->smtp_port);
                        exec("\$HOME/gosender --send --campuid $this->uid --smtphost $serv->host --smtpport $serv->smtp_port --smtpspeed 8000 --username $serv->smtp_username --password $serv->smtp_password $ssl --test --nodone");
                        break;
                    case "amazon-api":
                        if ($debug > 0) MailLog::info("Restarting amazon-api sender service...");
                        if ($debug > 0) MailLog::info("\$HOME/gosender --send --campuid $this->uid --accesskey $serv->aws_access_key_id --secretkey $serv->aws_secret_access_key --region $serv->aws_region --smtpspeed $serv->fitness --test --nodone");
                        exec("\$HOME/gosender --send --campuid $this->uid --type $serv->type --accesskey $serv->aws_access_key_id --secretkey $serv->aws_secret_access_key --region $serv->aws_region --smtpspeed $serv->fitness --test --nodone");
                        break;
                    case "sendgrid-api":
                        MailLog::info("Restarting sendgrid-api sender service $serv->api_key for campaign $this->uid");
                        exec("setsid \$HOME/gosender --send --campuid $this->uid --type $serv->type --accesskey $serv->api_key --smtpspeed $serv->fitness --test --nodone < /dev/null > /dev/null 2>&1 &");
                        break;
                }
                if ($use_sleep > 0) usleep($sleep_timeout);
            }

            MailLog::info("All test sendouts for campaign: ".$this->uid." is finished now!");

            // send the result as ok to the frontend
            $result['status'] = 'sent';
            if(array_has($result, 'error')) {
                throw new \Exception($result['error']);
            }

            return [
                "status" => "success",
                "message" => trans('messages.campaign.test_sent')
            ];
        } catch (\Exception $e) {
return [
"status" => "error",
"message" => $e->getMessage()
];
}
    }


    /**
     * Send a test email for testing campaign
     */
    public function sendTestEmail($email)
    {
        try {

            //hack MailLog::info('Sending test email for campaign `' . $this->name . '`');
// hack            MailLog::info('Sending test email to `' . $email . '`');

            // @todo: only send a test message when campaign sufficient information is available

            // build a temporary subscriber oject used to pass through the sending methods
            $subscriber = $this->createStdClassSubscriber(['email' => $email, 'status' => 'subscribed']);
            if (!Redis::exists($this->uid)) Redis::set($this->uid,json_encode($this));
            //$this->sending();
            MailLog::info("Starting test mail the campaign: ".$this->name);
            // sudedam visa maillista i redis
            // HACK TO make more performance
            \DB::connection()->disableQueryLog();
            // we replace the pont "." with the server on the sending backend
            $message_id = StringHelper::generateMessageId("");
            $subscriber->open_tracking = $message_id ."{SRVBL}";
            $message_id = str_replace('{SRVBL}','.',$subscriber->open_tracking);

                $subscriber->message_id = $message_id;
                $subscriber->track_url = Setting::get('global_tracking');
                // sudedam linkus ir t.t.
                //$subscriber->tracking_url = str_replace('{CAMPAIGN}', StringHelper::generateCampaignName(), str_replace('MESSAGE_ID', StringHelper::base64UrlEncode($message_id), Setting::get('url_open_track')));
                $subscriber->tracking_url = str_replace('{CAMPAIGN}', StringHelper::generateCampaignName(), Setting::get('url_open_track'));
                $subscriber->unsubscribe_url = str_replace(   '{CAMPAIGN}', StringHelper::generateUnsubscribeUrl(), str_replace('MESSAGE_ID', StringHelper::base64UrlEncode2($message_id), Setting::get('url_unsubscribe')));
                $subscriber->update_url = str_replace( '{RANDOM}', StringHelper::generateCampaignName(), str_replace('LIST_UID', $this->defaultMailList->uid, str_replace('SUBSCRIBER_UID', 0, str_replace('SECURE_CODE', $this->getSecKey($subscriber->email, 'update-profile'), Setting::get('url_update_profile')))));
                $subscriber->msgid1 = StringHelper::base64UrlEncode($message_id);
                $subscriber->msgid2 = StringHelper::base64UrlEncode3($message_id);


            // sudedam i array subscriberi
            if (Redis::exists("campaign_".$this->uid."_subscribers_test")) Redis::del("campaign_".$this->uid."_subscribers_test");
            Redis::sAdd("campaign_".$this->uid."_subscribers_test",\json_encode($subscriber));


            // get all assigned servers
            if (DB::table('campaigns')->select('all_sending_servers')
                    ->join('campaigns_lists_segments','campaigns.id','=','campaigns_lists_segments.campaign_id')
                    ->join('mail_lists','campaigns_lists_segments.mail_list_id','=','mail_lists.id')->where('mail_lists.id',$this->defaultMailList->id)->first()->all_sending_servers == 1) {
                $servers = \DB::table('sending_servers')->select('sending_servers.uid','sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port','smtp_protocol','smtp_username','smtp_password')
                    ->where('status', 'active')->get();
                MailLog::info("all servers assigend");
            } else {
                $servers = \DB::table('campaigns')->select('sending_servers.uid','sending_servers.type','host','aws_access_key_id','aws_secret_access_key','aws_region','api_key', 'smtp_port', 'fitness','smtp_protocol','smtp_username','smtp_password')
                    ->join('campaigns_lists_segments', 'campaigns.id', '=', 'campaigns_lists_segments.campaign_id')
                    ->join('mail_lists_sending_servers', 'campaigns_lists_segments.mail_list_id', '=', 'mail_lists_sending_servers.mail_list_id')
                    ->join('sending_servers', 'mail_lists_sending_servers.sending_server_id', '=', 'sending_servers.id')
                    ->where('campaigns.uid', $this->uid)->get();
                MailLog::info("servers assigned by hand");
            }

            MailLog::info("Servers detected: ".count($servers));

            foreach ($servers as $serv) {
                $ssl = "";
                if ($serv->smtp_protocol != "") $ssl = "--ssl";
                if ($this->defaultMailList->all_sending_servers == 1) $serv->fitness = $this->defaultMailList->speed ? $serv->fitness = $this->defaultMailList->speed : \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
                if (!isset($serv->fitness)) $serv->fitness = \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
                switch ($serv->type) {
                    case "smtp":
                        MailLog::info("./gosender --send --campuid $this->uid --smtphost $serv->host --smtpport $serv->smtp_port --smtpspeed $serv->fitness --username $serv->smtp_username --password $serv->smtp_password $ssl --test --nodone");
                        // setsid + </dev/null detaches gosender into its own session so it
                        // survives php-fpm finishing the request. Without it, the backgrounded
                        // child is killed before it pops the test subscriber from Redis, so no
                        // test email is ever delivered (the subscriber is left stuck in the set).
                        exec("setsid \$HOME/gosender --send --campuid $this->uid --smtphost $serv->host --smtpport $serv->smtp_port --smtpspeed $serv->fitness --username $serv->smtp_username --password $serv->smtp_password $ssl --test --nodone < /dev/null > /dev/null 2>&1 &");
                        break;
                    case "amazon-api":
                        // we should setup SNS handlers here :|
                        $servas = \Acelle\Model\SendingServer::findByUid($serv->uid);
                        $message = \Swift_Message::newInstance();
                        $message->setFrom($this->from_email);
                        $message->setTo($email);
                        $servas->initializeSns($message);

                        MailLog::info("Restarting amazon-api sender service...");
                        MailLog::info("\$HOME/gosender --send --campuid $this->uid --type $serv->type --accesskey $serv->aws_access_key_id --secretkey $serv->aws_secret_access_key --region $serv->aws_region --smtpspeed $serv->fitness --test --nodone");
                        exec("setsid \$HOME/gosender --send --campuid $this->uid --type $serv->type --accesskey $serv->aws_access_key_id --secretkey $serv->aws_secret_access_key --region $serv->aws_region --smtpspeed $serv->fitness --test --nodone < /dev/null > /dev/null 2>&1 &");
                        break;
                    case "sendgrid-api":
                        MailLog::info("Restarting sendgrid-api sender service $serv->api_key for campaign $this->uid");
                        MailLog::info("\$HOME/gosender --send --campuid $this->uid --type $serv->type --accesskey $serv->api_key --smtpspeed $serv->fitness --test --nodone > /dev/null 2>&1 &");
                        exec("setsid \$HOME/gosender --send --campuid $this->uid --type $serv->type --accesskey $serv->api_key --smtpspeed $serv->fitness --test --nodone < /dev/null > /dev/null 2>&1 &");
                        break;
                }
            }


           $result['status'] = 'sent';
            // examine the result from sending server
            if(array_has($result, 'error')) {
                throw new \Exception($result['error']);
            }

            return [
                "status" => "success",
                "message" => trans('messages.campaign.test_sent')
            ];
        } catch (\Exception $e) {
            return [
                "status" => "error",
                "message" => $e->getMessage()
            ];
        }
    }

    /**
     * Get the delay time before sending
     *
     */
    public function getDelayInSeconds()
    {
        $now = Carbon::now();

        if ($now->gte($this->run_at)) {
            return 0;
        } else {
            return $this->run_at->diffInSeconds($now);
        }
    }

    /**
     * Re-queue the campaign for sending
     *
     */
    public function requeue()
    {
        // clear all campaign's sending jobs which are being queued
        $this->clearAllJobs();

        // and queue again
        $this->queue();
    }

    /**
     * archive the campaign
     */

    public function archive()
    {
        $this->clearAllJobs();
        $this->archived = Carbon::now();
        $this->save();
    }

    /*
     * unarchive the campaign
     */

    public function unarchive()
    {
        $this->clearAllJobs();
        $this->archived = null;
        $this->save();
    }


    /**
     * Overwrite the delete() method to also clear the pending jobs
     *
     */
    public function delete()
    {
        // FIXME OPTIMIZE DELETE BY LIMIT
        $this->clearAllJobs();

        $this->del_date = Carbon::now();
        $this->save();

      //  parent::delete();
    }

    public function force_delete()
    {
        $this->clearAllJobs();
        parent::delete();
    }



    /**
     * Create a stdClass subscriber (for sending a campaign test email)
     * The campaign sending functions take a subscriber object as input
     * However, a test email address is not yet a subscriber object, so we have to build a fake stdClass object
     * which can be used as a real subscriber
     *
     * @param array $subscriber
     */
    public function createStdClassSubscriber($subscriber)
    {
        // default attributes that are required.
        // mail_list_id is REQUIRED by gosender — its Subscriber struct declares
        // `Mail_list_id int json:",string"`, so the JSON value must be a quoted
        // string. Without this field the test subscriber is silently dropped and
        // no test email is ever delivered (all 4 test/simulation methods are
        // affected, since they all build their subscriber through here).
        $jsonObj = [
            'uid' => uniqid(),
            'mail_list_id' => (string) ($this->defaultMailList ? $this->defaultMailList->id : 0),
        ];

        // append the customer specified attributes and build a stdClass object
        $stdObj = json_decode(json_encode(array_merge($jsonObj, $subscriber)));

        return $stdObj;
    }

    /**
     * Check if the given variable is a subscriber object (for actually sending a campaign)
     * Or a stdClass subscriber (for sending test email)
     *
     * @param Object $object
     */
    public function isStdClassSubscriber($object) {
        return (get_class($object) == 'stdClass');
    }

    /**
     * Get information from mail list
     *
     * @param void
     */
    public function getInfoFromMailList($list) {
        $this->from_name = !empty($this->from_name) ? $this->from_name : $list->from_name;
        $this->from_email = !empty($this->from_email) ? $this->from_email : $list->from_email;
        $this->subject = !empty($this->subject) ? $this->subject : $list->default_subject;
    }

    /**
     * Check if auto campaign designed.
     *
     * @return object
     */
    public function autoCampaignDesigned()
    {
        $cond = (!empty($this->name) && !empty($this->subject) && !empty($this->from_name)
            && !empty($this->from_email) && !empty($this->reply_to));

        $cond = $cond && ((!empty($this->html) || $this->type == 'plain-text') && !empty($this->plain) && $this->unsubscribe_url_valid());

        return $cond;
    }

    /**
     * Get type select options.
     *
     * @return array
     */
    public static function getTypeSelectOptions()
    {
        return [
            ['text' => trans('messages.' . self::TYPE_REGULAR), 'value' => self::TYPE_REGULAR],
            ['text' => trans('messages.' . self::TYPE_PLAIN_TEXT), 'value' => self::TYPE_PLAIN_TEXT]
        ];
    }

    /**
     * Check if campaign is automated.
     *
     * @return boolean
     */
    public function isAuto()
    {
        return $this->is_auto;
    }

    /**
     * The validation rules for automation trigger.
     *
     * @var array
     */
    public function recipientsRules($params = [])
    {
        $rules = [
            'lists_segments' => 'required'
        ];

        if(isset($params['lists_segments'])) {
            foreach ($params['lists_segments'] as $key => $param) {
                $rules['lists_segments.'.$key.'.mail_list_uid'] = 'required';
            }
        }

        return $rules;
    }

    /**
     * Fill recipients by params.
     *
     * @var void
     */
    public function fillRecipients($params=[])
    {
        if(isset($params['lists_segments'])) {
            foreach ($params['lists_segments'] as $key => $param) {
                $mail_list = null;

                if(!empty($param['mail_list_uid'])) {
                    $mail_list = MailList::findByUid($param['mail_list_uid']);

                    // default mail list id
                    if(isset($param['is_default']) && $param['is_default'] == 'true') {
                        $this->default_mail_list_id = $mail_list->id;
                    }
                }

                if(!empty($param['segment_uids'])) {
                    foreach ($param['segment_uids'] as $segment_uid) {
                        $segment = Segment::findByUid($segment_uid);

                        $lists_segment = new CampaignsListsSegment();
                        $lists_segment->campaign_id = $this->id;
                        if(is_object($mail_list)) {
                            $lists_segment->mail_list_id = $mail_list->id;
                        }
                        $lists_segment->segment_id = $segment->id;
                        $this->listsSegments->push($lists_segment);
                    }
                } else {
                    $lists_segment = new CampaignsListsSegment();
                    $lists_segment->campaign_id = $this->id;
                    if(is_object($mail_list)) {
                        $lists_segment->mail_list_id = $mail_list->id;
                    }
                    $this->listsSegments->push($lists_segment);
                }
            }
        }
    }

    /**
     * Save Recipients.
     *
     * @var void
     */
    public function saveRecipients($params=[])
    {
        // Empty current data
        $this->listsSegments = collect([]);
        // Fill params
        $this->fillRecipients($params);

        $lists_segments_groups = $this->getListsSegmentsGroups();

        $data = [];
        foreach($lists_segments_groups as $lists_segments_group) {
            if(!empty($lists_segments_group['segment_uids'])) {
                foreach($lists_segments_group['segment_uids'] as $segment_uid) {
                    $segment = Segment::findByUid($segment_uid);
                    $data[] = [
                        'campaign_id' => $this->id,
                        'mail_list_id' => $lists_segments_group['list']->id,
                        'segment_id' => $segment->id,
                    ];
                }
            } else {
                $data[] = [
                    'campaign_id' => $this->id,
                    'mail_list_id' => $lists_segments_group['list']->id,
                    'segment_id' => NULL,
                ];
            }
        }

        // Empty old data
        $this->listsSegments()->delete();

        // Insert Data
        CampaignsListsSegment::insert($data);

        // Save campaign with default list id
        $campaign = Campaign::find($this->id);
        $campaign->default_mail_list_id = $this->default_mail_list_id;
        $campaign->save();
    }

    /**
     * Display Recipients.
     *
     * @var array
     */
    public function displayRecipients()
    {
        if(!is_object($this->defaultMailList)) {
            return "";
        }

        $lines = [];
        // FIXME it's got problem in 2018.10.31
        try {
            foreach ($this->getListsSegmentsGroups() as $lists_segments_group) {
                $list_name = $lists_segments_group['list']->name;

                $segment_names = [];
                if (!empty($lists_segments_group['segment_uids'])) {
                    foreach ($lists_segments_group['segment_uids'] as $segment_uid) {
                        $segment = Segment::findByUid($segment_uid);
                        $segment_names[] = $segment->name;
                    }
                }

                if (empty($segment_names)) {
                    $lines[] = $list_name;
                } else {
                    $lines[] = implode(': ', [$list_name, implode(', ', $segment_names)]);
                }
            }
        } catch (\Exception $ex) {
            MailLog::error("Got problem in AbsCampaign:displayRecipientes function: ".$ex);
        }

        return implode(' | ', $lines);
    }

    /**
     * Check if campaign is paused.
     *
     * @return boolean
     */
    public function isPaused()
    {
        return $this->status == self::STATUS_PAUSED;
    }

    /**
     * Update Campaign cached data
     *
     * @return void
     */
    public function updateCache($key = null)
    {
        // new implementation as is
        // very experimental and should not work as expected
        // new implementation uses external API to RabiitMQ
        MailLog::info("EXPERIMENTAL Campaign cache update is initiated!!!");
        $taskrunner = New TaskRunner();
        $customer2= $this->customer->id;
        $taskrunner->send_queue($taskrunner::MESSAGE_TYPE_CAMPAIGN_UPDATE,$customer2,$taskrunner::PRIORITY_LOW,$this->uid);
        MailLog::info("EXPERIMENTAL Campaign cache update already passed!!! customer: ".$customer2. " campuid: ".$this->uid);

        // cache indexes
//        $index = [
//            // @note: SubscriberCount must come first as its value shall be used by the others
//            'SubscriberCount' => function(&$campaign) {
//                return $campaign->subscribersCount(false); // spepcial key that requires true update
//            },
//            'DeliveredRate' => function(&$campaign) {
//                return $campaign->deliveredRate(true);
//            },
//            'DeliveredCount' => function(&$campaign) {
//                return $campaign->deliveredCount();
//            },
//            'ClickedRate' => function(&$campaign) {
//                return $campaign->clickedEmailsRate();
//            },
//            'UniqOpenRate' => function(&$campaign) {
//                return $campaign->openUniqRate();
//            },
//            'UniqOpenCount' => function(&$campaign) {
//                return $campaign->openUniqCount();
//            },
//            'NotOpenRate' => function(&$campaign) {
//                return $campaign->notOpenRate(true);
//            },
//            'NotOpenCount' => function(&$campaign) {
//                return $campaign->notOpenCount(true);
//            }
//        ];
//
//        // retrieve cached data
//        $cache = json_decode($this->cache, true);
//        if (is_null($cache)) {
//            $cache = [];
//        }
//
//        if (is_null($key)) {
//            // update all cache
//            foreach($index as $key => $callback) {
//                $cache[$key] = $callback($this);
//                if ($key == 'SubscriberCount') {
//                    // SubscriberCount cache must always be updated as its value will be used for the others
//                    $this->cache = json_encode($cache);
//                    $this->save();
//                }
//            }
//        } else {
//            // @deprecated, requires updating the SubscriberCount cache before updating any other one
//            // update specific key
//            $callback = $index[$key];
//            $cache[$key] = $callback($this);
//        }
//
//        // write back to the DB
//        $this->cache = json_encode($cache);
//        $this->save();
    }

    /**
     * Retrieve Campaign cached data
     *
     * @return mixed
     */
    public function readCache($key, $default = null)
    {
        try {
            $cache = json_decode(\Redis::get('campaign_'.$this->uid.'_cache'),true);
            MailLog::info("Getting data from redis cache for campaign: ".$this->uid);
            if (array_key_exists($key, $cache)) {
                if (is_null($cache[$key])) {
                    return $default;
                } else {
                    return $cache[$key];
                }
            } else {
                return $default;
            }
        } catch (\Exception $ex) {
            MailLog::info("Unable to get cache from redis for campaign: ".$this->uid);
            $cache = json_decode($this->cache, true);
            if (is_null($cache)) {
                return $default;
            }
            if (array_key_exists($key, $cache)) {
                if (is_null($cache[$key])) {
                    return $default;
                } else {
                    return $cache[$key];
                }
            } else {
                return $default;
            }
        }
    }

    /**
     * Count subscribers.
     *
     * @return integer
     */
    public function subscribersCount($cache = false)
    {
        if ($cache) {
            return $this->readCache('SubscriberCount', 0);
        }
        // TODO implement exact number, that currently is (count subscribers with only status subscribed)
        // email_verifications join is required in case of segment condition (for example: 'deliverable' or 'risky')
         return distinctCount($this->subscribers([], []), 'subscribers.email');
        //return distinctCount($this->subscribers([], ['email_verifications']), 'subscribers.email');
    }

    /**
     * Count unique open by hour.
     *
     * @return number
     */
    public function openUniqHours($start = null, $end = null)
    {
        $query = $this->openLogs()->select('open_logs.created_at');
        if (isset($start)) {
            $query = $query->where('open_logs.created_at', '>=', $start);
        }
        if (isset($end)) {
            $query = $query->where('open_logs.created_at', '<=', $end);
        }

        return $query->orderBy('open_logs.created_at', 'asc')->get()->groupBy(function($date) {
            return \Acelle\Library\Tool::dateTime($date->created_at)->format('H'); // grouping by hours
        });
    }

    /**
     * Count click group by hour.
     *
     * @return number
     */
    public function clickHours($start = null, $end = null)
    {
        $query = $this->clickLogs()->select('click_logs.created_at', 'tracking_logs.subscriber_id');

        if (isset($start)) {
            $query = $query->where('click_logs.created_at', '>=', $start);
        }
        if (isset($end)) {
            $query = $query->where('click_logs.created_at', '<=', $end);
        }

        return $query->orderBy('click_logs.created_at', 'asc')->get()->groupBy(function($date) {
            return \Acelle\Library\Tool::dateTime($date->created_at)->format('H'); // grouping by hours
        });
    }
}
