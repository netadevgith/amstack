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

                    <form action="{{ action('Admin\SettingController@controller') }}" method="POST" class="form-validate-jqueryz">
						<label style="width: 100%;">Option A keywords<span class="text-danger">*</span>
<input id="quota_value" placeholder="" name="a_keyword" class="form-control required" aria-required="true" aria-invalid="false" type="text" value="{{ $settings['a_keyword'] }}">

						</label>
<p>Write text that will be used for postfix parser to pause the campaign automatically, then the new sending domain will be set from the below and campaigns will be resumed. Few items can be separated by <b>|</b>.</p>
						<textarea name="domains" rows="10" cols="30">{{ $settings['domains'] }}</textarea><br><br>
						<label style="width: 100%;">Option B keywords<span class="text-danger">*</span>
						<input id="b_keyword" placeholder="" name="b_keyword" class="form-control required" aria-required="true" aria-invalid="false" type="text" value="{{ $settings['b_keyword'] }}">
						<p>Write text that will be used for postfix parser to pause campaign automatically, without the resume function, few items can be separated by <b>|</b>.</p>
						<hr>
						{!! csrf_field() !!}
						<p>After save button is pressed the parsing daemon will be restarted (should take 2 seconds or more)</p>
						<div class="text-left">
							<button class="btn btn-primary bg-teal">
								{!! trans('messages.save') !!}
							</button>
						</div>
					</form>
				</div>
@endsection
