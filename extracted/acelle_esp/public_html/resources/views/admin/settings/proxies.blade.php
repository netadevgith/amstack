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

<div>
  <input type="checkbox" id="proxy_enabled" name="proxied"
@if ($enabled == 1)
checked
@endif
>
  <label for="proxied">Enable system wide proxy support</label>
</div>


                                        Already built proxies for this deployment:<br>
<div id="message"></div>
                                        <select id="proxies_list" size="50">
@foreach ($proxies as $prox)
@if ($prox == $proxy_ip)
<option value="{{ $prox }}" selected>{{ $prox }}</option>
@else
<option value="{{ $prox }}">{{ $prox }}</option>
@endif
@endforeach
                                        </select><br/>

<input type="button" onclick="proxy_remove()" value="Remove selected">

<div id="progressbar" style="display: none"><img src="/images/91.gif"> Working...</div>
					 <div id="setup_block">
										<input type="button" onclick="replace_input_pubkey()" value="pubkey auth"> @include('helpers.form_control', ['type' => 'text', 'name' => 'server', 'label' => 'Enter Server credentials in format username:password@ip:port', 'value' => $server->host ?? '', 'placeholder' => 'username:password@ip:port'])
										<input type="hidden" name="check" value="1" />
                                         <input type="button" id="setup_proxy" onclick="setup_proxy()" value="Proxy setup...">
                                         </div>
                                        <div id="debug_show" style="display:none">
                                        <textarea rows="15" cols="35" id="debugas"></textarea><br>
                                        </div>





                                </div>
<script>
 var secure_token = '{{ csrf_token() }}';
 function proxy_remove() {
 var selected = $("select#proxies_list").find(':selected').text();
            jQuery.ajax({
                type: "POST",
                data: {serv: selected, _token: secure_token, type: 2 },
                url: "/settings/proxies",
                dataType: "json",
                success: function (data2) {
                    if (data2.error == 0) {
			alert('deleted');
                    } else {
			alert('error removing');
                    }
                    location.reload().delay(3000);
                }
            });
 }

 function setup_proxy() {
            jQuery('div#setup_block').hide();
            jQuery('div#debug_show').hide();
            jQuery('div#progressbar').show();
            var serveris = $("#server").val();
            jQuery.ajax({
                type: "POST",
                data: {serv: serveris, _token: secure_token, type: 1 },
                url: "/settings/proxies",
                dataType: "json",
                success: function (data2) {
                    if (data2.error == 0) {
                        jQuery('textarea#debugas').val(data2.respond);
                        jQuery('div#progressbar').hide();
			jQuery('div#setup_block').show();
                        jQuery('div#debug_show').show();
//                        location.reload().delay(10000);
                    } else {
                        jQuery('textarea#debug_area').val(data2.respond);
                        $("div#debug_show").show();
			jQuery('div#setup_block').show();
                        alert('Error when setting up the server for proxy configuration!');
                    }
                    $("#progressbar").hide();

                }
            });
    }
  function replace_input_pubkey() {
        var numas = "root:key@"+$("#server").val();
                $("#server").val(numas);
        }



function copyToClipboard(text) {
    var $temp = $("<input>");
    $("body").append($temp);
    $temp.val(text).select();
    document.execCommand("copy");
    $temp.remove();
}

jQuery( document ).ready(function() {
    console.log( "ready!" );

$('#proxy_enabled').change(function() {
   var enabled = 0;
   if (!this.checked) {
      enabled = 0;
   } else {
      enabled = 1
   }
    console.log("Proxy status: "+enabled);
    jQuery.ajax({
    type: "POST",
    data: {status: enabled, _token: secure_token, type: 3 },
    url: "/settings/proxies",
    dataType: "json",
    success: function () {
    }
});
});

$('#proxies_list').change(function(){
    var value = $(this).val();
    jQuery('div#message').html('Default proxy changed to: '+value+' and copied to the clipboard');
    jQuery.ajax({
    type: "POST",
    data: {serv: value, _token: secure_token, type: 4 },
    url: "/settings/proxies",
    dataType: "json",
    success: function () {
    }
    });
    copyToClipboard(value);
});
});
</script>
@endsection
