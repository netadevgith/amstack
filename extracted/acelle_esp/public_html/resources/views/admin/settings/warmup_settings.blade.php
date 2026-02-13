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
Warmup default settings:
					<form action="{{ action('Admin\SettingController@warmup_settings') }}" method="POST" class="form-validate-jqueryz">
						{{ csrf_field() }}
					<label for="tracking">Send address:</label>
<input type="text" value="{{ $data["tracking"] }}" name="tracking" placeholder="email@domain.com"><br>
						<label for="name">From name:</label>
						<input type="text" value="{{ $data["name"] }}" name="name" placeholder="John"><br>
					<label for="subject">Subject:</label>
<input type="text" value="{{ $data["subject"] }}" name="subject" placeholder="Subject"><br>
					<label for="text">Email text:</label>
<input type="text" value="{{ $data["text"] }}" name="text" placeholder="email body"><br>
						<button class="btn bg-teal"><i class="icon-check"></i> {{ trans('messages.save') }}</button>
					</form>
					The changes will apply to new warmup pools only, the current running pools will not be affected.
				</div>
@endsection
