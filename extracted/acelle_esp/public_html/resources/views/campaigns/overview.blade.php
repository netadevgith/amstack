@extends('layouts.frontend')

@section('title', $campaign->name)

@section('page_script')
    <script type="text/javascript" src="{{ URL::asset('assets/js/plugins/visualization/echarts/echarts.js') }}"></script>
    <script type="text/javascript" src="{{ URL::asset('js/chart.js') }}"></script>
@endsection

@section('page_header')

			@include("campaigns._header")

@endsection

@section('content')

            @include("campaigns._menu")

			@include("campaigns._info")

            <br />
            @include("campaigns._realtime")

            <div id="mta_openers_place"></div>
            <input type="button" value="Load mta openers statistics..." onclick="loadcontent3(this,'/campaigns/{{ $campaign->uid }}/overview_mta_openers','mta_openers_place')"/>
            <br />

            <div id="charts_place"></div>
            <input type="button" value="Load statistics..." onclick="loadcontent2(this,'/campaigns/{{ $campaign->uid }}/overview_chart','charts_place','chartas')"/>
{{--            @include("campaigns._chart")--}}

            <div id="platforms_place"></div>
            <input type="button" value="Load platforms..." onclick="loadcontent2(this,'/campaigns/{{ $campaign->uid }}/overview_platforms','platforms_place','platforms')"/>
{{--            @include("campaigns._most_platforms")--}}
            <hr />


            <div id="open_click_rate_place"></div>
            <input type="button" value="Load Open/Click rate..." onclick="loadcontent2(this,'/campaigns/{{ $campaign->uid }}/overview_open_click_rate','open_click_rate_place')"/>
{{--			@include("campaigns._open_click_rate")--}}


{{--            <div id="platforms_count_boxes"></div>--}}
{{--            <input type="button" value="Load count boxes..." onclick="loadcontent2(this,'/campaigns/{{ $campaign->uid }}/overview_count_boxes','platforms_count_boxes')"/>--}}
			@include("campaigns._count_boxes")

            <br />

            @include("campaigns._providers")

            <br />

            <div id="24h_chart_place"></div>
            <input type="button" value="Load 24h chart..." onclick="loadcontent2(this,'/campaigns/{{ $campaign->uid }}/overview_24h_chart','24h_chart_place','24h')"/>
{{--            @include("campaigns._24h_chart")--}}


            <br />

            <div id="top_link_place"></div>
            <input type="button" value="Load top link..." onclick="loadcontent2(this,'/campaigns/{{ $campaign->uid }}/overview_top_link','top_link_place')"/>
{{--			@include("campaigns._top_link")--}}

            <br />


            <div id="click_country_place"></div>
            <input type="button" value="Load top click countries..." onclick="loadcontent2(this,'/campaigns/{{ $campaign->uid }}/overview_click_country','click_country_place','mostclick')"/>
{{--			@include("campaigns._most_click_country")--}}

            <br />

            <div id="open_country_place"></div>
            <input type="button" value="Load top open countries..." onclick="loadcontent2(this,'/campaigns/{{ $campaign->uid }}/overview_open_country','open_country_place','mostopen')"/>
{{--			@include("campaigns._most_open_country")--}}

			<br />

            <div id="open_location_place"></div>
            <input type="button" value="Load top open locations..." onclick="loadcontent2(this,'/campaigns/{{ $campaign->uid }}/overview_open_location','open_location_place')"/>
{{--			@include("campaigns._most_open_location")--}}

            <br />

            <script>
                function loadcontent2(caller, link, destination,chartas) {
                    jQuery.ajax({
                        type:"GET",
                        url: link,
                        dataType:"html",
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        },
                        success:function(data) {
                            jQuery('#'+destination).html(data);
                            var tmpch  = $('#'+chartas);
                            updateChart(tmpch);
                            // $('.chart').each(function() {
                            //     updateChart($(this));
                            // });
                        }
                    });
                    $(caller).hide();
                }
                function loadcontent3(caller, link, destination) {
                    jQuery.ajax({
                        type:"GET",
                        url: link,
                        dataType:"html",
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        },
                        success:function(data) {
                            jQuery('#'+destination).html("Openers count: "+data);
                        }
                    });
                    //$(caller).hide();
                }
            </script>

@endsection