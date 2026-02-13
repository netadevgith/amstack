                <div class="row">
                    <div class="col-md-3">
                        <div class="panel panel-white bg-teal-400">
                            <div class="panel-body text-center">
                                <h2 class="text-semibold mb-10 mt-0"><a href="{{ action('CampaignController@openLog', $campaign->uid) }}">{{ $campaign->openUniqCount() }}</a></h2>
                                <div class="text-muted">{{ trans('messages.opened') }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="panel panel-white bg-teal-400">
                            <div class="panel-body text-center">
                                <h2 class="text-semibold mb-10 mt-0"><a href="{{ action('CampaignController@clickLog', $campaign->uid) }}">{{ $campaign->clickedEmailsCount() }}</a></h2>
                                <div class="text-muted">{{ trans('messages.clicked') }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="panel panel-white bg-teal-400">
                            <div class="panel-body text-center">
                                <h2 class="text-semibold mb-10 mt-0"><a href="{{ action('CampaignController@bounceLog', $campaign->uid) }}">
                                    @if (Redis::exists($campaign->uid.'_bounced')&&Redis::get($campaign->uid.'_bounced') > 0)
                                        {{ Redis::get($campaign->uid.'_bounced') }}
                                    @else
                                    {{ $campaign->bounceCount() }}
                                    @endif
                                    </a>
                                </h2>
                                <div class="text-muted">{{ trans('messages.bounced') }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="panel panel-white bg-teal-400">
                            <div class="panel-body text-center">
                                <h2 class="text-semibold mb-10 mt-0"><a href="{{ action('CampaignController@unsubscribeLog', $campaign->uid) }}">{{ $campaign->unsubscribeCount() }}</a></h2>
                                <div class="text-muted">{{ trans('messages.unsubscribed') }}</div>
                            </div>
                        </div>
                    </div>
                </div>