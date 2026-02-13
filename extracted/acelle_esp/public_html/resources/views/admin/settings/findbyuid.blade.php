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
<h1>Find contact by uid</h1>
		<div class="form-group">
			<label for="trackurl">Specify contact uid: </label>
			<input type="text" placeholder="" name="contactuid" id="contactuid" />
		</div>
		<button onclick="find_uid()">Search</button><br><br>
		<textarea rows="15" cols="20" id="listas" name="listas" style="display:none"></textarea><br>
		<div id="loading" style="display:none">
			<img src="/images/91.gif"> Loading...
		</div>
			<script>
				function find_uid() {
					var uid = $('#contactuid').val();
					$('#listas').hide();
					$('#loading').show();
                    jQuery.ajax({
                        type:"GET",
                        url:"/settings/finduid/"+uid,
                        dataType:"json",
                        success:function(data) {
                            if (data.succeed == 0) {
                                jQuery('#listas').html('Not found');
                            } else {
                                jQuery('#listas').html(data.email);
                            }
                            $('#loading').hide();
							$('#listas').show();
                        }
                    });
                }
			</script>
@endsection
