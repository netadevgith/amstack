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


		@include("admin.settings._tabs")
<h1>MTA Status</h1>
		<span class="no-margin text-teal-800 stat-num">Microservice status: </span><span id="service_status">checking...</span><br>
		<b>MTA Postfix Handler status:<b/> <span id="mta_handler">checking...</span><br>
		<b>MTA PMTA Handler status:<b/> <span id="pmta_handler">checking...</span><br><br>

		<div class="container">
			<div class="row"><button id="refreshbtn" onclick="refresh_all()" style="display:none">Refresh</button></div>
			<div class="row">Add server using external <a href="{{ Config::get('app.mta') }}/api.php?addserver" target="_blank">MTA microservice</a></div>
			<div class="row">
				<div class="col-sm">
		<div id="datas"></div>
				</div>
			</div>


		</div>





@endsection
<meta name="csrf-token" content="{{ csrf_token() }}">
<script>
	window.onload = function () {
		refresh_all();
	}

	function refresh_all() {
		load_contents();
		refresh_handler();
	}

	function load_contents() {
		jQuery.ajax({
			type:"GET",
			url:"/settings/mta_load_data",
			dataType:"json",
			success:function(data) {
				$("#service_status").html("Running");
				$("#refreshbtn").show();
				var content = '<table class="table table-striped table-sm">';
				content += '<thead>';
				content += '<tr><th>Host</th><th>Type</th><th>Deployments</th></tr>';
				content += '</thead><tbody>';

				data.forEach(function(server) {
					content += '<tr><td> <button onclick="del_server(\''+server.host+'\')">delete</button> '+server.host+'</td><td>'+server.type+'</td>';
					content += '<td>';
					if (typeof server.deployments != 'undefined') {
						server.deployments.forEach(function (deployment) {
							content += deployment + ' ';
						});
					}
					content += '</td></tr>'

				});
				content += "</tbody></table>"
				$('#datas').html('');
				$('#datas').append(content);
			},
			error: function (request, status, error) {
				console.log(request.responseText);
			}
		});
	}


	function del_server(server) {
		console.log("Deleting server: "+server);
		if (server == 'undefined') {
			alert("Server host not known...");
			exit;
		}
		jQuery.ajax({
			type:"GET",
			url:"/settings/delfrommta/"+server,
			dataType:"json",
			success:function(data) {
				load_contents();
			},
			error: function (request, status, error) {
				load_contents();
			}
		});
	}


	function refresh_handler() {
		jQuery.ajax({
			type:"GET",
			url:"/settings/checkpmta",
			dataType:"json",
			success:function(data) {
				if (data.running == 1) {
					$('#pmta_handler').html("Running");
				} else {
					$('#pmta_handler').html("Stopped");
				}
			},
			error: function (request, status, error) {
				console.log(request.responseText);
			}
		});

		jQuery.ajax({
			type:"GET",
			url:"/settings/checkmta",
			dataType:"json",
			success:function(data) {
				if (data.running == 1) {
					$('#mta_handler').html("Running");
				} else {
					$('#mta_handler').html("Stopped");
				}
			},
			error: function (request, status, error) {
				console.log(request.responseText);
			}
			});
	}
	
</script>