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
<h1>Version</h1>

OS: {{ $data["os"]["pretty_name"] }}<br>
PHP: {{ $data["php"] }}<br>
Laravel: {{ App::VERSION() }}<br>
App version: {{ app_version() }}<br>

		@if(extension_loaded('ionCube Loader'))
			IonCube Extension ({{ ioncube_loader_version() }}) loaded!
		@endif


@endsection
