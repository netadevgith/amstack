<!DOCTYPE html>
<html lang="en">
<head>
	<title>@yield('title') - {{ \Acelle\Model\Setting::get("site_name") }}</title>

	@include('layouts._favicon')

	@include('layouts._head')

	@include('layouts._css')

	@include('layouts._js')

	<script>
		$.cookie('last_language_code', '{{ Auth::user()->customer->getLanguageCode() }}');
	</script>

</head>

<body class="navbar-top color-scheme-{{ Auth::user()->customer->getColorScheme() }}">

	<!-- Main navbar -->
	<div class="navbar navbar-{{ Auth::user()->customer->getColorScheme() == "white" ? "default" : "inverse" }} navbar-fixed-top">
		<div class="navbar-header">
			<a class="navbar-brand" href="{{ action('HomeController@index') }}">
				@if (\Acelle\Model\Setting::get('site_logo_small'))
                    <img src="{{ URL::asset(\Acelle\Model\Setting::get('site_logo_small')) }}" alt="">
                @else
                    <img src="{{ URL::asset('images/default_site_logo_small_' . (Auth::user()->customer->getColorScheme() == "white" ? "dark" : "light") . '.png') }}" alt="">
                @endif
			</a>

			<ul class="nav navbar-nav pull-right visible-xs-block">
				<li><a class="mobile-menu-button" data-toggle="collapse" data-target="#navbar-mobile"><i class="icon-menu7"></i></a></li>
			</ul>
		</div>

		<div class="navbar-collapse collapse" id="navbar-mobile">
			<ul class="nav navbar-nav">
				<li rel0="HomeController">
					<a href="{{ action('HomeController@index') }}">
						<i class="icon-home"></i> {{ trans('messages.dashboard') }}
					</a>
				</li>
				<li rel0="CampaignController">
					<a href="{{ action('CampaignController@index') }}">
						<i class="icon-paperplane"></i> {{ trans('messages.campaigns') }}
					</a>
				</li>
				<li rel0="ArchiveController">
					<a href="{{ action('ArchiveController@index') }}">
						<i class="icon-archive"></i> Archive
					</a>
				</li>
				{{--<li rel0="AutomationController">--}}
					{{--<a href="{{ action('AutomationController@index') }}">--}}
						{{--<i class="icon-alarm-check"></i> {{ trans('messages.Automations') }}--}}
					{{--</a>--}}
				{{--</li>--}}
				<li
					rel1="FieldController"
					rel2="SubscriberController"
					rel3="SegmentController"
						{{{ (Request::is('lists/*') ? 'class=active' : '') }}}
				>
					<a href="{{ action('MailListController@index') }}"><i class="icon-address-book2"></i> {{ trans('messages.lists') }}</a>
				</li>
                {{--<li rel0="TemplateController">--}}
					{{--<a href="{{ action('TemplateController@index') }}">--}}
						{{--<i class="icon-magazine"></i> {{ trans('messages.templates') }}--}}
					{{--</a>--}}
				{{--</li>--}}
				@if(
					Auth::user()->customer->can("read", new Acelle\Model\SendingServer()) ||
					Auth::user()->customer->can("read", new Acelle\Model\SendingDomain()) ||
                    Auth::user()->customer->can("read", new Acelle\Model\EmailVerificationServer()) ||
					Auth::user()->customer->can("read", new Acelle\Model\Blacklist())
				)
					<li class="dropdown language-switch"
						rel0="SendingServerController"
						rel1="Blacklist_domainsController"
						rel2="Blacklist_namesController"
                        rel3="Blacklist_abuseController"
						rel4="BlacklistController"
						rel5="BulkController"
					>
						<a class="dropdown-toggle" data-toggle="dropdown">
							<i class="glyphicon glyphicon-transfer"></i> {{ trans('messages.sending') }}
							<span class="caret"></span>
						</a>
						<ul class="dropdown-menu">
							@if (Auth::user()->customer->can("read", new Acelle\Model\SendingServer()) && config('app.shared') == false)
								<li rel0="SendingServerController">
									<a href="{{ action('SendingServerController@index') }}">
										<i class="icon-server"></i> {{ trans('messages.sending_servers') }}
									</a>
								</li>
								<li rel0="ServGroupController">
									<a href="{{ action('ServGroupController@index') }}">
										<i class="icon-make-group"></i> Server Groups
									</a>
								</li>
							@endif

							{{--@if (Auth::user()->customer->can("read", new Acelle\Model\SendingDomain()))--}}
								{{--<li rel0="SendingDomainController">--}}
									{{--<a href="{{ action('SendingDomainController@index') }}">--}}
										{{--<i class="icon-earth"></i> {{ trans('messages.sending_domains') }}--}}
									{{--</a>--}}
								{{--</li>--}}
							{{--@endif--}}
                            {{--@if (Auth::user()->customer->can("read", new Acelle\Model\EmailVerificationServer()))--}}
								{{--<li rel0="EmailVerificationServerController">--}}
									{{--<a href="{{ action('EmailVerificationServerController@index') }}">--}}
										{{--<i class="icon-database-check"></i> {{ trans('messages.email_verification_servers') }}--}}
									{{--</a>--}}
								{{--</li>--}}
							{{--@endif--}}
							@if (Auth::user()->customer->can("read", new Acelle\Model\Blacklist()))
								<li rel0="BlacklistController">
									<a href="{{ action('BlacklistController@index') }}">
										<i class="glyphicon glyphicon-minus-sign"></i> {{ trans('messages.blacklist') }}
									</a>
								</li>
							@endif
								<li rel0="Blacklist_domainsController">
									<a href="{{ action('Blacklist_domainsController@index') }}">
										<i class="icon-earth"></i> Domains Blacklist
									</a>
								</li>
								<li rel0="Blacklist_namesController">
									<a href="{{ action('Blacklist_namesController@index') }}">
										<i class="icon-person"></i> Names Blacklist
									</a>
								</li>
								<li rel0="Blacklist_abuseController">
									<a href="{{ action('Blacklist_abuseController@index') }}">
										<i class="icon-warning"></i> Abuse Blacklist
									</a>
								</li>
								<li rel0="BulkController">
									<a href="{{ action('BulkController@index') }}">
										<i class="icon-checkmark-circle"></i> Bulk IMAP Checker
									</a>
								</li>
						</ul>
					</li>
				@endif
				{{--begin injection--}}
				@if (config('app.shared') == false)
				<li class="dropdown language-switch"
					rel0="SettingController"
				>
					<a class="dropdown-toggle" data-toggle="dropdown">
						<i class="icon-gear"></i> {{ trans('messages.setting') }}
						<span class="caret"></span>
					</a>
					<ul class="dropdown-menu">
						@if (
							Auth::user()->admin->getPermission("setting_general") != 'no' ||
							Auth::user()->admin->getPermission("setting_sending") != 'no' ||
							Auth::user()->admin->getPermission("setting_system_urls") != 'no' ||
							Auth::user()->admin->getPermission("setting_background_job") != 'no'
						)
							<li rel0="SettingController">
								<a href="{{ action('Admin\SettingController@index') }}">
									<i class="icon-equalizer2"></i> {{ trans('messages.all_settings') }}
								</a>
							</li>
						@endif
					</ul>
				</li>

				<li class="dropdown language-switch"
					rel0="TrackingLogController"
					rel1="OpenLogController"
					rel2="ClickLogController"
					rel3="FeedbackLogController"
					rel4="BlacklistController"
					rel5="UnsubscribeLogController"
					rel6="BounceLogController"
				>
{{--					<a class="dropdown-toggle" data-toggle="dropdown">--}}
{{--						<i class="icon-file-text2"></i> {{ trans('messages.report') }}--}}
{{--						<span class="caret"></span>--}}
{{--					</a>--}}
{{--					<ul class="dropdown-menu">--}}
{{--						@if (Auth::user()->admin->getPermission("report_blacklist") != 'no')--}}
{{--							<li rel0="BlacklistController">--}}
{{--								<a href="{{ action('BlacklistController@index') }}">--}}
{{--									<i class="glyphicon glyphicon-minus-sign"></i> {{ trans('messages.blacklist') }}--}}
{{--								</a>--}}
{{--							</li>--}}
{{--						@endif--}}
{{--						@if (Auth::user()->admin->getPermission("report_tracking_log") != 'no')--}}
{{--							<li rel0="TrackingLogController">--}}
{{--								<a href="{{ action('Admin\TrackingLogController@index') }}">--}}
{{--									<i class="icon-file-text2"></i> {{ trans('messages.tracking_log') }}--}}
{{--								</a>--}}
{{--							</li>--}}
{{--						@endif--}}
{{--						@if (Auth::user()->admin->getPermission("report_bounce_log") != 'no')--}}
{{--							<li rel0="BounceLogController">--}}
{{--								<a href="{{ action('Admin\BounceLogController@index') }}">--}}
{{--									<i class="icon-file-text2"></i> {{ trans('messages.bounce_log') }}--}}
{{--								</a>--}}
{{--							</li>--}}
{{--						@endif--}}
{{--						@if (Auth::user()->admin->getPermission("report_feedback_log") != 'no')--}}
{{--							<li rel0="FeedbackLogController">--}}
{{--								<a href="{{ action('Admin\FeedbackLogController@index') }}">--}}
{{--									<i class="icon-file-text2"></i> {{ trans('messages.feedback_log') }}--}}
{{--								</a>--}}
{{--							</li>--}}
{{--						@endif--}}
{{--						@if (Auth::user()->admin->getPermission("report_open_log") != 'no')--}}
{{--							<li rel0="OpenLogController">--}}
{{--								<a href="{{ action('Admin\OpenLogController@index') }}">--}}
{{--									<i class="icon-file-text2"></i> {{ trans('messages.open_log') }}--}}
{{--								</a>--}}
{{--							</li>--}}
{{--						@endif--}}
{{--						@if (Auth::user()->admin->getPermission("report_click_log") != 'no')--}}
{{--							<li rel0="ClickLogController">--}}
{{--								<a href="{{ action('Admin\ClickLogController@index') }}">--}}
{{--									<i class="icon-file-text2"></i> {{ trans('messages.click_log') }}--}}
{{--								</a>--}}
{{--							</li>--}}
{{--						@endif--}}
{{--						@if (Auth::user()->admin->getPermission("report_unsubscribe_log") != 'no')--}}
{{--							<li rel0="UnsubscribeLogController">--}}
{{--								<a href="{{ action('Admin\UnsubscribeLogController@index') }}">--}}
{{--									<i class="icon-file-text2"></i> {{ trans('messages.unsubscribe_log') }}--}}
{{--								</a>--}}
{{--							</li>--}}
{{--						@endif--}}
{{--								<li rel0="ControllerLogController">--}}
{{--									<a href="{{ action('Admin\ControllerLogController@index') }}">--}}
{{--										<i class="icon-file-text2"></i> Controller log--}}
{{--									</a>--}}
{{--								</li>--}}
{{--					</ul>--}}

				</li>
				@endif
{{--				<li {{{ (Request::is('contacts') ? 'class=active' : '') }}}>--}}
{{--					<a href="{{ action('MailListController@contacts') }}"><i class="icon-address-book3"></i>Contacts</a>--}}
{{--				</li>--}}


			</ul>

 {{--end injection --}}






			<ul class="nav navbar-nav navbar-right">
				<li style="line-height: 0.8384616; font-size: 72%; padding-top: 13px">
					@if(is_array(\Acelle\Library\SysInf::GetNiceInfo()))
					@foreach(\Acelle\Library\SysInf::GetNiceInfo() as $usgitem)
						<small>{{ $usgitem }}</small> <br>
						@endforeach
						@endif
				</li>
				<li class="dropdown"><a href=""><i class="icon-ok"></i>
					@if (Redis::exists('proxy_check'))
						@if (Redis::get('proxy_check') == 1)
							Proxy: OK
							@else
							Proxy: ERROR
							@endif
						@else
						Proxy: Unchecked
						@endif

					</a></li>

				<!--<li class="dropdown language-switch">
					<a class="dropdown-toggle" data-toggle="dropdown">
						{{ Acelle\Model\Language::getByCode(Config::get('app.locale'))->name }}
						<span class="caret"></span>
					</a>

					<ul class="dropdown-menu">
						@foreach(Acelle\Model\Language::getAll() as $language)
							<li class="{{ Acelle\Model\Language::getByCode(Config::get('app.locale'))->code == $language->code ? "active" : "" }}">
								<a>{{ $language->name }}</a>
							</li>
						@endforeach
					</ul>
                </li>-->

				<!--<li class="dropdown">
					<a href="#" class="dropdown-toggle top-quota-button" data-toggle="dropdown" data-url="{{ action("AccountController@quotaLog") }}">
						<i class="icon-stats-bars4"></i>
						<span class="visible-xs-inline-block position-right">{{ trans('messages.used_quota') }}</span>
					</a>
				</li>-->

				@include('layouts._top_activity_log')

				<li class="dropdown dropdown-user">
					<a class="dropdown-toggle" data-toggle="dropdown">
						<img src="{{ action('CustomerController@avatar', Auth::user()->customer->uid) }}" alt="">
						<span>{{ Auth::user()->customer->displayName() }}</span>
						<i class="caret"></i>
					</a>

					<ul class="dropdown-menu dropdown-menu-right">
						{{--@can("admin_access", Auth::user())--}}
							{{--<li><a href="{{ action("HomeController@index") }}"><i class="icon-enter2"></i> {{ trans('messages.admin_view') }}</a></li>--}}
							{{--<li class="divider"></li>--}}
						{{--@endif--}}
						{{--<li class="dropdown">--}}
							{{--<a href="#" class="top-quota-button" data-url="{{ action("AccountController@quotaLog") }}">--}}
								{{--<i class="icon-stats-bars4"></i>--}}
								{{--<span class="">{{ trans('messages.used_quota') }}</span>--}}
							{{--</a>--}}
						{{--</li>--}}
						{{--@if (Auth::user()->customer->can("read", new Acelle\Model\Subscription()))--}}
							{{--<li rel0="AccountController\subscription">--}}
								{{--<a href="{{ action('AccountController@subscription') }}">--}}
									{{--<i class="icon-quill4"></i> {{ trans('messages.subscriptions') }}--}}
								{{--</a>--}}
							{{--</li>--}}
						{{--@endif--}}
						<li><a href="{{ action("AccountController@profile") }}"><i class="icon-profile"></i> {{ trans('messages.account') }}</a></li>
						{{--@if (Auth::user()->customer->canUseApi())--}}
							{{--<li rel0="AccountController/api">--}}
								{{--<a href="{{ action("AccountController@api") }}" class="level-1">--}}
									{{--<i class="icon-key position-left"></i> {{ trans('messages.api') }}--}}
								{{--</a>--}}
							{{--</li>--}}
						{{--@endif--}}
						<li><a href="{{ url("/logout") }}"><i class="icon-switch2"></i> {{ trans('messages.logout') }}</a></li>
					</ul>
				</li>
			</ul>
		</div>
	</div>
	<!-- /main navbar -->

	<!-- Page header -->
	<div class="page-header">
		<div class="page-header-content">

			@yield('page_header')

		</div>
	</div>
	<!-- /page header -->

	<!-- Page container -->
	<div class="page-container">

		<!-- Page content -->
		<div class="page-content">

			<!-- Main content -->
			<div class="content-wrapper">

				<!-- display flash message -->
				@include('common.errors')

				<!-- main inner content -->
				@yield('content')

			</div>
			<!-- /main content -->

		</div>
		<!-- /page content -->


		<!-- Footer -->
		<div class="footer text-muted">
			{{--{!! trans('messages.copy_right') !!}--}}
		</div>
		<!-- /footer -->

	</div>
	<!-- /page container -->

	@include("layouts._modals")

</body>
</html>
