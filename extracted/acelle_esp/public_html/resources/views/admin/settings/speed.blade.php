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

                    <form action="{{ action('Admin\SettingController@speed') }}" method="POST" class="form-validate-jqueryz">
						<label>Delay time<span class="text-danger">*</span>
<input id="quota_value" placeholder="" name="quota_value" class="form-control required numeric  numeric" aria-required="true" aria-invalid="false" type="text" value="{{ $settings['speed'] }}">
{{--<label>Time base<span class="text-danger">*</span>--}}
{{--<input id="quota_base" placeholder="" value="1000" name="quota_base" class="form-control required numeric  numeric" default-value="1" aria-required="true" aria-invalid="false" type="text">--}}
{{--<label>Time unit<span class="text-danger">*</span>--}}
{{--<select name="quota_unit" class="select select-search required   required select2-hidden-accessible" aria-required="true" tabindex="-1" aria-hidden="true">--}}
			{{--<option value="">Choose</option>--}}
				{{--<option value="minute">minute</option>--}}
			{{--<option selected="" value="hour">hour</option>--}}
			{{--<option value="day">day</option>--}}
	{{--</select>--}}
{{--<label>--}}
						</label>
	@if ($settings['speed'] > 0)

							<input type="checkbox" name="unlimited" class="styled">
							Unlimited
	@else
							<input type="checkbox" name="unlimited" checked="checked" class="styled">
							Unlimited
		@endif
<p>Delay time (microseconds) on every send, 2000000 for 2s and Checkbox for unlimited sending.</p>
						{!! csrf_field() !!}
						<hr>
						<div class="text-left">
							<button class="btn btn-primary bg-teal">
								{!! trans('messages.save') !!}
							</button>
						</div>
					</form>
					<script>
                        $(function() {
                            $('#quota_value').autocomplete({
                                source: ["10",
                                    "100",
                                    "1000",
                                    "10000",
                                    "100000",
                                    "1000000",
                                    "10000000",
                                    "100000000",
                                    "1000000000",
                                    "10000000000",
                                    "100000000000",
                                    "1000000000000",
                                    "10000000000000",
                                    "100000000000000",
                                    "1000000000000000",
                                    "200000000",
                                    "2000000",
                                    "800000",
                                    "100000"
                                ],
                                minLength: 0
                            });

                            $('#quota_value').focus(function(){
                                $(this).trigger('keydown.autocomplete');
                            });
                        });
					</script>
				</div>
@endsection
