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
<h1>Campaigns Realtime data</h1>
	<table class="table table-box pml-table">
		<tbody>
		@foreach ($campaigns as $camp)
			<tr>
				<td style="width: 40%; height: 21px;">
					<span class="no-margin text-teal-800 stat-num">Campaign: {{ $camp->name }} UID: {{ $camp->uid }} ID: {{ $camp->id }}</span><br />
					<span class="no-margin text-teal-800 stat-num">Statistics: </span><span id="{{ $camp->uid }}_stats"></span><br />
					<span class="no-margin text-teal-800 stat-num" style="float: left">Servers: </span><div style="float: left"></div><span id="{{ $camp->uid }}_servers"></span>
				</td>
			</tr>
			<script>
                var i = setInterval(function(){
                    jQuery.ajax({
                        type:"GET",
                        url:"/settings/realtime_camp/{{ $camp->uid }}",
                        dataType:"json",
                        success:function(data) {
                            if (data.status == 'Not sending') {
                                jQuery('#{{ $camp->uid }}_stats').html('Not sending');
                            } else {
                                var statsai;
                                var servai = '';
                                var srvcount = 0;
                                var srvalive = 0;
                                var srvdead = 0;
                                statsai = 'Total: '+data.total+' Sent: '+data.counter+' Opens: '+data.openers+' Clicks: '+data.clickers;
                                jQuery('#{{ $camp->uid }}_stats').html(statsai);
                                $.each(data.servers, function(i1, val){
                                    srvcount++;
                                    if (val.running == 1) {
                                        srvalive++;
                                        servai += '<div style="float: left" title="'+val.host+':'+val.smtp_port+' server is running in the background" class="icon-server lubos"></div>';
                                    } else {
                                        srvdead++;
                                        servai += '<div style="color: red; float: left" id="{{ $camp->uid }}_'+val.host+'" title="'+val.host+':'+val.smtp_port+' server is not running" class="icon-server"></div>';
                                    }
                                });
                                servai += '<div style="clear: both"></div>Total: '+srvcount+' Dead: '+srvdead+' Alive: '+srvalive;
                                jQuery('#{{ $camp->uid }}_servers').html(servai);
                                $('div[title]').qtip();
                            }


                        }
                    });
                },2000)
			</script>
		@endforeach
		</tbody>
	</table>

	<h1>Redis Background Queues</h1>

	<div id="redis_data"></div>

	</div>

	<script>
        var i = setInterval(function(){
            jQuery.ajax({
                type:"GET",
                url:"/settings/realtime_redis",
                dataType:"json",
                success:function(data2) {
                    var queue_count = 0;
                    var queues = '';
                    $.each(data2, function(i1, val) {
                        queue_count++;
                        if (typeof val != 'undefined' && val) {
                            queues += '<p title="'+val.data.command+'">Queued command: '+val.data.commandName+' attempts: '+val.attempts+' </p>';
                        }
                    });
                    jQuery('#redis_data').html(queues);
                    $('p[title]').qtip();
                }
            });
        },5000)
        $(document).ready(function () {
            $('p[title]').qtip();

        });


        $( ".lubos" ).tooltip({
            position: { my: "right-5 center", at: "left center", collision: "flipfit" }
        });
	</script>
@endsection
