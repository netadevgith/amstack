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
	
					<div class="tab-content">
There you can import all your domains, first point these domains Name servers to: ns1.yourdomain, ns2.yourdomain. Then write them down one per line.<br>
						<form action="{{ action('Admin\SettingController@dns') }}" method="POST" class="form-validate-jqueryz">
						<textarea rows="15" cols="20" name="domain_list">example.com.</textarea><br>
							{!! csrf_field() !!}
							<div class="text-left">
								<button class="btn btn-primary bg-teal">
									Import
								</button>
							</div>
						</form>

<br>
						Set ip to all domains (cloudflare proxy mode)<br>
						<input type="text" placeholder="ip" name="cloudflareip" id="cloudflareip" />
						<button id="set" onclick="update_cf()">Set</button>
<br>
						Set ip to all domains (cloudflare non proxy mode, standard A record)<br>
                                                <input type="text" placeholder="ip" name="cloudflareip2" id="cloudflareip2" />
                                                <button id="set" onclick="update_cf2()">Set</button>

						<script>
							function update_cf() {
								window.location='/update-cf-mass/' +
										encodeURIComponent(document.getElementById('cloudflareip').value);
								$('#set').hide();
							}
						        function update_cf2() {
                                                                window.location='/update-cfa-mass/' +
                                                                                encodeURIComponent(document.getElementById('cloudflareip2').value);
                                                                $('#set').hide();
                                                        }
						</script>

{{--						<h3>Already imported domains:</h3>--}}
{{--						<p>Proxy IP: {{ $proxyip }}</p>--}}
{{--						<ul class="list-group">--}}
{{--							@if (is_array($domains))--}}
{{--@foreach($domains as $domain)--}}
{{--<li class="list-group-item @if($domain->dns == $proxyip)--}}
{{--list-group-item-success--}}
{{--@else--}}
{{--list-group-item-warning--}}
{{--@endif--}}
{{--		">--}}
{{--	{{ $domain->dns }} - {{ $domain->name }} <input type="button" onclick="delete_domain('{{ $domain->name }}')" value="X"></li>--}}
{{--	@endforeach--}}
{{--</ul>--}}
{{--						@endif--}}









					</div>
				</div>
@endsection

<script>
    function delete_domain(domain) {
        window.location='/delete-domain/' + domain;
    }
    </script>
