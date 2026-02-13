<?php
use \Acelle\Library\Log as MailLog;

$openpart = Redis::sMembers('openpart');
$clickpart = Redis::sMembers('clickpart');
$sourcepart = Redis::sMembers('sourcepart');



if(!empty($clickpart))
{
    foreach ($clickpart as $page)
    {
        if (Request::segment(3) == $page) {
            Route::get('/{campaigns}/{message_id}/{' . $page . '}/{url}', ['as' => $page, 'uses' => 'CampaignController@click']);
        }
    }
} elseif (Request::segment(3) == "click") {
    Route::get('/{campaigns}/{message_id}/click', ['as' => 'click', 'uses' => 'CampaignController@click']);
}

if(!empty($openpart))
{
    foreach ($openpart as $page)
    {
        if (Request::segment(3) == $page) {
            Route::get('/{campaigns}/{message_id}/{' . $page . '}', ['as' => $page, 'uses' => 'CampaignController@open']);
        }
    }
} elseif (Request::segment(3) == "open") {
    Route::get('/{campaigns}/{message_id}/open', ['as' => 'click', 'uses' => 'CampaignController@open']);
}





if(!empty($sourcepart))
{
    foreach ($sourcepart as $page)
    {
        if (Request::segment(1) == $page) {
            Route::get('/{'.$page.'}/{campaign_uid}/{file}', ['as' => $page, 'uses' => 'CampaignController@RedirectSource']);
            //redirect('/source/');

        }
    }
} elseif (Request::segment(1) == "source") {
    Route::get('/source/{campaign_uid}/{file}', ['as' => $page, 'uses' => 'CampaignController@RedirectSource']);
}

Route::get('/{image}', 'CampaignController@open_new')->where('image', '[a-z]+\.(?:jpg|jpeg|png|gif|JPG|JPEG|PNG|GIF)$');

Route::get('/{file}', 'CampaignController@RedirectImage');

//Route::get('/{any}', function ($any) { return "nice"; })->where('any', '.*');

Route::group(['namespace' => 'Api', 'prefix' => 'api'/*, 'middleware' => 'api'*/], function () {
    Route::post('hardbounce/{email}', 'NonDBApiController@hardbounce');
    Route::get('failcampaign/{email}', 'ApiController@failcampaign');
    Route::post('campaign_counter/{uid}', 'NonDBApiController@campaign_counter');
    Route::get('get_data/{uid}', 'NonDBApiController@get_data');
    Route::get('global_data_counts', 'NonDBApiController@global_data_counts');
});

