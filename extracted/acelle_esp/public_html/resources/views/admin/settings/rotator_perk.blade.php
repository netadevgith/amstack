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
						There you can specify the domains and X number between domain switch. All campaigns tracking information will change automatically during the rotation process.<br>
						{{--@if(isset($settings->current))--}}
							{{--Current domain in rotation: {{ $settings->current }}--}}
						{{--@endif--}}
						<form action="{{ action('Admin\SettingController@rotator_perk') }}" method="POST" class="form-validate-jqueryz">
							<input type="checkbox" id="enabled" name="enabled" @if (isset($settings->enabled) && $settings->enabled == 'on') checked @endif> Enable/Disable This feature.<br/>
							<textarea rows="15" cols="20" name="domains">@if(isset($settings->domains)){!! $settings->domains  !!}@else example.com. @endif</textarea><br>
							{!! csrf_field() !!}
							<br/>
							<label for="interval"></label>
							<input type="number" min="10000" max="10000000" step="5000" name="interval" @if(isset($settings->interval)&&$settings->interval > 0)
							value="{{ $settings->interval }}"
							@else
							value="10000"
                            @endif/> Rotate every X emails sent
							<br/>
							<div class="text-left">

								<button class="btn btn-primary bg-teal">
									Save
								</button>
								<a href="{{ action('Admin\SettingController@rotator_perk_reset') }}" class="btn bg-teal">Reset rotator</a>
							</div>
						</form>


					</div>
				</div>
@endsection

<script>
    </script>