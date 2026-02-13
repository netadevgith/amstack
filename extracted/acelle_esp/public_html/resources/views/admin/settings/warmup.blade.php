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
You can create new warmup pool by going to settings > servers and creating multi postfix server with warmup enabled.<br>
					You can define the default settings <a href="/settings/warmup_settings">here</a>.
					@if ($pools->count() >= 0)
					<table class="table">
	<thead>
	<tr>
		<th scope="col">Pool</th>
		<th scope="col">IP Addresses</th>
		<th scope="col">Emails sent</th>
		<th scope="col">Time left</th>
		<th scope="col">Phase</th>
		<th scope="col">Status</th>
		<th scope="col"></th>
	</tr>
	</thead>
	<tbody>
					@foreach ($pools as $pool)
						<tr>
							<th scole="row">{{ $pool->name }}</th>
							<td>
								@foreach($pool->ips()->get() as $ip)
								{{ $ip->ip_address }}
								@endforeach
							</td>
							<td id="{{ $pool->uid }}_totals">0/0</td>
							<td id="{{ $pool->uid }}_timetotal">0h</td>
							<td id="{{ $pool->uid }}_phase"></td>
							<td id="{{ $pool->uid }}_statusas"></td>
							<td>
								<input type="button" id="{{ $pool->uid }}_start" value="Start" onclick="test_taskrunner_api('{{ $pool->uid }}',1)">
								<input type="button" id="{{ $pool->uid }}_stop" value="Pause" onclick="test_taskrunner_api('{{ $pool->uid }}',2)" style="display:none">
								<input type="button" id="{{ $pool->uid }}_pause" value="Stop" onclick="test_taskrunner_api('{{ $pool->uid }}',3)" style="display:none">
								<input type="button" id="{{ $pool->uid }}_delete" value="Delete" onclick="DeletePool('{{ $pool->uid }}')" style="display:none">
								<input type="button" id="{{ $pool->uid }}_add" value="Move IP's to production" onclick="addToProduction('{{ $pool->uid }}')" style="display:none">
							</td>
						</tr>
					@endforeach
	</tbody>
</table>
					@endif

{{--					<input type="button" onclick="taskrunner_respond()" value="test status">--}}

					<script>
						var secure_token = '{{ csrf_token() }}';
						function taskrunner_respond() {
						      jQuery.ajax({
                                                                type: "POST",
                                                                data: {type: 2, data: "", _token: secure_token },
                                                                url: "/settings/taskrunner_respond",
                                                                dataType: "json",
                                                                success: function (data2) {
                                                                        if (data2.status != 1) {
                                                                                alert('some error'+data2.data);
                                                                        } else {
 									alert('ok '+data2.data);
 									}
                                                                },
                                                                error: function (request, status, error) {
                                                                        alert('We have trouble, see logs!');
                                                                }
                                                        });

                                                }

                        function addToProduction(uid) {
							//alert('not implemented yet');
							jQuery.ajax({
								type: "POST",
								data: {uid: uid, _token: secure_token },
								url: "/settings/warmup_server_production",
								dataType: "json",
								success: function (data2) {
									if (data2.status != 1) {
										alert('some error');
									} else {
										alert('added!');
									}
								},
								error: function (request, status, error) {
									alert('We have trouble, see logs!');
								}
							});
						}

						function DeletePool(uid) {
							test_taskrunner_api(uid, 5);
							jQuery.ajax({
								type: "POST",
								data: {uid: uid, _token: secure_token},
								url: "/settings/warmup_del",
								dataType: "json",
								success: function (data2) {
								}
							});
							var seconds = 5;
							setInterval(function () {
								seconds--;
								if (seconds == 0) {
									location.reload();
								}
							}, 1000);
						}

						function test_taskrunner_api(uid, type) {
							var duom = {
								uid: uid,
								type: type,
							};

							jQuery.ajax({
								type: "POST",
								data: {type: 501, priority: 1, val: JSON.stringify(duom), raw: "direct", _token: secure_token },
								url: "/settings/taskrunner",
								dataType: "json",
								success: function (data2) {
									if (data2.status != 1) {
										alert('some error');
									}
								},
								error: function (request, status, error) {
									alert('We have trouble, see logs!');
								}
							});
						}

						function secondsToTime(s) {
							var h = Math.floor(s / 3600); //Get whole hours
							s -= h * 3600;
							var m = Math.floor(s / 60); //Get remaining minutes
							s -= m * 60;
							return h + ":" + (m < 10 ? '0' + m : m) + ":" + (s < 10 ? '0' + s : s); //zero padding on minutes and seconds
						}

						var i = setInterval(function(){
							console.log("Refresh is happening");
							jQuery.ajax({
								type: "POST",
								url:"/settings/taskrunner_respond",
								data: {type: 2, data: "", _token: secure_token },
								dataType:"json",
								success:function(data2) {
									if (data2.status != 1) {
										console.log('some error: '+data2.data);
									} else {
										var pools = $.parseJSON(data2.data);
										pools.forEach(function(pool){
											if ( $( "#"+pool.uid+"_statusas" ).length ) {
												//console.log("Got element pool: " + pool.uid);
												jQuery('#'+pool.uid+'_totals').html(pool.sendout+' / '+pool.total);
												jQuery('#'+pool.uid+'_timetotal').html(secondsToTime(pool.leftseconds));
												jQuery('#'+pool.uid+'_phase').html(pool.phrase);
												jQuery('#'+pool.uid+'_statusas').html(pool.status);
												if (pool.status == "complete") {
													jQuery('#'+pool.uid+'_add').show();
													jQuery('#'+pool.uid+'_start').hide();
													jQuery('#'+pool.uid+'_stop').hide();
													jQuery('#'+pool.uid+'_pause').hide();
												}
												if (pool.status == "paused") {
													jQuery('#'+pool.uid+'_add').hide();
													jQuery('#'+pool.uid+'_start').hide();
													jQuery('#'+pool.uid+'_stop').show();
													jQuery('#'+pool.uid+'_pause').show();
												}
												if (pool.status == "running") {
													jQuery('#'+pool.uid+'_add').hide();
													jQuery('#'+pool.uid+'_start').hide();
													jQuery('#'+pool.uid+'_stop').show();
													jQuery('#'+pool.uid+'_pause').show();
												}
												if (pool.status == "Interrupted") {
													jQuery('#'+pool.uid+'_delete').show();
												}
											}
										});
										// endas
									}

								}
							});
						},3000);
					</script>
				</div>
@endsection
