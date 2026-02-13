@if ($campaigns->count() > 0)
	<table class="table table-box pml-table"
		   current-page="{{ empty(request()->page) ? 1 : empty(request()->page) }}"
	>
		@foreach ($campaigns as $key => $item)
			<tr id="{{ $item->uid }}_item_row" style="display:none">
				<td width="1%">
					<div class="text-nowrap">
						<div class="checkbox inline">
							<label>
								<input type="checkbox" class="node styled"
									   custom-order="{{ $item->custom_order }}"
									   name="ids[]"
									   value="{{ $item->uid }}"
								/>
							</label>
						</div>
						@if (request()->sort_order == 'custom_order' && empty(request()->keyword))
							<i data-action="move" class="icon icon-more2 list-drag-button"></i>
						@endif
					</div>
				</td>
				<td>
					<h5 class="no-margin text-bold">
						<a class="kq_search" href="{{ action('CampaignController@show', $item->uid) }}">
							{{ $item->name }}
						</a>
					</h5>
					<span class="text-muted">{{ trans('messages.' . $item->type) }}</span>

					@if ($item->readCache('SubscriberCount'))
						<div class="text-semibold" data-popup="tooltip" title="{{ $item->displayRecipients() }}">
							{{ number_with_delimiter($item->readCache('SubscriberCount')) }} {{ trans('messages.recipients') }}
						</div>
					@endif

					@if ($item->status != 'new')
						<span class="text-muted2">{{ trans('messages.run_at') }}: &nbsp;&nbsp;<i class="icon-alarm mr-0"></i> {{ isset($item->run_at) ? Tool::formatDateTime($item->run_at) : "" }}</span>
					@else
						<span class="text-muted2">{{ trans('messages.updated_at') }}: {{ Tool::formatDateTime($item->created_at) }}</span>
					@endif
				</td>
				@if ($item->status != 'new')
					<td class="stat-fix-size-sm">
						<div class="single-stat-box pull-left ml-20">
							<span class="no-margin text-teal-800 stat-num" id="{{ $item->uid }}_progr"></span>
							<div class="progress progress-xxs">
								<div class="progress-bar progress-bar-info" id="{{ $item->uid }}_progrbar" style="width: 0px">
								</div>
							</div>
							<span class="text-semibold text-nowrap" id="{{ $item->uid }}">0</span>
							<span class="text-semibold text-nowrap" id="{{ $item->uid }}_total"> 0 </span>
							<br />
							<span class="text-muted"><div id="{{ $item->uid }}_eta" style="display:none"></div></span>
						</div>
					</td>
					<td class="stat-fix-size-sm">
						<div class="single-stat-box pull-left ml-20">
							<span class="no-margin text-teal-800 stat-num" id="{{ $item->uid }}_progropeners">0%</span>
							<div class="progress progress-xxs">
								<div class="progress-bar progress-bar-info" id="{{ $item->uid }}_progrbaropeners" style="width: 0%">
								</div>
							</div>
							<span class="text-muted">{{ trans('messages.open_rate') }}</span>
						</div>
					</td>
					<td class="stat-fix-size-sm">
						<div class="single-stat-box pull-left ml-20">
							<span class="no-margin text-teal-800 stat-num" id="{{ $item->uid }}_progrclickers">0%</span>
							<div class="progress progress-xxs">
								<div class="progress-bar progress-bar-info" id="{{ $item->uid }}_progrbarclickers" style="width: 0%">
								</div>
							</div>
							<span class="text-muted">{{ trans('messages.click_rate') }}</span>
						</div>
					</td>
				@else
					<td></td>
					<td></td>
					<td></td>
				@endif
				<td>
                                <span class="text-muted2 list-status pull-left" title='{{ $item->status == Acelle\Model\Campaign::STATUS_ERROR ? $item->last_error : '' }}' data-popup='tooltip'>
                                        <span class="label label-flat bg-{{ $item->status }}" id="{{ $item->uid }}_statusas">{{ trans('messages.campaign_status_' . $item->status) }}</span>
                                        <div id="{{ $item->uid }}_last_speed" style="display:none"></div>
                                        <span id="{{ $item->uid }}_bak" style="display:none"></span>
                                        <span id="{{ $item->uid }}_bg" style="display:none"></span>
                                </span>
				</td>
				<td class="text-right text-nowrap">
					{{--@if (\Gate::allows('update', $item))--}}
					@if (\Gate::allows('overview', $item))
						<a href="{{ action('CampaignController@edit', $item->uid) }}" type="button" class="btn bg-grey btn-icon"> <i class="icon-pencil"></i> {{ trans('messages.edit') }}</a>
					@endif
					@if (\Gate::allows('overview', $item))
						<a href="{{ action('CampaignController@overview', $item->uid) }}" data-popup="tooltip" title="{{ trans('messages.overview') }}" type="button" class="btn bg-teal-600 btn-icon"><i class="icon-stats-growth"></i> {{ trans('messages.overview') }}</a>
					@endif
					@if (\Gate::allows('delete', $item) || \Gate::allows('pause', $item) || \Gate::allows('restart', $item))
						<div class="btn-group">
							<button type="button" class="btn dropdown-toggle" data-toggle="dropdown"><span class="caret ml-0"></span></button>
							<ul class="dropdown-menu dropdown-menu-right">
								@if (\Gate::allows('pause', $item))
									<li id="{{ $item->uid }}_actionas"><a link-confirm="{{ trans('messages.pause_campaigns_confirm') }}" href="{{ action('CampaignController@pause', ["uids" => $item->uid]) }}"><i class="icon-pause"></i> {{ trans("messages.pause") }}</a></li>
								@endif
								<li id="{{ $item->uid }}_option_backgroundsend" style="display:none"><a data-method='GET' link-confirm="Do you really want to start Resend Technique im the background ?" href="{{ action('CampaignController@DoRetryBackGroundCampaign', ["uids" => $item->uid]) }}">
										<i class="icon-meter-fast"></i>Use Resend Technique</a></li>
								<li id="{{ $item->uid }}_option_restartsenders" style="display:none"><a data-method='GET' link-confirm="Do you really want to restart the background sending ?" href="{{ action('CampaignController@RestartBackground', ["uids" => $item->uid]) }}">
										<i class="icon-meter-fast"></i>Restart background sending</a></li>
								@if (\Gate::allows('copy', $item))
									<li>
										<a data-uid="{{ $item->uid }}" data-name="{{ trans("messages.copy_of_campaign", ['name' => $item->name]) }}" class="copy-campaign-link">
											<i class="icon-copy4"></i> {{ trans('messages.copy') }}
										</a>
									</li>
								@endif
								@if (\Gate::allows('restart', $item))
									<li id="{{ $item->uid }}_actionas"><a link-confirm="{{ trans('messages.restart_campaigns_confirm') }}" href="{{ action('CampaignController@restart', ["uids" => $item->uid]) }}"><i class="icon-history"></i> Resume Campaign</a></li>
								@endif
								@if (request()->type == "archive")
									@if (\Gate::allows('delete', $item))
										<li><a delete-confirm="{{ trans('messages.delete_campaigns_confirm') }}" href="{{ action('CampaignController@delete', ["uids" => $item->uid]) }}"><i class="icon-trash"></i> {{ trans("messages.delete") }}</a></li>
									@endif
								@endif

								@if (request()->type == "archive")
									<li><a data-method='GET' link-confirm="Do you really want to unarchive this campaign ?" href="{{ action('CampaignController@unarchive', ["uids" => $item->uid]) }}">
											<i class="icon-archive"></i>Move back from archive</a>
								@else
									<li><a data-method='GET' link-confirm="Do you really want to archive this campaign ?" href="{{ action('CampaignController@archive', ["uids" => $item->uid]) }}">
											<i class="icon-archive"></i>Archive</a>
								@endif

							</ul>

						</div>
					@endif
				</td>
			</tr>
		@endforeach
	</table>
	<script>
		var i = setInterval(function(){
			//console.log("Refresh is happening");
			jQuery.ajax({
				type:"GET",
				url:"/campaigns/counter",
				dataType:"json",
				success:function(data) {
					data.forEach(function(camp){
						if ( $( "#"+camp.uid+"_statusas" ).length ) {
							//console.log("Got element campaign: " + camp.uid);
							if(jQuery('#'+camp.uid+'_bak').html() == '') {
								jQuery('#'+camp.uid+'_bak').html(jQuery('#'+camp.uid+'_statusas').html());
								jQuery('#'+camp.uid+'_bg').html(jQuery('#'+camp.uid+'_statusas').prop('class'));
							}

							jQuery('#'+camp.uid).html(camp.counter);
							// calculate how much per second
							var last_prob = jQuery('#'+camp.uid+'_last_speed').html();
							var per_sec = (camp.counter-last_prob);
							// if speed is greater than zero, we will calculate some data
							if (per_sec > 0) {
								var remaining = camp.total - camp.counter;
								var seconds_remaining = 1 ? remaining / per_sec : 'calculating' ;
								jQuery('#'+camp.uid+'_eta').html('ETA: '+secondsTimeSpanToHMS(seconds_remaining)+', Speed: '+per_sec+' /s');
							}

							jQuery('#'+camp.uid+'_statusas').html(camp.status);
							jQuery('#'+camp.uid+'_total').html(' / '+camp.total);
							jQuery('#'+camp.uid+'_progr').html(percentage(camp.counter,camp.total)+'%');
							jQuery('#'+camp.uid+'_progrbar').css('width',percentage(camp.counter,camp.total)+'%');
							jQuery('#'+camp.uid+'_progropeners').html(percentage(camp.openers,camp.total)+'%');
							jQuery('#'+camp.uid+'_progrbaropeners').css('width',percentage(camp.openers,camp.total)+'%')
							jQuery('#'+camp.uid+'_progrclickers').html(percentage(camp.clickers,camp.total)+'%');
							jQuery('#'+camp.uid+'_progrbarclickers').css('width',percentage(camp.clickers,camp.total)+'%')
							// set the last value
							jQuery('#'+camp.uid+'_last_speed').html(camp.counter);

							// enable campaign item fadein effect
							jQuery('#'+camp.uid+'_item_row').fadeIn(1500);
							if (camp.status == "new") {
								jQuery('#'+camp.uid+'_statusas').attr("class","label label-flat bg-new");
							}
							if (camp.status == "ready") {
								jQuery('#'+camp.uid+'_statusas').attr("class","label label-flat bg-ready");
							}
							if (camp.status == "preparing") {
								jQuery('#'+camp.uid+'_statusas').attr("class","label label-flat bg-ready");
							}


							// status sending
							if (camp.status == "sending") {
								jQuery('#'+camp.uid+'_statusas').attr("class","label label-flat bg-sending");
								jQuery('#'+camp.uid+'_option_restartsenders').show();
								jQuery('#'+camp.uid+'_eta').show();
								if (camp.pause == 1) {
									jQuery('#'+camp.uid+'_statusas').attr("class","label label-flat bg-paused");
									jQuery('#'+camp.uid+'_statusas').html('paused');
									var str = '<a link-confirm="{{ trans('messages.restart_campaigns_confirm') }}" href="{{ action('CampaignController@restart', ["uids" => 'justanotherthing' ]) }}"><i class="icon-history"></i> Resume</a>';
									var needrepl = str.replace("justanotherthing",camp.uid);
									jQuery('#'+camp.uid+'_actionas').html(needrepl);
									jQuery('#'+camp.uid+'_eta').hide();
								} else {
									var str = '<a link-confirm="{{ trans('messages.pause_campaigns_confirm') }}" href="{{ action('CampaignController@pause', ["uids" => 'justanotherthing']) }}"><i class="icon-pause"></i> {{ trans("messages.pause") }}</a>';
									var needrepl = str.replace("justanotherthing",camp.uid);
									jQuery('#'+camp.uid+'_actionas').html(needrepl);
									jQuery('#'+camp.uid+'_eta').show();
								}
							}
							// status done
							if (camp.status == "done") {
								// show elements
								jQuery('#'+camp.uid+'_option_backgroundsend').show();
								// hide elements
								jQuery('#'+camp.uid+'_option_restartsenders').hide();
								jQuery('#'+camp.uid+'_eta').hide();
								jQuery('#'+camp.uid+'_actionas').hide();
								jQuery('#'+camp.uid+'_statusas').attr("class","label label-flat bg-done");
							}


						}
//                                      } else {
//                                              console.log("Campaign is deleted: "+camp.uid)
//                                      }
					});
				}
			});
		},2000)
		function percentage(partialValue, totalValue) {
			return ((100 * partialValue) / totalValue).toFixed(2);
		}
		function secondsTimeSpanToHMS(s) {
			s = Math.round(s);
			var h = Math.floor(s/3600); //Get whole hours
			s -= h*3600;
			var m = Math.floor(s/60); //Get remaining minutes
			s -= m*60;
			return h+":"+(m < 10 ? '0'+m : m)+":"+(s < 10 ? '0'+s : s); //zero padding on minutes and seconds
		}
	</script>
	@include('elements/_per_page_select', ["items" => $campaigns])
	{{ $campaigns->links() }}
@elseif (!empty(request()->keyword))
	<div class="empty-list">
		<i class="icon-paperplane"></i>
		<span class="line-1">
                {{ trans('messages.no_search_result') }}
              </span>
	</div>
@else
	<div class="empty-list">
		<i class="icon-paperplane"></i>
		<span class="line-1">
                {{ trans('messages.campaign_empty_line_1') }}
              </span>
	</div>
@endif