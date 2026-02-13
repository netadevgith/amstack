<?php
use Illuminate\Support\Facades\Route;


// DEPRECATED
Route::resource('automation', 'AutomationController');
Route::group(['middleware' => ['installed']], function () {
    // Installation
    Route::get('install', 'InstallController@starting');
    Route::get('install/site-info', 'InstallController@siteInfo');
    Route::post('install/site-info', 'InstallController@siteInfo');
    Route::get('install/system-compatibility', 'InstallController@systemCompatibility');
    Route::get('install/database', 'InstallController@database');
    Route::post('install/database', 'InstallController@database');
    Route::get('install/database_import', 'InstallController@databaseImport');
    Route::get('install/import', 'InstallController@import');
    Route::get('install/cron-jobs', 'InstallController@cronJobs');
    Route::post('install/cron-jobs', 'InstallController@cronJobs');
    Route::get('install/finishing', 'InstallController@finishing');
    Route::get('install/finish', 'InstallController@finish');
});


    Route::get('', 'HomeController@index');

// DEPRECATED2
Route::group(['middleware' => ['not_logged_in']], function () {
    //Route::auth();
    Auth::routes();
    Route::get('user/activate/{token}', 'UserController@activate');


   // Route::get('/offline', 'Controller@offline');
    Route::get('/not-authorized', 'Controller@notAuthorized');
  //  Route::get('/demo', 'Controller@demo');
  //  Route::get('/demo/go/{view}', 'Controller@demoGo');
    Route::get('/autologin/{api_token}', 'Controller@autoLogin');
    //Route::get('/reload/cache', 'Controller@reloadCache');
    //Route::get('/migrate/run', 'Controller@runMigration');
    Route::post('/remote-job/{remote_job_token}', 'Controller@remoteJob');

    // Customer avatar
    Route::get('assets/images/avatar/customer-{uid?}.jpg', 'CustomerController@avatar');

    // Admin avatar
    Route::get('assets/images/avatar/admin-{uid?}.jpg', 'AdminController@avatar');

    // Customer subscription
    Route::get('subscriptions/preview', 'SubscriptionController@preview');
    Route::post('subscriptions/register/{plan_uid?}', 'SubscriptionController@register');
    Route::get('subscriptions/register/{plan_uid?}', 'SubscriptionController@register');
   // Route::get('subscriptions/select-plan', 'SubscriptionController@selectPlan');

    // User resend activation email
    Route::get('users/resend-activation-email', 'UserController@resendActivationEmail');

    // Plan
 //   Route::get('plans/select2', 'PlanController@select2');

//    // Payments
//    Route::get('paypal-payment-cancel/{subscription_uid}', 'PaymentController@paypalCancel');
//    Route::get('paypal-payment-status/{subscription_uid}', 'PaymentController@paypalStatus');
//    Route::get('paypal-payment-cancel', function () {
//        return 'Payment has been canceled';
//    });

    Route::get('logout',function() {
        \Auth::logout();
        return redirect('/');
    });

    // Translation data
    Route::get('/datatable_locale', 'Controller@datatable_locale');
    Route::get('/jquery_validate_locale', 'Controller@jquery_validate_locale');

   // Route::get('payments/paddle/card/{subscription_uid}/hook', 'PaymentController@paddle_card_hook');
   // Route::post('payments/paddle/card/{subscription_uid}/hook', 'PaymentController@paddle_card_hook');
});

Route::group(['middleware' => ['not_installed', 'frontend']], function () {
    Route::get('/', 'HomeController@index');
    Route::get('/current_user_uid', 'UserController@showUid');

    // Update current user profile
    Route::get('account/api/renew', 'AccountController@renewToken');
    Route::get('account/api', 'AccountController@api');
    Route::get('account/profile', 'AccountController@profile');
    Route::post('account/profile', 'AccountController@profile');
    Route::get('account/contact', 'AccountController@contact');
    Route::post('account/contact', 'AccountController@contact');
    Route::get('account/logs', 'AccountController@logs');
    Route::get('account/logs/listing', 'AccountController@logsListing');
    Route::get('account/quota_log', 'AccountController@quotaLog');
    Route::get('account/subscription', 'AccountController@subscription');
    Route::get('account/subscription/new', 'AccountController@subscriptionNew');

    // User avatar
    Route::get('assets/images/avatar/user-{uid?}.jpg', 'UserController@avatar');

    // Mail list
    Route::get('lists/{uid}/check-email', 'MailListController@checkEmail');
    Route::get('lists/{uid}/verification/progress', 'MailListController@verificationProgress');
    Route::get('lists/{uid}/verification', 'MailListController@verification');
    Route::post('lists/{uid}/verification/start', 'MailListController@startVerification');
    Route::post('lists/{uid}/verification/stop', 'MailListController@stopVerification');
    Route::post('lists/{uid}/verification/reset', 'MailListController@resetVerification');
    Route::post('lists/copy', 'MailListController@copy');
    Route::get('lists/quick-view', 'MailListController@quickView');
    Route::get('lists/{uid}/list-growth', 'MailListController@listGrowthChart');
    Route::get('lists/{uid}/openers-growth', 'MailListController@OpenerslistGrowthChart');
    Route::get('lists/{uid}/list-statistics-chart', 'MailListController@statisticsChart');
    Route::get('lists/sort', 'MailListController@sort');
    Route::get('lists/listing/{page?}', 'MailListController@listing');
    Route::get('lists/delete', 'MailListController@delete');
    // FIXME
    Route::get('lists/createopeners', 'MailListController@createopeners');
    Route::post('lists/storeopeners', 'MailListController@storeopeners');
    // FIXME impl delete by domain
    Route::get('lists/deletebydomain/{uid}/{domain}','MailListController@deletebydomain');
    Route::post('lists/deletebydomain/{uid}/{domain}','MailListController@deletebydomain');
    Route::get('lists/openersbyprovider/{uid}','MailListController@openersbyprovider');

    Route::get('lists/delete/confirm', 'MailListController@deleteConfirm');
    Route::get('lists/{uid}/overview', 'MailListController@overview');
    Route::resource('lists', 'MailListController');
    Route::get('lists/{uid}/edit', 'MailListController@edit');
    Route::patch('lists/{uid}/update', 'MailListController@update');
    Route::get('lists/{uid}/embedded-form', 'MailListController@embeddedForm');
    Route::post('lists/{uid}/embedded-form-subscribe', 'MailListController@embeddedFormSubscribe');
    Route::post('lists/{uid}/embedded-form-subscribe-captcha', 'MailListController@embeddedFormCaptcha');
    Route::get('lists/{uid}/embedded-form-frame', 'MailListController@embeddedFormFrame');

    // FIXME test
    Route::get('contacts','MailListController@contacts');
    Route::get('servers/setserver/{mail_list_id}/{server_id}','MailListController@setserver');


    // Field
    Route::get('lists/{list_uid}/fields', 'FieldController@index');
    Route::get('lists/{list_uid}/fields/sort', 'FieldController@sort');
    Route::post('lists/{list_uid}/fields/store', 'FieldController@store');
    Route::get('lists/{list_uid}/fields/sample/{type}', 'FieldController@sample');
    Route::get('lists/{list_uid}/fields/{uid}/delete', 'FieldController@delete');

    // Subscriber
    Route::post('subscriber/{uid}/verification/start', 'SubscriberController@startVerification');
    Route::post('subscriber/{uid}/verification/reset', 'SubscriberController@resetVerification');
    Route::get('lists/{from_uid}/copy-move-from/{action}', 'SubscriberController@copyMoveForm');
    Route::post('subscribers/move', 'SubscriberController@move');
    Route::post('subscribers/copy', 'SubscriberController@copy');
    Route::get('lists/{list_uid}/subscribers', 'SubscriberController@index');
    Route::get('lists/{list_uid}/subscribers/deldup', 'SubscriberController@delete_dupes');
    Route::get('lists/{list_uid}/subscribers/create', 'SubscriberController@create');
    Route::get('lists/{list_uid}/subscribers/listing', 'SubscriberController@listing');
    Route::post('lists/{list_uid}/subscribers/store', 'SubscriberController@store');
    Route::get('lists/{list_uid}/subscribers/{uid}/edit', 'SubscriberController@edit');
    Route::patch('lists/{list_uid}/subscribers/{uid}/update', 'SubscriberController@update');
    Route::get('lists/{list_uid}/subscribers/delete', 'SubscriberController@delete');
    Route::get('lists/{list_uid}/subscribers/subscribe', 'SubscriberController@subscribe');
    Route::get('lists/{list_uid}/subscribers/unsubscribe', 'SubscriberController@unsubscribe');
    Route::get('lists/{list_uid}/subscribers/import', 'SubscriberController@import');
    Route::post('lists/{list_uid}/subscribers/import', 'SubscriberController@import');
    Route::get('lists/{list_uid}/subscribers/import/list', 'SubscriberController@importList');
    Route::get('lists/{list_uid}/subscribers/import/log', 'SubscriberController@downloadImportLog');
    Route::get('lists/{list_uid}/subscribers/import/proccess', 'SubscriberController@importProccess');
    Route::get('lists/{list_uid}/subscribers/export', 'SubscriberController@export');
    // new item
    Route::get('lists/{list_uid}/subscribers/recover', 'SubscriberController@recover');
    Route::post('lists/{list_uid}/subscribers/recover', 'SubscriberController@recover');
    Route::get('lists/{list_uid}/subscribers/remove', 'SubscriberController@remove');
    Route::post('lists/{list_uid}/subscribers/remove', 'SubscriberController@remove');


    Route::post('lists/{list_uid}/subscribers/export', 'SubscriberController@export');
    // FIXME TEST
    Route::get('lists/exportas/{list_uid}', 'SubscriberController@exportas');
    Route::get('lists/exportas2/{list_uid}', 'SubscriberController@exportas_without_bounces');
    Route::get('lists/exportfunc/{type}','SubscriberController@exportasfunc');
    Route::get('lists/exportfunc2/{id}/{type}','SubscriberController@exportasfunc2');
    Route::get('list/searchingexport/{id}/{search}','SubscriberController@searchingexport');


    Route::get('lists/{list_uid}/subscribers/export/proccess', 'SubscriberController@exportProccess');
    Route::get('lists/{list_uid}/subscribers/export/download', 'SubscriberController@downloadExportedCsv');
    Route::get('lists/{list_uid}/subscribers/export/list', 'SubscriberController@exportList');

    // Notification handler
    Route::post('delivery/notify/{stype}', 'DeliveryController@notify');
    Route::get('delivery/notify/{stype}', 'DeliveryController@notify');

    // Segment
    Route::get('segments/condition-value-control', 'SegmentController@conditionValueControl');
    Route::get('segments/select_box', 'SegmentController@selectBox');
    Route::get('lists/{list_uid}/segments', 'SegmentController@index');
    Route::get('lists/{list_uid}/segments/{uid}/subscribers', 'SegmentController@subscribers');
    Route::get('lists/{list_uid}/segments/{uid}/listing_subscribers', 'SegmentController@listing_subscribers');
    Route::get('lists/{list_uid}/segments/create', 'SegmentController@create');
    Route::get('lists/{list_uid}/segments/listing', 'SegmentController@listing');
    Route::post('lists/{list_uid}/segments/store', 'SegmentController@store');
    Route::get('lists/{list_uid}/segments/{uid}/edit', 'SegmentController@edit');
    Route::patch('lists/{list_uid}/segments/{uid}/update', 'SegmentController@update');
    Route::get('lists/{list_uid}/segments/delete', 'SegmentController@delete');
    Route::get('lists/{list_uid}/segments/sample_condition', 'SegmentController@sample_condition');

    // Page
    Route::get('lists/{list_uid}/pages/{alias}/update', 'PageController@update');
    Route::post('lists/{list_uid}/pages/{alias}/update', 'PageController@update');
    Route::post('lists/{list_uid}/pages/{alias}/preview', 'PageController@preview');
    Route::get('lists/{list_uid}/sign-up', 'PageController@signUpForm');
    Route::post('lists/{list_uid}/sign-up', 'PageController@signUpForm');
    Route::get('lists/{list_uid}/sign-up/{subscriber_uid}/thank-you', 'PageController@signUpThankyouPage');
    Route::get('lists/{list_uid}/subscribe-confirm/{uid}/{code}', 'PageController@signUpConfirmationThankyou');
    Route::get('lists/{list_uid}/unsubscribe/{uid}/{code}', 'PageController@unsubscribeForm');
    Route::post('lists/{list_uid}/unsubscribe/{uid}/{code}', 'PageController@unsubscribeForm');
  // FIXME OBSCURE PATH
   // Route::get('{RANDOM}/{list_uid}/u/{uid}/{code}', 'PageController@profileUpdateForm');
    Route::post('lists/{list_uid}/update-profile/{uid}/{code}', 'PageController@profileUpdateForm');
    Route::get('lists/{list_uid}/update-profile-success/{uid}', 'PageController@profileUpdateSuccessPage');
    Route::get('lists/{list_uid}/profile-update-email-sent/{uid}', 'PageController@profileUpdateEmailSent');
    Route::get('lists/{list_uid}/unsubscribe-success/{uid}', 'PageController@unsubscribeSuccessPage');

    // Template
    Route::post('templates/{uid}/copy', 'TemplateController@copy');
    Route::get('templates/{uid}/copy', 'TemplateController@copy');
    Route::get('templates/{uid}/content', 'TemplateController@content');
    Route::get('templates/sort', 'TemplateController@sort');
    Route::get('templates/listing/{page?}', 'TemplateController@listing');
    Route::get('templates/choosing/{campaign_uid}/{page?}', 'TemplateController@choosing');
    Route::get('templates/upload', 'TemplateController@upload');
    Route::post('templates/upload', 'TemplateController@upload');
    Route::get('templates/{uid}/image', 'TemplateController@image');
    Route::post('templates/{uid}/saveImage', 'TemplateController@saveImage');
    Route::get('templates/{uid}/preview', 'TemplateController@preview');
    Route::get('templates/delete', 'TemplateController@delete');
    Route::get('templates/build/select', 'TemplateController@buildSelect');
    Route::get('templates/build/{style?}', 'TemplateController@build');
    Route::get('templates/{uid}/rebuild', 'TemplateController@rebuild');
    Route::resource('templates', 'TemplateController');
    Route::get('templates/{uid}/edit', 'TemplateController@edit');
    Route::patch('templates/{uid}/update', 'TemplateController@update');

    // Campaign
    Route::get('campaigns/showopenersbyprovider/{uid}','CampaignController@showopenersbyprovider');
    Route::get('campaigns/showclickersbyprovider/{uid}','CampaignController@showclickersbyprovider');
    Route::get('campaigns/{from_uid}/copy-move-from/{action}', 'CampaignController@copyMoveForm');
    Route::post('campaigns/{uid}/resend', 'CampaignController@resend');
    Route::get('campaigns/{uid}/template/review-iframe', 'CampaignController@templateReviewIframe');
    Route::get('campaigns/{uid}/template/review', 'CampaignController@templateReview');
    Route::get('campaigns/{message_id}/web-view', 'CampaignController@webView');
    Route::get('campaigns/select-type', 'CampaignController@selectType');
    Route::get('campaigns/{uid}/list-segment-form', 'CampaignController@listSegmentForm');
    Route::post('campaigns/{uid}/image/save', 'CampaignController@saveImage');
    Route::get('campaigns/{uid}/image', 'CampaignController@image');
    Route::get('campaigns/{uid}/preview', 'CampaignController@preview');
    Route::get('campaigns/templates/list', 'CampaignController@templateList');
    Route::patch('campaigns/{uid}/templates/choose/from/{from_uid}', 'CampaignController@campaignTemplateChoose');
    Route::post('campaigns/send-test-email', 'CampaignController@sendTestEmail');
    Route::post('campaigns/send-test-mass','CampaignController@sendTestEmailMass');
    Route::post('campaigns/send-test-domain','CampaignController@sendTestEmailDomain');
    Route::post('campaigns/simulation-test','CampaignController@CampaignSimulation');
    Route::get('campaigns/simulation-stop/{uid}','CampaignController@CampaignSimulationStop');

    Route::get('campaigns/delete/confirm', 'CampaignController@deleteConfirm');





    $unsubscribepart = Redis::sMembers('unsubscribepart');
    $profilepart = Redis::sMembers('profilepart');

    if(!empty($unsubscribepart))
    {
        foreach ($unsubscribepart as $page)
        {
            if (Request::segment(3) == $page) {
                Route::get('/{campaigns}/{message_id}/{' . $page . '}', ['as' => $page, 'uses' => 'CampaignController@unsubscribe']);
            }
        }
    }

    if(!empty($profilepart))
    {
        // Route::get('{RANDOM}/{list_uid}/u/{uid}/{code}', 'PageController@profileUpdateForm');
        foreach ($profilepart as $page)
        {
            if (Request::segment(3) == $page) {
                Route::get('/{RANDOM}/{list_uid}/{' . $page . '}/{uid}/{code}', ['as' => $page, 'uses' => 'PageController@profileUpdateForm']);
            }
        }
    }


    Route::post('campaigns/copy', 'CampaignController@copy'); // copy campaign feature
    Route::get('campaigns/{uid}/subscribers', 'CampaignController@subscribers'); // shows subscribers of the campaign
    Route::get('campaigns/{uid}/subscribers/listing', 'CampaignController@subscribersListing'); // subscribers for campaign listing
    Route::get('campaigns/{uid}/open-map', 'CampaignController@openMap'); // openers map used in campaign overview
    Route::get('campaigns/{uid}/tracking-log', 'CampaignController@trackingLog'); // tracking log in campaign overview
    Route::get('campaigns/{uid}/tracking-log/listing', 'CampaignController@trackingLogListing'); // tracking log listing in campaign overview
    Route::get('campaigns/{uid}/bounce-log', 'CampaignController@bounceLog'); // bounce log in campaign overview
    Route::get('campaigns/{uid}/bounce-log/listing', 'CampaignController@bounceLogListing'); // bounce log listing in campaign overview
    Route::get('campaigns/{uid}/feedback-log', 'CampaignController@feedbackLog'); // feedbacklog in campaign overview
    Route::get('campaigns/{uid}/feedback-log/listing', 'CampaignController@feedbackLogListing'); // feedbacklog listing in campaign overview
    Route::get('campaigns/{uid}/open-log', 'CampaignController@openLog'); // open log in campaign overview
    Route::get('campaigns/{uid}/open-log/listing', 'CampaignController@openLogListing'); // campaign openers listing in campaign overview
    Route::get('campaigns/{uid}/click-log', 'CampaignController@clickLog'); // click log of campaign in campaign overview
    Route::get('campaigns/{uid}/click-log/listing', 'CampaignController@clickLogListing'); // click logs listing in campaign overview
    Route::get('campaigns/{uid}/unsubscribe-log', 'CampaignController@unsubscribeLog'); // unsubscribe log of the camopaign in overview
    Route::get('campaigns/{uid}/conversion-log', 'CampaignController@conversionLog'); // conversions log of the campaign in overview
    Route::get('campaigns/{uid}/unsubscribe-log/listing', 'CampaignController@unsubscribeLogListing'); // unsubscribe logs listing
    Route::get('campaigns/{uid}/conversion-log/listing', 'CampaignController@conversionLogListing'); // conversions listing for JS lister
    Route::get('campaigns/{uid}/export','SubscriberController@export_from_campaigns'); // export from campaigns overview
    Route::get('campaigns/{uid}/export_redis/{type}','SubscriberController@export_from_chart'); // funkcija is campaign overview chart eksportuoja delivered emailus
    Route::get('campaigns/counter/{uid}','CampaignController@old_counter'); // JSON Endpoint for campaign counters data retrieval
    Route::get('campaigns/counter','CampaignController@counter'); // New endpoint for all campaigns counters
    Route::get('campaigns/simulation/{uid}','CampaignController@simulation_status'); // JSON Endpoint of camopaign simulation status
    Route::get('campaigns/quick-view', 'CampaignController@quickView'); // quickview of campaign in campaign overview
    Route::get('campaigns/{uid}/chart24h', 'CampaignController@chart24h'); // chart24 in campaign overview
    Route::get('campaigns/{uid}/chart', 'CampaignController@chart'); // chart in campaign overview
    Route::get('campaigns/{uid}/platforms', 'CampaignController@chartPlatforms'); // platforms used in campaign overview
    Route::get('campaigns/{uid}/chart/countries/open', 'CampaignController@chartCountry'); // country chart in campaign overview
    Route::get('campaigns/{uid}/chart/countries/click', 'CampaignController@chartClickCountry'); // country chart by clicks in campaign overview
    Route::get('campaigns/{uid}/overview', 'CampaignController@overview'); // campaign overview
    // overview for now will be splitted in several pages that will load on button press in each section
    Route::get('campaigns/{uid}/overview_chart', 'CampaignController@overview_chart');
    Route::get('campaigns/{uid}/overview_platforms', 'CampaignController@overview_platforms');
    Route::get('campaigns/{uid}/overview_open_click_rate', 'CampaignController@overview_open_click_rate');
    Route::get('campaigns/{uid}/overview_count_boxes', 'CampaignController@overview_count_boxes');
    Route::get('campaigns/{uid}/overview_24h_chart', 'CampaignController@overview_24h_chart');
    Route::get('campaigns/{uid}/overview_top_link', 'CampaignController@overview_top_link');
    Route::get('campaigns/{uid}/overview_click_country', 'CampaignController@overview_click_country');
    Route::get('campaigns/{uid}/overview_open_country', 'CampaignController@overview_open_country');
    Route::get('campaigns/{uid}/overview_open_location', 'CampaignController@overview_open_location');
    Route::get('campaigns/{uid}/overview_mta_openers','CampaignController@overview_mta_openers');

    Route::get('campaigns/{uid}/links', 'CampaignController@links'); // links clicked in campaign
    Route::get('campaigns/sort', 'CampaignController@sort'); // sort feature of the campaigns
    Route::get('campaigns/listing/{page?}', 'CampaignController@listing'); // campaigns listing (pagination feature)
    Route::get('campaigns/{uid}/recipients', 'CampaignController@recipients'); // recipients of campaign
    Route::post('campaigns/{uid}/recipients', 'CampaignController@recipients'); // set recipients of campaign
    Route::get('campaigns/{uid}/setup', 'CampaignController@setup'); // setup stage of campaign
    Route::post('campaigns/{uid}/setup', 'CampaignController@setup'); // post setup parameters of campaign
    Route::get('campaigns/{uid}/template', 'CampaignController@template'); // choose template for campaign
    Route::post('campaigns/{uid}/template', 'CampaignController@template'); // set template for campaign
    Route::get('campaigns/{uid}/template/select', 'CampaignController@templateSelect'); // select template from saved templates for campaign
    Route::get('campaigns/{uid}/template/choose/{template_uid}', 'CampaignController@templateChoose'); // list templates
    Route::get('campaigns/{uid}/template/preview', 'CampaignController@templatePreview'); // template preview
    Route::get('campaigns/{uid}/template/iframe', 'CampaignController@templateIframe'); // template iframe
    Route::get('campaigns/{uid}/template/build/{style}', 'CampaignController@templateBuild'); // template building
    Route::get('campaigns/{uid}/template/rebuild', 'CampaignController@templateRebuild'); // template rebuilding
    Route::get('campaigns/{uid}/schedule', 'CampaignController@schedule'); // schedule the campaign
    Route::post('campaigns/{uid}/schedule', 'CampaignController@schedule'); // set schedule time in campaign
    Route::get('campaigns/{uid}/confirm', 'CampaignController@confirm'); // confirmation page in campaign setup
    Route::post('campaigns/{uid}/confirm', 'CampaignController@confirm'); // submit confirmation page in campaign setup, start campaign
    Route::get('campaigns/delete', 'CampaignController@delete'); // delete the campaign
    Route::get('campaigns/archive', 'CampaignController@archive'); // archive the campaign
    Route::get('campaigns/unarchive', 'CampaignController@unarchive'); // unarchive the campaign from archive listing
    Route::get('campaigns/select2', 'CampaignController@select2'); // select2 ???
    Route::get('campaigns/pause', 'CampaignController@pause'); // pause the campaign
    Route::get('campaigns/masscopy', 'CampaignController@copymass'); // copy more than 1 campaign at the time
    Route::get('campaigns/test/{uid}','CampaignController@testbackgroundqueue'); // testing the background queue (DEPRECATED)
    Route::get('campaigns/restartbackground','CampaignController@RestartBackground'); // restart background senders for the campaign
    Route::get('campaign/doretrycampaign','CampaignController@DoRetryBackGroundCampaign'); // use resend technique for the campaign
    Route::get('campaigns/restart', 'CampaignController@restart'); // restart the campaign
    Route::resource('campaigns', 'CampaignController'); // campaigns resouces

    // this is temporary solution
    Route::get('archive','ArchiveController@index');
    Route::get('campaigns/{uid}/edit', 'CampaignController@edit');
    Route::patch('campaigns/{uid}/update', 'CampaignController@update');

    Route::get('customers/login-back', 'CustomerController@loginBack');

    Route::get('users/login-back', 'UserController@loginBack');

    // System job - Nogui
    Route::post('systems/jobs/cancel', 'SystemJobController@cancel');
    Route::get('systems/jobs/{type}/listing', 'SystemJobController@listing');
    Route::get('systems/jobs/delete', 'SystemJobController@delete');
    Route::get('systems/jobs/{id}/download/log', 'SystemJobController@downloadLog');
    Route::get('systems/jobs/{id}/download/csv', 'SystemJobController@downloadCsv');

    // Automation - DEPRECATED
    Route::get('automations/{uid}/list-segment-form', 'AutomationController@listSegmentForm');
    Route::patch('automations/disable', 'AutomationController@disable');
    Route::patch('automations/enable', 'AutomationController@enable');
    Route::get('automations/{uid}/overview/emails/list', 'AutomationController@overviewCampaignsList');
    Route::get('automations/{uid}/overview/emails', 'AutomationController@overviewCampaigns');
    Route::get('automations/{uid}/overview/workflow', 'AutomationController@overviewWorkflow');
    Route::post('automations/{uid}/confirm', 'AutomationController@confirm');
    Route::get('automations/{uid}/confirm', 'AutomationController@confirm');
    Route::delete('automations/delete', 'AutomationController@delete');
    Route::get('automations/{uid}/auto-event/form', 'AutomationController@nextEventForm');
    Route::post('automations/{uid}/workflow', 'AutomationController@workflow');
    Route::get('automations/{uid}/workflow', 'AutomationController@workflow');
    Route::get('automations/{uid}/custom-criteria/form', 'AutomationController@criteriaForm');
    Route::post('automations/{uid}/trigger', 'AutomationController@trigger');
    Route::get('automations/{uid}/trigger', 'AutomationController@trigger');
    Route::get('automations/sort', 'AutomationController@sort');
    Route::get('automations/listing/{page?}', 'AutomationController@listing');
    Route::resource('automations', 'AutomationController');

    // Auto event - DEPRECATED
    Route::get('auto-events/{uid}/emails/{campaign_uid}/template', 'AutoEventController@template'); //
    Route::post('auto-events/{uid}/emails/{campaign_uid}/template', 'AutoEventController@template'); //
    Route::get('auto-events/{uid}/emails/{campaign_uid}/template/select', 'AutoEventController@templateSelect'); //
    Route::get('auto-events/{uid}/emails/{campaign_uid}/template/choose/{template_uid}', 'AutoEventController@templateChoose');
    Route::get('auto-events/{uid}/emails/{campaign_uid}/template/preview', 'AutoEventController@templatePreview'); //
    Route::get('auto-events/{uid}/emails/{campaign_uid}/template/iframe', 'AutoEventController@templateIframe'); //
    Route::get('auto-events/{uid}/emails/{campaign_uid}/template/build/{style}', 'AutoEventController@templateBuild'); //
    Route::get('auto-events/{uid}/emails/{campaign_uid}/template/rebuild', 'AutoEventController@templateRebuild'); //

    Route::patch('auto-events/{uid}/move/up', 'AutoEventController@moveUp');
    Route::patch('auto-events/{uid}/move/down', 'AutoEventController@moveDown');
    Route::patch('auto-events/{uid}/disable', 'AutoEventController@disable');
    Route::patch('auto-events/{uid}/enable', 'AutoEventController@enable');
    Route::patch('auto-events/{uid}/emails/{campaign_uid}/setup', 'AutoEventController@campaignSetup');
    Route::get('auto-events/{uid}/emails/{campaign_uid}/setup', 'AutoEventController@campaignSetup');
    Route::delete('auto-events/{uid}/delete', 'AutoEventController@delete');
    Route::delete('auto-events/campaigns/{uid}/delete', 'AutoEventController@deleteCampaign');
    Route::post('auto-events/{uid}/campaigns/add', 'AutoEventController@addCampaign');
    Route::get('auto-events/{uid}/campaigns', 'AutoEventController@campaigns');
    Route::resource('auto-events', 'AutoEventController');

    // Subscription
    Route::get('subscriptions/{uid}/pay/method/{payment_method_uid}', 'SubscriptionController@selectPaymentMethod');
    Route::get('subscriptions/checkout/paypal/{subscription_uid}', 'PaymentController@paypal');
    Route::get('subscriptions/checkout/{uid}', 'SubscriptionController@checkout');
    Route::get('subscriptions/finish', 'SubscriptionController@finish');
    Route::post('subscriptions/subscription/{plan_uid?}', 'SubscriptionController@subscription');
    Route::get('subscriptions/subscription/{plan_uid?}', 'SubscriptionController@subscription');
    Route::get('subscriptions/preview', 'SubscriptionController@preview');
    Route::get('subscriptions/listing/{page?}', 'SubscriptionController@listing');
    Route::get('subscriptions/sort', 'SubscriptionController@sort');
    Route::delete('subscriptions/delete', 'SubscriptionController@delete');
    Route::resource('subscriptions', 'SubscriptionController');

    // Sending servers
    Route::get('sending_servers/export_servers','SendingServerController@export_servers'); // exports sending servers to a file
    Route::post('sending_servers/{uid}/test', 'SendingServerController@test'); // test deliverability of sending servers
    Route::get('sending_servers/{uid}/test', 'SendingServerController@test'); // get the deliverability test form
    Route::post('sending_servers/changedns', 'SendingServerController@changedns'); // change dns from sending servers page
    Route::get('sending_servers/changedns', 'SendingServerController@changedns'); // change dns from sending servers page
    Route::get('sending_servers/select', 'SendingServerController@select'); // server selection
    Route::get('sending_servers/listing/{page?}', 'SendingServerController@listing'); // sending servers listing
    Route::get('sending_servers/sort', 'SendingServerController@sort'); // sending servers sorting
    Route::get('sending_servers/delete', 'SendingServerController@delete'); // delete sending server
    Route::get('sending_servers/disable', 'SendingServerController@disable'); // disable sending server
    Route::get('sending_servers/enable', 'SendingServerController@enable'); // enable sending server
    Route::get('sending_servers/move','SendingServerController@moveservers'); // move servers
    Route::resource('sending_servers', 'SendingServerController');
    Route::get('sending_servers/create/{type}', 'SendingServerController@create'); // create sending server
    Route::post('sending_servers/create/{type}', 'SendingServerController@store'); // save sending server
    Route::get('sending_servers/{id}/edit/{type}', 'SendingServerController@edit'); // edit sending server
    Route::patch('sending_servers/{id}/update/{type}', 'SendingServerController@update'); // update sending server from edit sending server page

    // Sending domain
    Route::get('sending_domains/listing/{page?}', 'SendingDomainController@listing');
    Route::get('sending_domains/sort', 'SendingDomainController@sort');
    Route::get('sending_domains/delete', 'SendingDomainController@delete');
    Route::resource('sending_domains', 'SendingDomainController');

    // Payment
    Route::get('payments/paddle/card/{subscription_uid}', 'PaymentController@paddle_card');
    Route::post('payments/billing-information/{subscription_uid}', 'PaymentController@billingInformation');
    Route::get('payments/billing-information/{subscription_uid}', 'PaymentController@billingInformation');
    Route::post('payments/stripe/credit-card/{subscription_uid}', 'PaymentController@stripe_credit_card');
    Route::get('payments/stripe/credit-card/{subscription_uid}', 'PaymentController@stripe_credit_card');
    Route::post('payments/braintree/paypal/{subscription_uid}', 'PaymentController@braintree_paypal');
    Route::get('payments/braintree/paypal/{subscription_uid}', 'PaymentController@braintree_paypal');
    Route::get('payments/success/{subscription_uid}', 'PaymentController@success');
    Route::post('payments/braintree/credit-card/{subscription_uid}', 'PaymentController@braintree_credit_card');
    Route::get('payments/braintree/credit-card/{subscription_uid}', 'PaymentController@braintree_credit_card');
    Route::get('payments/cash/{subscription_uid}', 'PaymentController@cash');
    Route::post('payments/paypal/{subscription_uid}', 'PaymentController@paypal');
    Route::get('payments/paypal/{subscription_uid}', 'PaymentController@paypal');

    // Email verification servers
    Route::get('email_verification_servers/options', 'EmailVerificationServerController@options');
    Route::get('email_verification_servers/listing/{page?}', 'EmailVerificationServerController@listing');
    Route::get('email_verification_servers/sort', 'EmailVerificationServerController@sort');
    Route::get('email_verification_servers/delete', 'EmailVerificationServerController@delete');
    Route::get('email_verification_servers/disable', 'EmailVerificationServerController@disable');
    Route::get('email_verification_servers/enable', 'EmailVerificationServerController@enable');
    Route::resource('email_verification_servers', 'EmailVerificationServerController');

    // Blacklists
    Route::post('blacklists/job/{system_job_id}/cancel', 'BlacklistController@cancel');
    Route::get('blacklists/import/process', 'BlacklistController@importProcess');
    Route::post('blacklists/import', 'BlacklistController@import');
    Route::get('blacklists/import', 'BlacklistController@import');
    Route::get('blacklists/listing/{page?}', 'BlacklistController@listing');
    Route::get('blacklists/delete', 'BlacklistController@delete');
    Route::get('blacklists/deleteall','BlacklistController@deleteall');
 //   Route::get('blacklists/populate_sql_from_redis','BlacklistController@populate_sql_from_redis');
    Route::resource('blacklists', 'BlacklistController');
});

// ADMIN AREA
//Route::group(['namespace' => 'Admin', 'middleware' => ['not_installed', 'backend']], function () {
   Route::get('admin', 'Admin\HomeController@index');
  //  Route::get('admin/docs/api/v1', 'ApiController@doc');

    // User
    Route::get('admin/users/switch/{uid}', 'UserController@switch_user');
    Route::get('admin/users/listing/{page?}', 'UserController@listing');
    Route::get('admin/users/sort', 'UserController@sort');
    Route::get('admin/users/delete', 'UserController@delete');
    Route::resource('admin/users', 'UserController');

    // Template
    Route::post('admin/templates/{uid}/copy', 'TemplateController@copy');
    Route::get('admin/templates/{uid}/copy', 'TemplateController@copy');
    Route::get('admin/templates/sort', 'TemplateController@sort');
    Route::get('admin/templates/{uid}/image', 'TemplateController@image');
    Route::post('admin/templates/{uid}/saveImage', 'TemplateController@saveImage');
    Route::get('admin/templates/{uid}/preview', 'TemplateController@preview');
    Route::get('admin/templates/listing/{page?}', 'TemplateController@listing');
    Route::get('admin/templates/upload', 'TemplateController@upload');
    Route::post('admin/templates/upload', 'TemplateController@upload');
    Route::get('admin/templates/delete', 'TemplateController@delete');
    Route::get('admin/templates/build/select', 'TemplateController@buildSelect');
    Route::get('admin/templates/build/{style?}', 'TemplateController@build');
    Route::get('admin/templates/{uid}/rebuild', 'TemplateController@rebuild');
    Route::resource('admin/templates', 'TemplateController');
    Route::get('admin/templates/{uid}/edit', 'TemplateController@edit');
    Route::patch('admin/templates/{uid}/update', 'TemplateController@update');

    // Layout
    Route::get('admin/layouts/listing/{page?}', 'Admin\LayoutController@listing');
    Route::get('admin/layouts/sort', 'Admin\LayoutController@sort');
    Route::resource('admin/layouts', 'Admin\LayoutController');

    // Sending servers
    Route::post('admin/sending_servers/{uid}/test', 'Admin\SendingServerController@test');
    Route::get('admin/sending_servers/{uid}/test', 'Admin\SendingServerController@test');
    Route::get('admin/sending_servers/select', 'Admin\SendingServerController@select');
    Route::get('admin/sending_servers/listing/{page?}', 'Admin\SendingServerController@listing');
    Route::get('admin/sending_servers/sort', 'Admin\SendingServerController@sort');
    Route::get('admin/sending_servers/delete', 'Admin\SendingServerController@delete');
    Route::get('admin/sending_servers/disable', 'Admin\SendingServerController@disable');
    Route::get('admin/sending_servers/enable', 'Admin\SendingServerController@enable');
    Route::resource('admin/sending_servers', 'Admin\SendingServerController');
    Route::get('admin/sending_servers/create/{type}', 'Admin\SendingServerController@create');
    Route::post('admin/sending_servers/create/{type}', 'Admin\SendingServerController@store');
    Route::get('admin/sending_servers/{id}/edit/{type}', 'Admin\SendingServerController@edit');
    Route::patch('admin/sending_servers/{id}/update/{type}', 'Admin\SendingServerController@update');

    // Bounce handlers
    Route::post('admin/bounce_handlers/{uid}/test', 'Admin\BounceHandlerController@test');
    Route::get('admin/bounce_handlers/listing/{page?}', 'Admin\BounceHandlerController@listing');
    Route::get('admin/bounce_handlers/sort', 'Admin\BounceHandlerController@sort');
    Route::get('admin/bounce_handlers/delete', 'Admin\BounceHandlerController@delete');
    Route::resource('admin/bounce_handlers', 'Admin\BounceHandlerController');

    // Feedback Loop handlers
    Route::post('admin/feedback_loop_handlers/{uid}/test', 'Admin\FeedbackLoopHandlerController@test');
    Route::get('admin/feedback_loop_handlers/listing/{page?}', 'Admin\FeedbackLoopHandlerController@listing');
    Route::get('admin/feedback_loop_handlers/sort', 'Admin\FeedbackLoopHandlerController@sort');
    Route::get('admin/feedback_loop_handlers/delete', 'Admin\FeedbackLoopHandlerController@delete');
    Route::resource('admin/feedback_loop_handlers', 'Admin\FeedbackLoopHandlerController');

    // Sending domain
    Route::get('admin/sending_domains/listing/{page?}', 'SendingDomainController@listing');
    Route::get('admin/sending_domains/sort', 'SendingDomainController@sort');
    Route::get('admin/sending_domains/delete', 'SendingDomainController@delete');
    Route::resource('admin/sending_domains', 'SendingDomainController');

    // Language
    Route::get('admin/languages/delete/confirm', 'Admin\LanguageController@deleteConfirm');
    Route::get('admin/languages/listing/{page?}', 'Admin\LanguageController@listing');
    Route::get('admin/languages/delete', 'Admin\LanguageController@delete');
    Route::get('admin/languages/{id}/translate/{file}', 'Admin\LanguageController@translate');
    Route::post('admin/languages/{id}/translate/{file}', 'Admin\LanguageController@translate');
    Route::get('admin/languages/disable', 'Admin\LanguageController@disable');
    Route::get('admin/languages/enable', 'Admin\LanguageController@enable');
    Route::get('admin/languages/{id}/download', 'Admin\LanguageController@download');
    Route::get('admin/languages/{id}/upload', 'Admin\LanguageController@upload');
    Route::post('admin/languages/{id}/upload', 'Admin\LanguageController@upload');
    Route::resource('admin/languages', 'Admin\LanguageController');

    // Settings
    Route::post('admin/settings/upgrade/cancel', 'Admin\SettingController@cancelUpgrade');
    Route::post('admin/settings/upgrade', 'Admin\SettingController@doUpgrade');
    Route::post('admin/settings/upgrade/upload', 'Admin\SettingController@uploadApplicationPatch');
    Route::get('settings/upgrade', 'Admin\SettingController@upgrade');
    Route::post('settings/license', 'Admin\SettingController@license');
    Route::get('settings/license', 'Admin\SettingController@license');
    Route::get('settings/mailer', 'Admin\SettingController@mailer');
    Route::post('settings/mailer', 'Admin\SettingController@mailer');
    Route::get('settings/cronjob', 'Admin\SettingController@cronjob');
    Route::post('settings/cronjob', 'Admin\SettingController@cronjob');
    Route::get('settings/urls', 'Admin\SettingController@urls');
    Route::get('settings/customurls','Admin\SettingController@customurls');
    // openpart, add, atidaryti
    Route::get('settings/setcustomurl/{type}/{action}/{item}','Admin\SettingController@setcustomurl');
    Route::get('admin/settings/sending', 'Admin\SettingController@sending');
    Route::post('admin/settings/sending', 'Admin\SettingController@sending');
    Route::get('settings/general', 'Admin\SettingController@general');
    Route::post('settings/general', 'Admin\SettingController@general');
    Route::get('settings/hardbounces','Admin\SettingController@hardbounces');
    Route::post('settings/hardbounces','Admin\SettingController@hardbounces');
Route::get('settings/controller','Admin\SettingController@controller');
Route::post('settings/controller','Admin\SettingController@controller');
Route::get('settings/maintenance','Admin\SettingController@maintenance');
Route::post('settings/maintenance','Admin\SettingController@maintenance');

Route::get('settings/taskrunner','Admin\SettingController@taskrunner');
Route::post('settings/taskrunner','Admin\SettingController@taskrunner');
Route::post('settings/taskrunner_respond','Admin\SettingController@taskrunner_respond');

Route::get('settings/storage','Admin\SettingController@storage');
Route::post('settings/storage','Admin\SettingController@storage');
Route::get('settings/proxies','Admin\SettingController@proxies');
Route::post('settings/proxies','Admin\SettingController@proxies');
Route::get('settings/checkstorage_availability','Admin\SettingController@checkstorage_availability');


Route::get('settings/mta','Admin\SettingController@mta');
Route::post('settings/mta','Admin\SettingController@mta');
Route::get('settings/mta_load_data','Admin\SettingController@load_mta_servers');
Route::get('settings/checkmta','Admin\SettingController@checkmta');
Route::get('settings/checkpmta','Admin\SettingController@checkpmta');
Route::get('settings/delfrommta/{host}','Admin\SettingController@delfrommta');


Route::get('settings/amazonses','Admin\SettingController@amazonses');
Route::post('settings/amazonses','Admin\SettingController@amazonses');


Route::get('settings/rotator_perk','Admin\SettingController@rotator_perk');
Route::post('settings/rotator_perk','Admin\SettingController@rotator_perk');
Route::get('settings/rotator','Admin\SettingController@rotator');
Route::post('settings/rotator','Admin\SettingController@rotator');
Route::get('settings/rotator_reset','Admin\SettingController@rotator_reset');
Route::get('settings/rotator_perk_reset','Admin\SettingController@rotator_perk_reset');
Route::get('settings/monitoring','Admin\SettingController@monitoring');
Route::get('settings/DNS','Admin\SettingController@dns');
Route::post('settings/DNS','Admin\SettingController@dns');
Route::get('settings/debug','Admin\SettingController@debug');
Route::geT('settings/readlog/{log}','Admin\SettingController@readlog');
Route::get('settings/debug2','Admin\SettingController@debug2');
Route::get('settings/servers','Admin\SettingController@servers');
Route::post('settings/servers','Admin\SettingController@servers');
Route::post('settings/initialize_dns','Admin\SettingController@initialize_dns');
Route::post('settings/setup_server','Admin\SettingController@setup_server');
Route::post('settings/inject_server','Admin\SettingController@inject_server');
Route::post('settings/test_server','Admin\SettingController@test_server');
Route::post('settings/setup_ips','Admin\SettingController@setup_ips');

Route::get('settings/realtime_camp/{uid}','Admin\SettingController@ViewCampaignProcess');
Route::get('settings/realtime_redis','Admin\SettingController@ViewRedisQueue');



// FIXME
    Route::get('settings/warmup','Admin\SettingController@warmup');
    Route::get('settings/warmup_settings','Admin\SettingController@warmup_settings');
    Route::post('settings/warmup_settings','Admin\SettingController@warmup_settings');
    Route::post('settings/warmup_server_production','Admin\SettingController@warmup_server_production');
    Route::post('settings/warmup_del','Admin\SettingController@warmup_del');
    Route::post('settings/warmup_test_test','Admin\SettingController@warmup_test_test');

    Route::get('settings/speed', 'Admin\SettingController@speed');
    Route::get('settings/findbyuid','Admin\SettingController@FindContactByUid');
    Route::get('settings/ver','Admin\SettingController@ver');
    Route::get('settings/finduid/{uid}','Admin\SettingController@FindContactUidFunc');
    Route::post('settings/setserverinfo','Admin\SettingController@SetServerInfo');
    Route::post('settings/speed', 'Admin\SettingController@speed');
    Route::get('admin/settings/logs', 'Admin\SettingController@logs');
    Route::get('log', 'Admin\SettingController@download_log');
    Route::get('settings/{tab?}', 'Admin\SettingController@index');
    Route::post('settings', 'Admin\SettingController@index');
    Route::get('update-urls/{trackurl}', 'Admin\SettingController@updateUrls');
    Route::get('update-proxy/{proxyip}','Admin\SettingController@updateProxy');
    Route::get('update-cf-mass/{ip}','Admin\SettingController@updatecfmass');
    Route::get('update-cfa-mass/{ip}','Admin\SettingController@updatecfamass');
    Route::get('delete-domain/{domain}','Admin\SettingController@delete_domain');

    // Controller log
Route::get('admin/controller_log', 'Admin\ControllerLogController@index');
   Route::get('admin/controller_log/listing', 'Admin\ControllerLogController@listing');

    // Tracking log
    Route::get('admin/tracking_log', 'Admin\TrackingLogController@index');
    Route::get('admin/tracking_log/listing', 'Admin\TrackingLogController@listing');

    // Feedback log
    Route::get('admin/bounce_log', 'Admin\BounceLogController@index');
    Route::get('admin/bounce_log/listing', 'Admin\BounceLogController@listing');

    // Open log
    Route::get('admin/open_log', 'Admin\OpenLogController@index');
    Route::get('admin/open_log/listing', 'Admin\OpenLogController@listing');

    // Click log
    Route::get('admin/click_log', 'Admin\ClickLogController@index');
    Route::get('admin/click_log/listing', 'Admin\ClickLogController@listing');

    // Feedback log
    Route::get('admin/feedback_log', 'Admin\FeedbackLogController@index');
    Route::get('admin/feedback_log/listing', 'Admin\FeedbackLogController@listing');

    // Unsubscribe log
    Route::get('admin/unsubscribe_log', 'Admin\UnsubscribeLogController@index');
    Route::get('admin/unsubscribe_log/listing', 'Admin\UnsubscribeLogController@listing');

    // Server groups
//Route::get('admin/servgroups/import', 'ServGroupController@import');
Route::post('admin/servgroups/post_data', 'ServGroupController@post_data');
Route::get('admin/servgroups', 'ServGroupController@index');
Route::get('admin/servgroups/listing', 'ServGroupController@listing');
Route::get('admin/servgroups/delete', 'ServGroupController@delete');
Route::get('admin/servgroups/create','ServGroupController@item_add');
Route::post('admin/servgroups/create','ServGroupController@item_add');


// bulk imap checker
Route::get('bulkchecker', 'BulkController@index');
Route::post('bulkchecker/check','BulkController@check');
Route::post('bulkchecker/submit','BulkController@submit');

    // Blacklist
    Route::post('admin/blacklists/job/{system_job_id}/cancel', 'BlacklistController@cancel');
    Route::get('admin/blacklists/import/process', 'BlacklistController@importProcess');
    Route::post('admin/blacklists/import', 'BlacklistController@import');
    Route::get('admin/blacklists/import', 'BlacklistController@import');
    Route::get('admin/blacklists/export','BlacklistController@exportblacklist');
    Route::get('admin/blacklist', 'BlacklistController@index');
    Route::get('admin/blacklist/listing', 'BlacklistController@listing');
    Route::get('admin/blacklist/delete', 'BlacklistController@delete');
    Route::get('admin/blacklist/create','BlacklistController@item_add');
    Route::post('admin/blacklist/create','BlacklistController@item_add');

    // Domains blacklist
Route::get('admin/blacklist_domains/import', 'Blacklist_domainsController@import');
Route::post('admin/blacklist_domains/post_data', 'Blacklist_domainsController@post_data');
Route::get('admin/blacklist_domains', 'Blacklist_domainsController@index');
Route::get('admin/blacklist_domains/listing', 'Blacklist_domainsController@listing');
Route::get('admin/blacklist_domains/delete', 'Blacklist_domainsController@delete');
Route::get('admin/blacklist_domains/create','Blacklist_domainsController@item_add');
Route::post('admin/blacklist_domains/create','Blacklist_domainsController@item_add');


// Names blacklist
Route::get('admin/blacklists_names/import', 'Blacklist_namesController@import');
Route::post('admin/blacklists_names/post_data', 'Blacklist_namesController@post_data');
Route::get('admin/blacklist_names', 'Blacklist_namesController@index');
Route::get('admin/blacklist_names/listing', 'Blacklist_namesController@listing');
Route::get('admin/blacklist_names/delete', 'Blacklist_namesController@delete');
Route::get('admin/blacklist_names/create','Blacklist_namesController@item_add');
Route::post('admin/blacklist_names/create','Blacklist_namesController@item_add');


// Abuse blacklist
Route::get('admin/blacklists_abuse/import', 'Blacklist_abuseController@import');
Route::post('admin/blacklists_abuse/post_data', 'Blacklist_abuseController@post_data');
Route::get('admin/blacklist_abuse', 'Blacklist_abuseController@index');
Route::get('admin/blacklist_abuse/listing', 'Blacklist_abuseController@listing');
Route::get('admin/blacklist_abuse/delete', 'Blacklist_abuseController@delete');
Route::get('admin/blacklist_abuse/create','Blacklist_abuseController@item_add');
Route::post('admin/blacklist_abuse/create','Blacklist_abuseController@item_add');

// MX blacklist
Route::get('admin/blacklists_mx/import', 'Blacklist_mxController@import');
Route::post('admin/blacklists_mx/post_data', 'Blacklist_mxController@post_data');
Route::get('admin/blacklist_mx', 'Blacklist_mxController@index');
Route::get('admin/blacklist_mx/listing', 'Blacklist_mxController@listing');
Route::get('admin/blacklist_mx/delete', 'Blacklist_mxController@delete');
Route::get('admin/blacklist_mx/create','Blacklist_mxController@item_add');
Route::post('admin/blacklist_mx/create','Blacklist_mxController@item_add');


    // Customer Group
    Route::get('admin/customer_groups/listing/{page?}', 'Admin\CustomerGroupController@listing');
    Route::get('admin/customer_groups/sort', 'Admin\CustomerGroupController@sort');
    Route::get('admin/customer_groups/delete', 'Admin\CustomerGroupController@delete');
    Route::resource('admin/customer_groups', 'Admin\CustomerGroupController');

    // Customer
    Route::get('admin/customers/{uid}/su-account', 'Admin\CustomerController@subAccount');
    Route::post('admin/customers/{uid}/contact', 'Admin\CustomerController@contact');
    Route::get('admin/customers/{id}/contact', 'Admin\CustomerController@contact');
    Route::get('admin/customers/growthChart', 'Admin\CustomerController@growthChart');
    Route::get('admin/customers/{id}/subscriptions', 'Admin\CustomerController@subscriptions');
    Route::get('admin/customers/select2', 'Admin\CustomerController@select2');
    Route::get('admin/customers/login-as/{uid}', 'Admin\CustomerController@loginAs');
    Route::get('admin/customers/listing/{page?}', 'Admin\CustomerController@listing');
    Route::get('admin/customers/sort', 'Admin\CustomerController@sort');
    Route::get('admin/customers/delete', 'Admin\CustomerController@delete');
    Route::get('admin/customers/disable', 'Admin\CustomerController@disable');
    Route::get('admin/customers/enable', 'Admin\CustomerController@enable');
    Route::resource('admin/customers', 'Admin\CustomerController');

    // Admin Group
    Route::get('admin/admin_groups/listing/{page?}', 'Admin\AdminGroupController@listing');
    Route::get('admin/admin_groups/sort', 'Admin\AdminGroupController@sort');
    Route::get('admin/admin_groups/delete', 'Admin\AdminGroupController@delete');
    Route::resource('admin/admin_groups', 'Admin\AdminGroupController');

    // Admin
    Route::get('admin/admins/login-as/{uid}', 'Admin\AdminController@loginAs');
    Route::get('admin/admins/listing/{page?}', 'Admin\AdminController@listing');
    Route::get('admin/admins/sort', 'Admin\AdminController@sort');
    Route::get('admin/admins/delete', 'Admin\AdminController@delete');
    Route::get('admin/admins/disable', 'Admin\AdminController@disable');
    Route::get('admin/admins/enable', 'Admin\AdminController@enable');
    Route::get('admin/admins/login-back', 'Admin\AdminController@loginBack');
    Route::resource('admin/admins', 'Admin\AdminController');


    // Account
    Route::get('admin/account/api/renew', 'Admin\AccountController@renewToken');
    Route::get('admin/account/api', 'Admin\AccountController@api');
    Route::get('admin/account/profile', 'Admin\AccountController@profile');
    Route::post('admin/account/profile', 'Admin\AccountController@profile');
    Route::get('admin/account/contact', 'Admin\AccountController@contact');
    Route::post('admin/account/contact', 'Admin\AccountController@contact');

    // Plan
    Route::get('admin/plans/pieChart', 'PlanController@pieChart');
    Route::get('admin/plans/delete/confirm', 'PlanController@deleteConfirm');
    Route::get('admin/plans/select2', 'PlanController@select2');
    Route::get('admin/plans/listing/{page?}', 'PlanController@listing');
    Route::get('admin/plans/sort', 'PlanController@sort');
    Route::get('admin/plans/delete', 'PlanController@delete');
    Route::get('admin/plans/disable', 'PlanController@disable');
    Route::get('admin/plans/enable', 'PlanController@enable');
    Route::resource('admin/plans', 'PlanController');

    // Currency
    Route::get('admin/currencies/select2', 'Admin\CurrencyController@select2');
    Route::get('admin/currencies/listing/{page?}', 'Admin\CurrencyController@listing');
    Route::get('admin/currencies/sort', 'Admin\CurrencyController@sort');
    Route::get('admin/currencies/delete', 'Admin\CurrencyController@delete');
    Route::get('admin/currencies/disable', 'Admin\CurrencyController@disable');
    Route::get('admin/currencies/enable', 'Admin\CurrencyController@enable');
    Route::resource('admin/currencies', 'Admin\CurrencyController');

    // Subscription
    Route::patch('admin/subscriptions/unpaid', 'SubscriptionController@unpaid');
    Route::patch('admin/subscriptions/paid', 'SubscriptionController@paid');
    Route::get('admin/subscriptions/{uid}/payments', 'SubscriptionController@payments');
    Route::patch('admin/subscriptions/enable', 'SubscriptionController@enable');
    Route::patch('admin/subscriptions/disable', 'SubscriptionController@disable');
    Route::get('admin/subscriptions/preview', 'SubscriptionController@preview');
    Route::get('admin/subscriptions/listing/{page?}', 'SubscriptionController@listing');
    Route::get('admin/subscriptions/sort', 'SubscriptionController@sort');
    Route::delete('admin/subscriptions/delete', 'SubscriptionController@delete');
    Route::resource('admin/subscriptions', 'SubscriptionController');

    // Payment method
    Route::get('admin/payment_methods/braintree/merchant-accounts/select/{uid?}', 'Admin\PaymentMethodController@braintreeMerchantAccountSelect');
    Route::get('admin/payment_methods/options/{uid?}', 'Admin\PaymentMethodController@options');
    Route::get('admin/payment_methods/select2', 'Admin\PaymentMethodController@select2');
    Route::get('admin/payment_methods/listing/{page?}', 'Admin\PaymentMethodController@listing');
    Route::get('admin/payment_methods/sort', 'Admin\PaymentMethodController@sort');
    Route::get('admin/payment_methods/delete', 'Admin\PaymentMethodController@delete');
    Route::get('admin/payment_methods/disable', 'Admin\PaymentMethodController@disable');
    Route::get('admin/payment_methods/enable', 'Admin\PaymentMethodController@enable');
    Route::resource('admin/payment_methods', 'Admin\PaymentMethodController');

    // Email verification servers - No gui
    Route::get('admin/email_verification_servers/options', 'EmailVerificationServerController@options');
    Route::get('admin/email_verification_servers/listing/{page?}', 'EmailVerificationServerController@listing');
    Route::get('admin/email_verification_servers/sort', 'EmailVerificationServerController@sort');
    Route::get('admin/email_verification_servers/delete', 'EmailVerificationServerController@delete');
    Route::get('admin/email_verification_servers/disable', 'EmailVerificationServerController@disable');
    Route::get('admin/email_verification_servers/enable', 'EmailVerificationServerController@enable');
    Route::resource('admin/email_verification_servers', 'EmailVerificationServerController');

    // Sub account
    Route::get('admin/sub_accounts/{uid}/delete/confirm', 'Admin\SubAccountController@deleteConfirm');
    Route::delete('admin/sub_accounts/{uid}/delete', 'Admin\SubAccountController@delete');
    Route::get('admin/sub_accounts/listing/{page?}', 'Admin\SubAccountController@listing');
    Route::resource('admin/sub_accounts', 'Admin\SubAccountController');

    // Just dummy function to test if api is working, returns request object to MailLog - No gui
    Route::get('postapi','Api\ApiController@getapi');
    Route::post('postapi','Api\ApiController@getapi');
    // Reports abuse from domain.com/report form
    Route::get('report','CampaignController@reportabuse');
    Route::post('report','CampaignController@reportabuse');

    // new tracking
   // Route::get('click_new', 'CampaignController@click_new');



// API
Route::group(['namespace' => 'Api', 'prefix' => 'api', 'middleware' => 'api'], function () {
    Route::post('postserver','ApiController@postserver');
    Route::get('getip/{ip}','ApiController@getip');
    Route::post('listdomains','ApiController@list_domains');
    Route::post('initializedns','ApiController@initialize_dns');
    Route::post('uninitializedns','ApiController@uninitialize_dns');
    Route::post('updatespf','ApiController@updatespf');
    Route::post('testdelivery','ApiController@test_delivery');
    Route::post('deleteserver','ApiController@delete_server');
    Route::post('replaceserver','ApiController@replace_server');
    Route::post('conversion','ApiController@conversion');
    Route::get('conversion','ApiController@conversion');
    Route::post('blacklist','ApiController@blacklist');
    Route::post('simulation-campaign','ApiController@SimulationTestCampaign');
    Route::post('getexternaltracking','ApiController@getexternaltracking');
    Route::post('checkserverexists','ApiController@checkifserverexists');
});
Route::get('/{urlas}', 'CampaignController@click_new')->where('urlas', '[a-z]+');

//});
