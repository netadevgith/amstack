<div class="row">
	<div class="col-md-12">
		<ul class="nav nav-tabs nav-tabs-top page-second-nav">
			<li rel0="CampaignController/overview" class="dropdown">
				<a href="{{ action('CampaignController@overview', $campaign->uid) }}" class="level-1">
					<i class="icon-stats-bars3"></i> {{ trans('messages.overview') }}
				</a>
			</li>
			<li rel0="CampaignController/links" class="dropdown">
				<a href="{{ action('CampaignController@links', $campaign->uid) }}" class="level-1">
					<i class="icon-link"></i> {{ trans('messages.links') }}
				</a>
			</li>
			<li rel0="CampaignController/openMap" class="dropdown">
				<a href="{{ action('CampaignController@openMap', $campaign->uid) }}" class="level-1">
					<i class="icon-map4"></i> {{ trans('messages.open_map') }}
				</a>
			</li>
			<li rel0="CampaignController/subscribers" class="dropdown">
				<a href="{{ action('CampaignController@subscribers', $campaign->uid) }}" class="level-1">
					<i class="icon-users"></i> {{ trans('messages.subscribers') }}
				</a>
			</li>
			<li class="dropdown"
				rel0="CampaignController/trackingLog"
				rel1="CampaignController/bounceLog"
				rel2="CampaignController/feedbackLog"
				rel3="CampaignController/openLog"
				rel4="CampaignController/clickLog"
				rel5="CampaignController/unsubscribeLog"
			>
				<a href="{{ action("AccountController@contact") }}" class="level-1" data-toggle="dropdown">
					<i class="icon-file-text2 position-left"></i> {{ trans('messages.sending_logs') }}
					<span class="caret"></span>
				</a>
				<ul class="dropdown-menu dropdown-menu-right">
					<li rel0="CampaignController/trackingLog" class="dropdown">
						<a href="{{ action('CampaignController@trackingLog', $campaign->uid) }}">
							<i class="icon-file-text2"></i> {{ trans('messages.tracking_log') }}
						</a>
					</li>
					<li rel0="CampaignController/bounceLog" class="dropdown">
						<a href="{{ action('CampaignController@bounceLog', $campaign->uid) }}">
							<i class="icon-file-text2"></i> {{ trans('messages.bounce_log') }}
						</a>
					</li>
					<li rel0="CampaignController/feedbackLog" class="dropdown">
						<a href="{{ action('CampaignController@feedbackLog', $campaign->uid) }}">
							<i class="icon-file-text2"></i> {{ trans('messages.feedback_log') }}
						</a>
					</li>
					<li rel0="CampaignController/openLog" class="dropdown">
						<a href="{{ action('CampaignController@openLog', $campaign->uid) }}">
							<i class="icon-file-text2"></i> {{ trans('messages.open_log') }}
						</a>
					</li>
					<li rel0="CampaignController/clickLog" class="dropdown">
						<a href="{{ action('CampaignController@clickLog', $campaign->uid) }}">
							<i class="icon-file-text2"></i> {{ trans('messages.click_log') }}
						</a>
					</li>
					<li rel0="CampaignController/unsubscribeLog" class="dropdown">
						<a href="{{ action('CampaignController@unsubscribeLog', $campaign->uid) }}">
							<i class="icon-file-text2"></i> {{ trans('messages.unsubscribe_log') }}
						</a>
					</li>
					<li rel0="CampaignController/conversionLog" class="dropdown">
						<a href="{{ action('CampaignController@conversionLog', $campaign->uid) }}">
							<i class="icon-file-text2"></i> Conversion log
						</a>
					</li>
				</ul>
			</li>
			<li rel0="CampaignController/templateReview" class="dropdown">
				<a href="{{ action('CampaignController@templateReview', $campaign->uid) }}" class="level-1">
					<i class="icon-magazine"></i> {{ trans('messages.email_review') }}
				</a>
			</li>

			<!-- new one -->
			<li class="dropdown"
				rel0="CampaignController/trackingLog"
				rel1="CampaignController/bounceLog"
				rel2="CampaignController/feedbackLog"
				rel3="CampaignController/openLog"
				rel4="CampaignController/clickLog"
				rel5="CampaignController/unsubscribeLog">
				<a href="{{ action("AccountController@contact") }}" class="level-1" data-toggle="dropdown">
					<i class="icon-database-export position-left"></i> Export
					<span class="caret"></span>
				</a>
				<ul class="dropdown-menu dropdown-menu-right">
					<li rel0="CampaignController/trackingLog" class="dropdown">
						<a href="{{ action('SubscriberController@export_from_campaigns', [ 'uid' => $campaign->id, 'type' => 1 ]) }}">
							<i class="icon-file-text2"></i> Openers
						</a>
					</li>
					<li rel0="CampaignController/trackingLog" class="dropdown">
						<a href="{{ action('SubscriberController@export_from_campaigns', [ 'uid' => $campaign->id, 'type' => 2 ]) }}">
							<i class="icon-file-text2"></i> Clickers
						</a>
					</li>
					<li rel0="CampaignController/trackingLog" class="dropdown">
						<a href="{{ action('SubscriberController@export_from_campaigns', [ 'uid' => $campaign->id, 'type' => 3]) }}">
							<i class="icon-file-text2"></i> Unsubscribers
						</a>
					</li>
					<li rel0="CampaignController/trackingLog" class="dropdown">
						<a href="{{ action('SubscriberController@export_from_campaigns', [ 'uid' => $campaign->id, 'type' => 4]) }}">
							<i class="icon-file-text2"></i> Not openers
						</a>
					</li>
			<!-- new one end -->

		</ul>
	</div>
</div>
