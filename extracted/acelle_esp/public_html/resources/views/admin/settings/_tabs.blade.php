<ul class="nav nav-tabs nav-tabs-top">
                <li class="{{ $action == "speed" ? "active" : "" }} text-semibold">
                        <a href="{{ action('Admin\SettingController@speed') }}">
                        <i class="icon-watch2"></i> Speed</a></li>
	{{--@if (Auth::user()->admin->getPermission("setting_general") == 'yes')--}}
		{{--<li class="{{ $action == "general" ? "active" : "" }} text-semibold">--}}
			{{--<a href="{{ action('Admin\SettingController@general') }}">--}}
			{{--<i class="icon-equalizer2"></i> {{ trans('messages.general') }}</a></li>--}}
	{{--@endif--}}
	@if (Auth::user()->admin->getPermission("setting_general") == 'yes')
		<li class="{{ $action == "mailer" ? "active" : "" }} text-semibold">
			<a href="{{ action('Admin\SettingController@mailer') }}">
			<i class="icon-envelop"></i> {{ trans('messages.system_email') }}</a></li>
	@endif
	@if (Auth::user()->admin->getPermission("setting_sending") == 'yes' && false)
		<li class="{{ $action == "sending" ? "active" : "" }} text-semibold">
			<a href="{{ action('Admin\SettingController@sending') }}">
			<i class="icon-paperplane"></i> {{ trans('messages.sending') }}</a></li>
	@endif
	@if (Auth::user()->admin->getPermission("setting_system_urls") == 'yes')
		<li class="{{ $action == "urls" ? "active" : "" }} text-semibold">
			<a href="{{ action('Admin\SettingController@urls') }}">
			<i class="icon-link"></i> {{ trans('messages.system_urls') }}</a></li>
		<li class="{{ $action == "customurls" ? "active" : "" }} text-semibold">
			<a href="{{ action('Admin\SettingController@customurls') }}">
				<i class="icon-link2"></i> Custom URLs</a></li>
	@endif
	<li class="{{ $action == "hardbounces" ? "active" : "" }} text-semibold">
		<a href="{{ action('Admin\SettingController@hardbounces') }}">
			<i class="icon-umbrella"></i> Hard Bounces</a></li>
	<li class="{{ $action == "controller" ? "active" : "" }} text-semibold">
		<a href="{{ action('Admin\SettingController@controller') }}">
			<i class="icon-triangle"></i> Controller</a></li>
	<li class="{{ $action == "maintenance" ? "active" : "" }} text-semibold">
		<a href="{{ action('Admin\SettingController@maintenance') }}">
			<i class="icon-wrench"></i> Maintenance</a></li>
	<li class="{{ $action == "rotator" ? "active" : "" }} text-semibold">
		<a href="{{ action('Admin\SettingController@rotator') }}">
			<i class="icon-rotate-cw"></i> Domain Rotator</a></li>
	<li class="{{ $action == "rotator_perk" ? "active" : "" }} text-semibold">
		<a href="{{ action('Admin\SettingController@rotator_perk') }}">
			<i class="icon-rotate-ccw"></i> Rotator per K</a></li>
	<li class="{{ $action == "monitoring" ? "active" : "" }} text-semibold">
		<a href="{{ action('Admin\SettingController@monitoring') }}">
			<i class="icon-statistics"></i> Monitoring</a></li>
	<li class="{{ $action == "dns" ? "active" : "" }} text-semibold">
		<a href="{{ action('Admin\SettingController@dns') }}">
			<i class="icon-package"></i> DNS</a></li>
	<li class="{{ $action == "debug" ? "active" : "" }} text-semibold">
		<a href="{{ action('Admin\SettingController@debug') }}">
			<i class="icon-terminal"></i> Debug</a></li>
    @if (Config::get('app.servers') == true)
	<li class="{{ $action == "servers" ? "active" : "" }} text-semibold">
		<a href="{{ action('Admin\SettingController@servers') }}">
			<i class="icon-server"></i> Servers</a></li>
    @endif
	<li class="{{ $action == "taskrunner" ? "active" : "" }} text-semibold">
		<a href="{{ action('Admin\SettingController@taskrunner') }}">
			<i class="icon-racing"></i> Task Runner</a></li>
	@if (Config::get('app.storage') == true)
		<li class="{{ $action == "storage" ? "active" : "" }} text-semibold">
			<a href="{{ action('Admin\SettingController@storage') }}">
				<i class="icon-meter-fast"></i> Storage</a></li>
	@endif
	@if (Config::get('app.servers') == true)
		<li class="{{ $action == "mta" ? "active" : "" }} text-semibold">
			<a href="{{ action('Admin\SettingController@mta') }}">
				<i class="icon-gear"></i> MTA</a></li>
		<li class="{{ $action == "warmup" ? "active" : "" }} text-semibold">
			<a href="{{ action('Admin\SettingController@warmup') }}">
				<i class="icon-fire"></i> Warmup</a></li>
		@endif

	<li class="{{ $action == "findbyuid" ? "active" : "" }} text-semibold">
		<a href="{{ action('Admin\SettingController@FindContactByUid') }}">
			<i class="icon-finder"></i> Find By Uid</a></li>

	<li class="{{ $action == "ver" ? "active" : "" }} text-semibold">
		<a href="{{ action('Admin\SettingController@ver') }}">
			<i class="icon-versions"></i> Version</a></li>


	{{--@if (Auth::user()->admin->getPermission("setting_background_job") == 'yes')--}}
		{{--<li class="{{ $action == "cronjob" ? "active" : "" }} text-semibold">--}}
			{{--<a href="{{ action('Admin\SettingController@cronjob') }}">--}}
			{{--<i class="icon-alarm"></i> {{ trans('messages.background_job') }}</a></li>--}}
	{{--@endif--}}
	{{--@if (Auth::user()->admin->getPermission("setting_general") == 'yes')--}}
		{{--<li class="{{ $action == "license" ? "active" : "" }} text-semibold">--}}
			{{--<a href="{{ action('Admin\SettingController@license') }}">--}}
			{{--<i class="icon-key"></i> {{ trans('messages.license_tab') }}</a></li>--}}
	{{--@endif--}}
	{{--@if (Auth::user()->admin->getPermission("setting_upgrade_manager") == 'yes')--}}
		{{--<li class="{{ $action == "upgrade" ? "active" : "" }} text-semibold">--}}
			{{--<a href="{{ action('Admin\SettingController@upgrade') }}">--}}
			{{--<i class="icon-wrench"></i> {{ trans('messages.upgrade.title.upgrade') }}</a></li>--}}
	{{--@endif--}}
        <li class="{{ $action == "proxies" ? "active" : "" }} text-semibold">
        <a href="{{ action('Admin\SettingController@proxies') }}">
        <i class="icon-wall"></i> Proxies</a></li>
</ul>


