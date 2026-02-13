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
						This tab will help you add amazon ses and sns bounce and complains handler to the both systems for a specified amazon account:<br>
						<form action="{{ action('Admin\SettingController@amazonses') }}" method="POST" class="form-validate-jqueryz">
							<input type="text" placeholder="verified domain" name="domain" />
							<input type="text" placeholder="amazon key" name="amazonkey" />
							<input type="text" placeholder="amazon secret key" name="amazonsecret" />
							{!! csrf_field() !!}
							<div class="text-left">
								<button class="btn btn-primary bg-teal">
									Set Handler
								</button>
							</div>
						</form>







					</div>
				</div>
@endsection

<script>
    </script>