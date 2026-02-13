@extends('layouts.frontend')

@section('title', trans('messages.settings'))

@section('page_script')
    <script type="text/javascript" src="{{ URL::asset('assets/js/core/libraries/jquery_ui/interactions.min.js') }}"></script>
	<script type="text/javascript" src="{{ URL::asset('assets/js/core/libraries/jquery_ui/touch.min.js') }}"></script>
	<script type="text/javascript" src="http://creativecouple.github.com/jquery-timing/jquery-timing.min.js"></script>
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

						<div id="tail" style="border: 1px solid blue; height: 500px; width: 1200px; overflow:scroll;overflow-x:hidden; font-size: 9px;">Starting up...</div>

						<select id="logas">
							<option value="mail.log">Mail log</option>
							<option value="laravel.log">Laravel debug</option>
							<option value="open.log">Openers log</option>
							<option value="click.log">Clickers log</option>
						</select>

					</div>
				</div>
				<script>
					$(document).ready(function () {
					var logfile = "mail.log";
					$("#logas").change(function (eventa) {
						logfile = $(this).val();
						$('#tail').html('');
						console.log($(this).val());
					});
					jQuery(function() {
						jQuery.repeat(1000, function() {
							jQuery.get('/settings/readlog/'+logfile, function(data) {
								$('#tail').append(data);
								$('#tail').scrollTop($('#tail')[0].scrollHeight);
								//window.scrollTo(0,document.body.scrollHeight);
								// needs to implement checkbox autoscroll
							});
						});
					});
					});
				</script>
@endsection

