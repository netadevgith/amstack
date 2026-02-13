@extends('layouts.frontend')

@section('title', trans('messages.campaigns') . " - " . trans('messages.setup'))
	
@section('page_script')
	<script type="text/javascript" src="{{ URL::asset('assets/js/plugins/forms/styling/uniform.min.js') }}"></script>
		
    <script type="text/javascript" src="{{ URL::asset('js/validate.js') }}"></script>
@endsection

@section('page_header')
	
			<div class="page-title">
				<ul class="breadcrumb breadcrumb-caret position-right">
					<li><a href="{{ action("HomeController@index") }}">{{ trans('messages.home') }}</a></li>
					<li><a href="{{ action("CampaignController@index") }}">{{ trans('messages.campaigns') }}</a></li>
				</ul>
				<h1>
					<span class="text-semibold"><i class="icon-paperplane"></i> {{ $campaign->name }}</span>
				</h1>

				@include('campaigns._steps', ['current' => 2])
			</div>

@endsection

@section('content')
                <form action="{{ action('CampaignController@setup', $campaign->uid) }}" method="POST" class="form-validate-jqueryz">
					{{ csrf_field() }}
					
					<div class="row">
						{{--<div class="col-md-6 list_select_box" target-box="segments-select-box" segments-url="{{ action('SegmentController@selectBox') }}">--}}
						<div target-box="segments-select-box" segments-url="{{ action('SegmentController@selectBox') }}">
							@include('helpers.form_control', ['type' => 'text',
                                                                'name' => 'name',
                                                                'label' => trans('messages.name_your_campaign'),
                                                                'value' => $campaign->name,
                                                                'rules' => $rules,
                                                                'help_class' => 'campaign'
                                                            ])
                                                            
                            @include('helpers.form_control', ['type' => 'text',
                                                                'name' => 'subject',
                                                                'label' => trans('messages.email_subject'),
                                                                'value' => $campaign->subject,
                                                                'rules' => $rules,
                                                                'help_class' => 'campaign'
                                                            ])
                                                            
                            @include('helpers.form_control', ['type' => 'text',
                                                                'name' => 'from_name',
                                                                'label' => trans('messages.from_name'),
                                                                'value' => $campaign->from_name,
                                                                'rules' => $rules,
                                                                'help_class' => 'campaign'
                                                            ])
                            
                            @include('helpers.form_control', ['type' => 'text',
                                                                'name' => 'from_email',
                                                                'label' => trans('messages.from_email'),
                                                                'value' => $campaign->from_email,
                                                                'rules' => $rules,
                                                                'help_class' => 'campaign'
                                                            ])

							@include('helpers.form_control', ['type' => 'text',
                                                              'name' => 'trackurl',
                                                              'label' => 'Custom tracking url (without http://)',
                                                              'value' => $campaign->trackurl,
                                                              'rules' => $rules,
                                                              'help_class' => 'campaign'
                                                          ])
							@include('helpers.form_control', ['type' => 'text',
                                                              'name' => 'auto_pause',
                                                              'label' => 'Pause campaign automatically (After sending number of X emails)',
                                                              'value' => $campaign->auto_pause,
                                                              'rules' => $rules,
                                                              'help_class' => 'campaign'
                                                          ])
                                                            
                            {{--@include('helpers.form_control', ['type' => 'text',--}}
                                                                {{--'name' => 'reply_to',--}}
                                                                {{--'label' => trans('messages.reply_to'),--}}
                                                                {{--'value' => $campaign->reply_to,--}}
                                                                {{--'rules' => $rules,--}}
                                                                {{--'help_class' => 'campaign'--}}
                                                            {{--])--}}
						</div>


						@include('helpers.form_control', ['type' => 'checkbox',
                                                            'name' => 'deferred_enabled',
                                                            'label' => 'Do we handle deferred emails ?',
                                                            'value' => $deferred->enabled,
                                                            'options' => [false,true],
                                                            'help_class' => 'campaign',
                                                            'rules' => $rules
                                                        ])


						@include('helpers.form_control', ['type' => 'text',
                                                              'name' => 'deferred_wait',
                                                              'label' => 'After first deferred email received when we will start to requeue the emails (after X seconds) ?',
                                                              'value' => $deferred->wait,
                                                              'rules' => $rules,
                                                              'help_class' => 'campaign'
                                                          ])



						<div class="col-md-4 list_select_box"
							 target-box="segments-select-box"
							 "
						>
							@include('helpers.form_control', [
                                'name' => 'tracktype',
                                'include_blank' => trans('messages.choose'),
                                'type' => 'select',
                                'label' => 'What tracking type we will use?',
                                'value' => $campaign->tracktype,
                                'options' => ['vienas' => array('text' =>'Old tracking','value' => 0),
                                'du' => array('text' =>'New tracking','value' => 1),
                                'trys' => array('text' =>'Do not use tracking (use external links)','value' => 2),
                                'keturi' => array('text' =>'New tracking v2 (external storage)','value' => 3)],
                                'rules' => isset($rules) ? $rules : []
                            ])

						</div>


<br><br><br><br>
							@include('helpers.form_control', ['type' => 'checkbox',
                                                            'name' => 'tracking_headers',
                                                            'label' => 'Tracking headers in email',
                                                            'value' => $tracking->enabled,
                                                            'options' => [false,true],
                                                            'help_class' => 'campaign',
                                                            'rules' => $rules
                                                        ])
<br>
                            Custom headers:<br>
							<textarea rows="15" cols="45" name="custom_headers">{{ $tracking->headers }}</textarea><br>
                            The headers should be written in the way of <i>header=value</i>, for ex.: <i>return-path=info@domain.com</i><br>
The are also possible to apply the following snippets that generate the randomness: {rndnum[50,60]}, {rndstr[30,40]} you can change numeric values to suit your needs it's readed in the way of [min,max]. There are also a static snippet {date} which outputs the current date/time and timezone.<br>Example.:<br>
<small>Feedback-ID={rndnum[50,60]}:mailer:{rndstr[30,40]}</small>


						<div class="col-md-6 segments-select-box" style="display:none">
                            <div class="form-group checkbox-right-switch">
                                @include('helpers.form_control', ['type' => 'checkbox',
                                                                'name' => 'track_open',
                                                                'label' => trans('messages.track_opens'),
                                                                'value' => $campaign->track_open,
                                                                'options' => [false,true],
                                                                'help_class' => 'campaign',
                                                                'rules' => $rules
                                                            ])
                                
                                @include('helpers.form_control', ['type' => 'checkbox',
                                                                'name' => 'track_click',
                                                                'label' => trans('messages.track_clicks'),
                                                                'value' => $campaign->track_click,
                                                                'options' => [false,true],
                                                                'help_class' => 'campaign',
                                                                'rules' => $rules
                                                            ])
                                
                                @include('helpers.form_control', ['type' => 'checkbox',
                                                                'name' => 'sign_dkim',
                                                                'label' => trans('messages.sign_dkim'),
                                                                'value' => $campaign->sign_dkim,
                                                                'options' => [false,true],
                                                                'help_class' => 'campaign',
                                                                'rules' => $rules
                                                            ])
                            </div>
						</div>
					</div>
					<hr>
					<div class="text-right">
						<button class="btn bg-teal-800">{{ trans('messages.next') }} <i class="icon-arrow-right7"></i> </button>
					</div>
					
				<form>
					
				
@endsection
