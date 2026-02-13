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

<br>
						<h3>Test mail lists cache generation:</h3>
						{{--<p>Proxy IP: {{ $proxyip }}</p>--}}
						<ul class="list-group">
							@if (is_object($lists))
@foreach($lists as $list)
<li class="list-group-item list-group-item-warning">{{ $list->name }} ({{ $list->uid }}) <input type="button" onclick="update_cache(1,3,'{{ $list->uid }}')" value="Update cache prior 3">
	<input type="button" onclick="update_cache(1,2,'{{ $list->uid }}')" value="Update cache prior 2">
	<input type="button" onclick="update_cache(1,1,'{{ $list->uid }}')" value="Update cache prior 1">
</li>
{{--list-group-item-success--}}
{{--@else--}}

{{--@endif--}}
		{{--">--}}
	{{--{{ $domain->dns }} - {{ $domain->name }} <input type="button" onclick="delete_domain('{{ $domain->name }}')" value="X"></li>--}}
	@endforeach
								<li class="list-group-item list-group-item-info"><input type="button" onclick="update_cache(500,2,'0')" value="Redis cleanup"></li>
</ul>
						@endif
					<h3>Test campaigns cache generation:</h3>
					<ul class="list-group">
@if (is_object($campaigns))
							@foreach($campaigns as $camp)
								<li class="list-group-item list-group-item-warning">{{ $camp->name }} ({{ $camp->uid }}) <input type="button" onclick="update_cache(2,3,'{{ $camp->uid }}')" value="Update cache prior 3">
									<input type="button" onclick="update_cache(2,2,'{{ $camp->uid }}')" value="Update cache prior 2">
									<input type="button" onclick="update_cache(2,1,'{{ $camp->uid }}')" value="Update cache prior 1">
								</li>
	@endforeach
					</ul>
	@endif








					</div>
				</div>
@endsection
<meta name="csrf-token" content="{{ csrf_token() }}">
<script>
    function update_cache(type,prior,uid) {
		var data = {type :type, priority: prior,val:uid};
		jQuery.ajax({
			type: "POST",
			data :JSON.stringify(data),
			url: "/settings/taskrunner",
			headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')},
			contentType: "application/json",
			success: function (msg){
		//	alert('Queue sent!');
			}
		});
    }
    </script>