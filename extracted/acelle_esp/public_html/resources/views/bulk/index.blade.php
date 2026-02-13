@extends('layouts.frontend')

@section('title', 'Bulk imap checker')

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
					<span class="text-gear"><i class="icon-list2"></i> Bulk Imap Checker</span>
				</h1>
			</div>

@endsection

@section('content')
				<div class="tabbable">

	
					<div class="tab-content">
Enter the credentials with separator of (:,|,;) one per line.<br>This utility can instantly check the text that contains email users/passwords or the process can be queued in the background processing.
						<form action="{{ action('BulkController@check') }}" method="POST" class="form-validate-jqueryz">
						<textarea id="credentials" rows="20" cols="40" name="credentials">user@email.com:password</textarea><br>
							{!! csrf_field() !!}
							<div class="text-left">
								<button class="btn btn-primary bg-teal">
									Check
								</button>
								<input type="button" onclick="update_action(0)" value="Submit to queue">
								<input type="button" onclick="update_action(1)" value="Clear queue">
							</div>
						</form>


<br>
We currently have {{ $valid_count }} valid entries and {{ $queue_count }} entries in the queue processing...
						<br>
<br>


@if (is_object($valid))
							<font color="#006400">Valid entries:</font>
						<?php
						$count=0;
						?>

	@foreach ($valid as $val)
		<?php $count++;
		?>
								<p><input type="text" value="{{ $val->username }}" id="user_{{ $count }}" class="span12" />
		<button type="button" class="btn btn-info btn-sm" onclick="copyToClipboard('input#user_{{ $count }}')">Copy</button>
		 / <input type="text" value="{{ $val->password }}" id="pass_{{ $count }}" class="span12" />
		<button type="button" class="btn btn-info btn-sm" onclick="copyToClipboard('input#pass_{{ $count }}')">Copy</button>
		</p>
		@endforeach

	@endif




					</div>
				</div>
@endsection
<meta name="csrf-token" content="{{ csrf_token() }}">
<script>
	function copyToClipboard(element) {
		$(element).select();
		document.execCommand("copy");
	}

	function update_action(typ) {
		var cred = $('textarea#credentials').val();
		var data = {type: typ, credentials: cred};
		jQuery.ajax({
			type: "POST",
			data :JSON.stringify(data),
			url: "/bulkchecker/submit",
			headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')},
			contentType: "application/json",
			success: function (msg){
					alert('ok');
			}
		});
	}


</script>