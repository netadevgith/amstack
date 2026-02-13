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
					<li><a href="{{ action("Admin\HomeController@index") }}">{{ trans('messages.home') }}</a></li>
				</ul>
				<h1>
					<span class="text-gear"><i class="icon-list2"></i> {{ trans('messages.settings') }}</span>
				</h1>
			</div>

@endsection


@section('content')
				<div class="tabbable">
					
                    @include("admin.settings._tabs")
	
					{{--<div class="tab-content">--}}
{{--There you can import all your domains, first point these domains Name servers to: ns1.yourdomain, ns2.yourdomain. Then write them down one per line.<br>--}}
						{{--<form action="{{ action('Admin\SettingController@taskrunner') }}" method="POST" class="form-validate-jqueryz">--}}
						{{--<textarea rows="15" cols="20" name="domain_list">example.com.</textarea><br>--}}
							{{--{!! csrf_field() !!}--}}
							{{--<div class="text-left">--}}
								{{--<button class="btn btn-primary bg-teal">--}}
									{{--Import--}}
								{{--</button>--}}
							{{--</div>--}}
						{{--</form>--}}

{{--<br>--}}
{{--						<h3>Online</h3>--}}
						{{--<p>Proxy IP: {{ $proxyip }}</p>--}}
{{--						<ul class="list-group">--}}
{{--							@if (is_object($lists))--}}
{{--@foreach($lists as $list)--}}
{{--<li class="list-group-item list-group-item-warning">{{ $list->name }} ({{ $list->uid }}) <input type="button" onclick="update_cache(3,'{{ $list->uid }}')" value="Update cache prior 3">--}}
{{--	<input type="button" onclick="update_cache(2,'{{ $list->uid }}')" value="Update cache prior 2">--}}
{{--	<input type="button" onclick="update_cache(1,'{{ $list->uid }}')" value="Update cache prior 1">--}}
{{--</li></li>--}}
{{--	@endforeach--}}
{{--</ul>--}}
{{--						@endif--}}


					<span class="no-margin text-teal-800 stat-num">Microservice status: </span><span id="service_status">checking...</span><br>

				<div id="enable_storage" style="display:none">


					Here you can import new emails or specific contacts to blacklists in the remote storage microservice, it can also remove the imported contacts and do some diagnostics.<br>
					<form action="{{ action('Admin\SettingController@storage') }}" method="POST" class="form-validate-jqueryz">
						<textarea rows="15" cols="35" name="records_list">{{ $responder }}</textarea><br>
						{!! csrf_field() !!}
						Type:
						<select name="submit_type">
							<option value="1">Add email(s) as hardbounce(s)</option>
							<option value="2">Add email(s) as complain(s)</option>
							<option value="3">Add email(s) as abuse report(s)</option>
							<option value="4">Add email(s) as feedback loop(s)</option>
							<option value="5">Add email(s) as spamtrap(s)</option>
							<option value="6">Add domain as (dns not found)</option>
							<option value="7">Add domain as (blocked)</option>
							<option value="8">Add domain as (spamtrap)</option>
							<option value="9">Add name(s) as (blocked)</option>
							<option value="10">Add MX as (dns not found)</option>
							<option value="11">Add MX as (blocked)</option>
							<option value="12">Add MX as (spamtrap)</option>
							<option value="-1">Delete the specified emails</option>
							<option value="-2">Delete the specified domains</option>
							<option value="-3">Delete the specified names</option>
							<option value="-4">Delete the specified MX'es</option>
							<option value="-5">Check if these records exists</option>
						</select><br/>
						Reason: <input type="text" name="reason"><br/>
						<div class="text-left">
							<button class="btn btn-primary bg-teal">
								Proceed
							</button>
						</div>
					</form>




				</div>





					</div>
{{--				</div>--}}
@endsection
<meta name="csrf-token" content="{{ csrf_token() }}">
<script>
	var i = setInterval(function(){
		jQuery.ajax({
			type:"GET",
			url:"/settings/checkstorage_availability",
			dataType:"json",
			success:function(data) {
				jQuery('#service_status').html(data.answer);
				if (data.online == 1) {
					// it's online, enable the views
					jQuery('#enable_storage').show();
				}
			}
		});
	},2000)
    </script>