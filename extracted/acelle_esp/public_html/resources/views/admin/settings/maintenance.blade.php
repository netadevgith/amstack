@extends('layouts.frontend')

@section('title', trans('messages.settings'))

@section('page_script')
    <script type="text/javascript" src="{{ URL::asset('assets/js/core/libraries/jquery_ui/interactions.min.js') }}"></script>
	<script type="text/javascript" src="{{ URL::asset('assets/js/core/libraries/jquery_ui/touch.min.js') }}"></script>

	<script type="text/javascript" src="{{ URL::asset('js/listing.js') }}"></script>
@endsection

@section('page_header')

			<div class="page-title">
				<ul class="breadcrumb breadcrumb-caret position-right">
					<li><a href="{{ action("HomeController@index") }}">{{ trans('messages.home') }}</a></li>
				</ul>
				<h1>
					<span class="text-gear"><i class="icon-list2"></i> {{ trans('messages.settings') }}</span>
				</h1>
			</div>

@endsection

@section('content')
				<div class="tabbable">

					@include("admin.settings._tabs")

                    <form action="{{ action('Admin\SettingController@maintenance') }}" method="POST" class="form-validate-jqueryz">
<p>Here you can do manually things such as clean mysql queries, stop processes, etc...</p>
						{!! csrf_field() !!}
						<button class="btn btn-primary bg-teal lubos" name="mysql" data-popup="tooltip" title="Processes running: {{ $settings['mysqls'] }}">
							Clean MySQL Workload
						</button> kills all running mysql background queries manually.<br>
{{--						<button class="btn btn-primary bg-teal lubos" name="poststop" data-popup="tooltip" title="Processes running: {{ $settings['parsers'] }}">--}}
{{--							Stop postfix parsing daemons--}}
{{--						</button> Stops all running postfix parsing daemons that are running in the background.<br>--}}
{{--						<button class="btn btn-primary bg-teal lubos" name="poststart" data-popup="tooltip" title="Processes running: {{ $settings['parsers'] }}">--}}
{{--							Start postfix parsing daemons--}}
{{--						</button> Starts all postfix parsing daemons in the background for every server.<br>--}}
						<button class="btn btn-primary bg-teal lubos" name="postrestart" data-popup="tooltip" title="Processes running: {{ $settings['parsers'] }}">
							Restart parsers
						</button> Restarts all remote postfix parsers.<br>
						<button class="btn btn-primary bg-teal lubos" name="sendstop" data-popup="tooltip" title="Processes running: {{ $settings['senders'] }}">
							Stop background sending
						</button> Stops all sending processes that are running in the background and sending emails.<br>
						<button class="btn btn-primary bg-teal lubos" name="sendrestart" data-popup="tooltip" title="Processes running: {{ $settings['senders'] }}">
							Restart background sending
						</button> Restart all sending processes that are running in the background and sending emails.<br>
						@if ($settings['running'] == 1)
							<b>Maintenance cleanup script is already running in the background. Please wait...<b>
							@else
{{--						<button class="btn btn-primary bg-teal" name="sendmaint">--}}
{{--							Run maintenance & cleanup--}}
{{--						</button> Runs the maintenance and cleanup script in the background which cleans the job queues, web cache, temporary files, etc...<br>--}}
						@endif
						<button class="btn btn-primary bg-teal lubos" name="killcache">
						Restart cache manager
						</button> Kills cache manager, after 1 minute it starts automatically.<br>
						<button class="btn btn-primary bg-teal lubos" name="cleanredis" title="Currently: {{ $settings['redisqueue'] }} jobs are queued">
									Clean redis queue
						</button> Cleans out the redis queue.<br>
{{--						<button class="btn btn-primary bg-teal lubos" name="sendtestjob" title="test">--}}
{{--									Test a background job--}}
{{--								</button> Runs a test background job and outputs to the log mail.log.<br>--}}
						<div class="text-left">
							{{--<button class="btn btn-primary bg-teal">--}}
								{{--{!! trans('messages.save') !!}--}}
							{{--</button>--}}
						</div>
					</form>
				</div>
				<script>
                    $( ".lubos" ).tooltip({
                        position: { my: "right-5 center", at: "left center", collision: "flipfit" }
                    });
				</script>
@endsection
