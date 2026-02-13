

            <h3 class="mt-10"><i class="icon-stats-dots"></i> Platforms</h3>
            <div class="row">
                <div class="col-md-6">
                    @if (!$campaign->openCount())
                        <div class="empty-chart-pie">
                            <div class="empty-list">
                                <i class="icon-file-text2"></i>
                                <span class="line-1">
                                    {{ trans('messages.log_empty_line_1') }}
                                </span>
                            </div>
                        </div>
                    @else
                        <div class="stat-table">
                            @foreach ($campaign->topPlatforms(7)->get() as $platform)
                                <div class="stat-row">
                                    <div class="pull-right num">
                                        {{ $platform->aggregate }}
                                    </div>
                                    <p class="text-muted">{{ $agent_api->get_operating_system($platform->user_agent) }}</p>
                                </div>
                            @endforeach 
                        </div>

                    @endif
                </div>
                <div class="col-md-6">
                    @if ($campaign->openCount())
                        <div class="panel panel-flat">
                            <div class="panel-body">
                                <div class="chart-container has-scroll">
                                    <div id="platforms" class="chart has-fixed-height" id="basic_pie_44"  data-url="{{ action('CampaignController@chartPlatforms', $campaign->uid) }}"></div>
                                </div>
                            </div>
                        </div>
                            
                    @else
                        <div class="empty-chart-pie">
                            <div class="empty-list">
                                <i class="icon-file-text2"></i>
                                <span class="line-1">
                                    {{ trans('messages.log_empty_line_1') }}
                                </span>
                            </div>
                        </div>
                    @endif
                </div>                
            </div>