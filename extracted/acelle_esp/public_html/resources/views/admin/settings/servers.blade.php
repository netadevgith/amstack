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
						@if ($error == 1 && isset($error_msg) && $error_msg != "")
							<h1 style="color: darkred">ERROR</h1>
							<h3 style="color: red">{{ $error_msg }}</h3>
							@endif


<h1>Servers creation tool</h1>



						<p>It helps to create Single or MultiInstance Postfix servers on the Plain VPS/KVM/VDS or DS type of server. It should meet the minimum requirements of running Debian 8/9 (Jessie/Stretch) or Ubuntu 16.04 (Xenial) releases. Have as much as 256Mb RAM and half of one CPU core with as much as 1gb of free HDD space.</p>

						<h2>Check compatibility</h2>
						<!-- form -->
						@if (is_object($server)&& isset($server->valid) && $server->valid == 1)
							<script>
                           var server = {hostname:"{{ $server->hostname }}", user:"{{ $server->username }}", password:"{{ $server->password }}", port:"{{ $server->port }}", os:"{{ $server->os }}", multi:"{{ $server->multi }}", tracking: "", sendaddress: ""};
                           var serveriai = {};
                           var pmta = 0;
							</script>


							<p>Server has been verified and is almost compatible with our requirements, please select the further options for server installation</p>
							@if (isset($server->msg))
								<small>Details: {{ $server->msg }}</small>
								@endif
							<br/>
							<br/>
							<br/>
								<label for="Property Select" class="title" id="label_domain_select">Select domain for DNS generation:</label>
								<select name="domain_select" id="domain_selector">
							<?php
                                    $cloudflare = new Acelle\Library\CloudFlareHelper();
                                    if (\Config::get('app.cloudflare_enabled') == true) {
                                    $domains = $cloudflare->get_domains();

                                    // some filtration
                                    $bl = $cloudflare->returnbl();

                                    foreach($domains as $key => $value){ ?>
                                    @if ($cloudflare->recursive_array_search($value->name,$bl) !== false)
                                        <option style="color:red" value="{{ $value->id }}">{{ $value->name }}</option>
                                    @else
                                        <option value="{{ $value->id }}">{{ $value->name }}</option>
                                    @endif
                                    <?php }} ?>
								</select>
							<br/>
								<input type="button" id="dns_button" onclick="initialize_dns('{{ $server->hostname }}')" value="Initialize DNS">
							    <br/>
                            @if (isset($server->multi)&&$server->multi == 1)
                                <div id="multistep0">
                                We also detected that this server are able to run multi postfix or PowerMTA configuration because it has many ip addresses<br/>
                                    <input type="button" id="multipost" onclick="initialize_multipostfix('{{ $server->hostname }}')" value="I will go with multi">
                                </div>
                                <div id="multistep1" style="display:none">
                                <br/>
                                    Here you will need to specify the domains (separated by spaces), that we will use for mail server dns generation:<br/>
                                    <input type="text" id="domains_for_dns" name="domains_for_dns" placeholder="domain1.info domain2.info domain3.info">
                                    <input type="button" id="multi_start" onclick="setup_multipostfix('{{ $server->hostname }}')" value="Start MultiPostfix setup...">
                                    <input type="button" id="multi_start2" onclick="setup_powermta('{{ $server->hostname }}')" value="Start PowerMTA setup...">
                                    <br/>
                                    <input type="checkbox" name="warmupcheck" id="warmupcheck" onclick="togglewarmup()">Enable warmup on this server<br/>
                                    <div id="warmupstep0" style="display:none">
                                        <input type="text" id="warmup_name" name="warmup_name" placeholder="warmup pool name">
                                        <input type="text" id="warmup_email" name="warmup_email" placeholder="warmup test email">
                                        <!-- removable content -->
                                        <div id="phrases">
                                            <div class="phrases_fields"><label for="perh">Per hour:</label><input type="number" id="perh" name="perh" class="blocknumeric" value="120">
                                                <label for="totalas">Total sendout:</label><input type="number" id="totalas" name="totalas" class="blocknumeric" value="10000">
                                            </div>
                                        </div>
                                        <input type="button" value="Add phase" onclick="addphase()">
{{--                                        <input type="button" value="Save" onclick="save()">--}}
                                        <!-- removable content -->
                                    </div>
                                </div>
                                <div id="multistep2" style="display:none">
                                    <img src="/images/91.gif"> Creating server... This will take up to 20 minutes...
                                </div>
                                <div id="multistep3" style="display:none">
                                </div>
                                <!-- test -->



                                    {{--                                   <table border="1">';--}}
{{--                                       <tr><th>IP</th><th></th><th>rDNS</th><th></th><th>Send Address<th>Tracking domain</th></tr>--}}
{{--                                       <tr><td id="ip1"></td><td><button onclick="copyToClipboard('#ip1')">Copy</button></td>--}}
{{--                                       <td id="dns1">reversas1</td><td><button onclick="copyToClipboard('#dns1')">Copy</button></td>--}}
{{--                                           <td><input type="text" name="serverip_sendaddress" data-ip="164.92.141.133" data-type="sendaddress"></td>--}}
{{--                                           <td><input type="text" name="serverip_tracking_domain" data-ip="164.92.141.133" data-type="tracking"></td>--}}
{{--                                       </tr>--}}
{{--                                       <tr><td id="ip2"></td><td><button onclick="copyToClipboard('#ip2')">Copy</button></td>--}}
{{--                                           <td id="dns2">reversas1</td><td><button onclick="copyToClipboard('#dns2')">Copy</button></td>--}}
{{--                                           <td><input type="text" name="serverip_sendaddress" data-ip="164.92.141.131" data-type="sendaddress"></td>--}}
{{--                                           <td><input type="text" name="serverip_tracking_domain" data-ip="164.92.141.131" data-type="tracking"></td>--}}
{{--                                       </tr>--}}
{{--                                   </table>--}}
{{--                                    <input type="button" value="Update server info" onclick="updatesrv()">--}}

                                <div id="warmup_msg" style="display:none">

                                </div>
                                    <div id="multistep4" style="display:none">
                                       <input type="checkbox" name="masschange" id="masschange">Enable mass send address/tracking change per server<br/>
                                       <input type="text" id="domains_for_tracking" name="domains_for_tracking" placeholder="domain1.info domain2.info domain3.info">
                                       <p>The changes will take effect upon add to the specified deployment!</p>
                                        @if (count($appliances) > 0)
                                            <h3>Add servers to app deployments:</h3>
                                            @foreach($appliances as $appl)
                                                <input type="button" id="multi_inject_button" onclick="inject_multiserver('{{ $appl }}')" value="Add to {{ $appl }}">
                                            @endforeach

                                        @endif
                                    </div>
                                @endif
								<textarea rows="4" cols="50" id="dns_status" style="display: none"></textarea>
							<br/>
								<input type="button" id="serverup" disabled style="display: none" onclick="setup_server('{{ $server->hostname }}')" value="Setup Server"> <div id="progressbar" style="display: none"><img src="/images/91.gif"> Working...</div>
<br/>
								<textarea rows="4" cols="50" id="setup_status" style="display: none"></textarea>
<br/>
							<div id="testblock" style="display:none">
								<input type="text" id="test_mail" name="test_mail" placeholder="name@domain.com">
								<input type="button" id="test_button" onclick="test_server()" value="Delivery Report">
							     <div id="delivery_status"></div>
								<textarea rows="4" cols="50" id="test_status"></textarea>
							</div>

							<div id="serveraddblock" style="display:none">
							@if (count($appliances) > 0)
								<h3>Add server to app deployments:</h3>
								@foreach($appliances as $appl)
								<input type="button" id="inject_button" onclick="inject_server('{{ $appl }}')" value="Add to {{ $appl }}">
								@endforeach

								@endif
							</div>

						@else
						<form action="" method="POST" class="form-validate-jqueryz">
							{{ csrf_field() }}
							<div class="sub_section">
								<div class="row">

									<div class="col-md-6">
										<input type="button" onclick="replace_input_pubkey()" value="pubkey auth"> @include('helpers.form_control', ['type' => 'text', 'name' => 'server', 'label' => 'Enter Server credentials in format username:password@ip:port', 'value' => $server->host ?? '', 'placeholder' => 'username:password@ip:port'])
										<input type="hidden" name="check" value="1" />
									</div>
								</div>

								<div class="text-left">
									<button class="btn bg-teal"><i class="icon-check"></i>Check</button>
                                    <button class="btn bg-teal" id="btnshowips"><i class="icon-add"></i> Add More IPs</button>
								</div>

							</div>
						</form>
                        <div id="ipsdash" style="display:none;">
                            <b>To add more ip addresses to the server please specify it in the following syntax:</b>
                            <ul>
                                <li>1.1.1.1/24 - Using prefix /24 (will add ips from 1.1.1.1 to 1.1.1.254)</li>
                                <li>1.1.1.1-1.1.1.10 - Using range from-to using separator <b>-</b> (will increment last ip number from 1 to 10)</li>
                                <li>1.1.1.1 2.2.2.2 3.3.3.3 - Using space separator will add specified ip addresses only (useful when the ip adresses are not from the same subnet)</li>
                            </ul>
                            <input id="moreips" placeholder="1.1.1.1/24" value="" type="text" name="moreips" class="form-control">
                                <button class="btn bg-teal" id="addips"><i class="icon-add-to-list"></i> Execute addition</button>
                        </div>

						@endif
						<!-- /form  -->






					</div>
				</div>


<script>
    var secure_token = '{{ csrf_token() }}';
    var wrapper = jQuery("#phrases");
    //var server;
    //var subdomain;

    function setup_powermta(ip) {
        var domenai = jQuery('input#domains_for_dns').val()
        pmta = 1;
        if (domenai != "") {
            jQuery('div#multistep1').hide();
            jQuery('div#multistep2').show();
            jQuery.ajax({
                type: "POST",
                data: {serv: server, _token: secure_token, domens: domenai, pmta: pmta},
                url: "/settings/setup_server",
                dataType: "json",
                success: function (data2) {
                    if (data2.error == 0) {
                        jQuery('textarea#setup_status').val(data2.respond);
                        jQuery('div#multistep2').hide();
                        // load reverse dns data to table
                        var table_body = '<b>Setup have been complete!</b></br>Below are the reverse dns configuration for your dns setup:</br><table border="1">';
                        table_body+='<tr><th>IP</th><th></th><th>rDNS</th><th></th></tr>';
                        var count = 0;
                        for (var k in data2.reverses) {
                            count++;
                            table_body +='<tr><td id="ip'+count+'">';
                            table_body += k+ '</td><td><button onclick="copyToClipboard(\'#ip'+count+'\')">Copy</button></td>';
                            table_body +='<td id="dns'+count+'">'+data2.reverses[k][0]+'</td><td><button onclick="copyToClipboard(\'#dns'+count+'\')">Copy</button></td></tr>';
                            serveriai[k] = {ip: k,dns: data2.reverses[k][0]};
                        }
                        table_body+='</table>';
                        $('div#multistep3').html(table_body);
                        jQuery('div#multistep3').show();
                        jQuery('div#multistep4').show();
                    } else {
                        jQuery('textarea#setup_status').val(data2.respond);
                        $("textarea#setup_status").show();
                        alert('Error when setting up the server for PowerMTA configuration!');
                    }
                    $("#progressbar").hide();

                },
                error: function (request, status, error) {
                    alert('We have trouble, see logs!');
                }
            });
            //alert(ip);
        } else {
            alert('You have not specified any of domains for PowerMTA configuration setup!');
        }
    }

    function setup_multipostfix(ip) {
        var domenai = jQuery('input#domains_for_dns').val()
        var warmup_enabled = false;
        warmup_enabled = jQuery('input#warmupcheck').is(':checked');
        warmup_name = jQuery('input#warmup_name').val();
        warmup_email = jQuery('input#warmup_email').val();
        var duom = {};
        var count = 1;
        $('.phrases_fields').each(function(i, obj) {
            var perh = $(obj).find("input[name='perh']").val();
            var total = $(obj).find("input[name='totalas']").val();
            duom[count] = { perh: perh, total: total };
            count++;
        });
        if (domenai != "") {
            jQuery('div#multistep1').hide();
            jQuery('div#multistep2').show();
            jQuery.ajax({
                type: "POST",
                data: {serv: server, _token: secure_token, domens: domenai, pmta: 0, warmup: warmup_enabled, warmupname: warmup_name, warmupemail: warmup_email, phrases: JSON.stringify(duom)},
                url: "/settings/setup_server",
                dataType: "json",
                success: function (data2) {
                    if (data2.error == 0) {
                        jQuery('textarea#setup_status').val(data2.respond);
                        jQuery('div#multistep2').hide();
                        // load reverse dns data to table
                        /*

                                        <tr><th>IP</th><th></th><th>rDNS</th><th></th><th>Send Address<th>Tracking domain</th></tr>
                                       <tr><td id="ip1"></td><td><button onclick="copyToClipboard('#ip1')">Copy</button></td>
                                       <td id="dns1">reversas1</td><td><button onclick="copyToClipboard('#dns1')">Copy</button></td>
                                           <td><input type="text" name="serverip_sendaddress" data-ip="164.92.141.133" data-type="sendaddress"></td>
                                           <td><input type="text" name="serverip_tracking_domain" data-ip="164.92.141.133" data-type="tracking"></td>
                                       </tr>
                                   </table>
                                    <input type="button" value="Update server info" onclick="updatesrv()">


                         */
                        var table_body = '<b>Setup have been complete!</b></br>Below are the reverse dns configuration for your dns setup:</br><table border="1">';
                        table_body+='<tr><th>IP</th><th></th><th>rDNS</th><th></th><th>Send Address<th>Tracking domain</th></tr>';
                        var count = 0;
                        for (var k in data2.reverses) {
                            count++;
                            table_body +='<tr><td id="ip'+count+'">';
                            table_body += k+ '</td><td><button onclick="copyToClipboard(\'#ip'+count+'\')">Copy</button></td>';
                            table_body +='<td id="dns'+count+'">'+data2.reverses[k][0]+'</td><td><button onclick="copyToClipboard(\'#dns'+count+'\')">Copy</button></td>';
                            table_body += '<td><input type="text" name="serverip_sendaddress" data-ip="'+k+'" data-type="sendaddress"></td>';
                            table_body += '<td><input type="text" name="serverip_tracking_domain" data-ip="'+k+'" data-type="tracking"></td>';
                            table_body += '</tr>';
                            serveriai[k] = {ip: k,dns: data2.reverses[k][0]};
                        }
                        table_body+='</table>';
                        table_body+='<input type="button" value="Update server info" onclick="updatesrv()">';
                        table_body+='<p>Note! This only works on local deployment, you can leave the blank fields and use the one below.</p><br>';
                        $('div#multistep3').html(table_body);
                        jQuery('div#multistep3').show();
                        jQuery('div#multistep4').show();
                        console.log("setup finished!");
                        if (warmup_enabled) {
                            jQuery('div#warmup_msg').html(data2.warmupmsg);
                            jQuery('div#warmup_msg').show();
                        }
                    } else {
                        jQuery('textarea#setup_status').val(data2.respond);
                        $("textarea#setup_status").show();
                        alert('Error when setting up the server for multi postfix configuration!');
                    }
                    $("#progressbar").hide();

                },
                error: function (request, status, error) {
                    alert('We have trouble, see logs!');
                }
            });
            //alert(ip);
        } else {
            alert('You have not specified any of domains for multi postfix configuration setup!');
        }
    }

    function copyToClipboard(element) {
        var $temp = $("<input>");
        $("body").append($temp);
        $temp.val($(element).text()).select();
        document.execCommand("copy");
        $temp.remove();
    }



    function initialize_multipostfix(ip) {
        jQuery('input#dns_button').hide();
        jQuery('label#label_domain_select').hide();
        jQuery('select#domain_selector').hide();
        jQuery('div#multistep0').hide();
        jQuery('div#multistep1').show();
        //alert('test');
    }

    function initialize_dns(ip) {
        var domain_id = $('select#domain_selector').val();
        var domain = $("select#domain_selector option:selected").text();
        jQuery('textarea#setup_status').val('');
        jQuery('textarea#dns_status').val('');
        jQuery.ajax({
            type:"POST",
			data: { domain_id: domain_id, domain: domain, ip: ip, _token: secure_token },
            url:"/settings/initialize_dns",
            dataType:"json",
            success:function(data) {
                if (data.status == 1) {
                    jQuery('textarea#dns_status').val(data.respond);
                    jQuery('input#dns_button').hide();
                    $("textarea#dns_status").show();
                    $("input#serverup").show();
                    server.dns = data.subdomain;
                    document.getElementById("serverup").disabled = false;
                } else {
                    alert('Some error accoured: '+data.error);
				}

                }
            });
	}

	function test_server() {
        var email = $("#test_mail").val();
        $("#progressbar").show();
     //   alert(email);
        jQuery.ajax({
            type:"POST",
            data: { serv: server, email: email, _token: secure_token },
            url:"/settings/test_server",
            dataType:"json",
            success:function(data4) {
                $("#test_status").show();
                if (data4.status == 1) {
                    $("#test_status").val(data4.respond);
                    $("#delivery_status").html("Delivery was successfull: "+data4.msg);
                } else {
                    $("#delivery_status").html("Delivery failed: "+data4.msg);
                   // alert('Error when testing the server!');
                    $("#test_status").val(data4.respond);
                }
                $("#progressbar").hide();
            }
        });
	}

	function inject_server(deployment) {
        $("#progressbar").show()
        jQuery.ajax({
            type:"POST",
            data: { serv: server, depl: deployment, _token: secure_token },
            url:"/settings/inject_server",
            dataType:"json",
            success:function(data3) {
                if (data3.status == 1) {
                   alert(data3.msg);
                } else {
                    alert('Error when injecting the server!');
                }
                $("#progressbar").hide();
            }
        });
	}

	function inject_multiserver(deployment) {
        masschange_enabled = jQuery('input#masschange').is(':checked');
        var domenai = jQuery('input#domains_for_tracking').val().split(' ');
        if (masschange_enabled == true) {
            if (domenai.length == Object.keys(serveriai).length) {

            } else {
                console.log("Servers: "+Object.keys(serveriai).length+" domains: "+domenai.length);
                alert('There must be some domains missing? You need one domain per server!');
                return;
            }
        }
        var count = 0;

        for (var k in serveriai) {
            count++;
            //console.log('Got server ip: '+serveriai[k].ip+' dns: '+serveriai[k].dns);
            var tempserv = server;
            tempserv.hostname = serveriai[k].ip;
            tempserv.dns = serveriai[k].dns;
            // some addition 2022.09.14
            if (masschange_enabled == true) {
                var domenas = FindInArray(domenai, count - 1);
                tempserv.sendaddress = 'info@'+domenas;
                tempserv.tracking = domenas;
                //console.log("selected domain: " + domenas+ " for server: "+tempserv.hostname);
            }

            var datosas;
            var errors = 0;
            var ok = 0;
            if (pmta > 0) {
                datosas = { serv: tempserv, depl: deployment, _token: secure_token, pmta: 1 }
            } else {
                datosas = { serv: tempserv, depl: deployment, _token: secure_token, pmta: 0 };
            }
            jQuery.ajax({
                    type:"POST",
                    data: datosas,
                    url:"/settings/inject_server",
                    dataType:"json",
                    success:function(data3) {
                        if (data3.status == 1) {
                            ok++;
                        } else {
                            errors++;
                        }
                    }
                });
        }
        alert('All servers have been injected in deployment: '+deployment+' errors: '+errors+' ok: '+ok);
    }

    function setup_server(host) {
		server.multi = 0;
      //  alert('setting up server on: '+server.dns);
        $("#progressbar").show();
        jQuery.ajax({
            type:"POST",
            data: { serv: server, _token: secure_token },
            url:"/settings/setup_server",
            dataType:"json",
            success:function(data2) {
                if (data2.error == 0) {
                 //   jQuery('input#serverup').hide();
					//alert(data2.respond);
                    jQuery('textarea#setup_status').val(data2.respond);
                    $("textarea#setup_status").show();
                    $("#serveraddblock").show();
                    $("#testblock").show();
                    $("#delivery_status").html("");
                } else {
                    jQuery('textarea#setup_status').val(data2.respond);
                    $("textarea#setup_status").show();
                    alert('Error when setting up the server!');
				}
                $("#progressbar").hide();
                // jQuery('textarea#dns_status').html(data.respond);
                // if (data.status == 1) {
                //     $("textarea#dns_status").show();
                //     server.dns = data.subdomain;
                //     document.getElementById("serverup").disabled = false;
                // } else {
                //     alert('Some error accoured: '+data.error);
                // }

            }
        });
	}

	function replace_input_pubkey() {
        var numas = "root:key@"+$("#server").val();
		$("#server").val(numas);
	}

    $( "#btnshowips" ).click(function( event ) {
        event.preventDefault();
        $('#ipsdash').show();
    });

    $( "#addips" ).click(function( event ) {
        event.preventDefault();
        var ipai = $('#moreips').val();
        var serverys = $('#server').val();
            jQuery.ajax({
                type: "POST",
                data: {serv: serverys, _token: secure_token, ipai: ipai},
                url: "/settings/setup_ips",
                dataType: "json",
                success: function (data2) {
                    if (data2.error == 0) {
                        alert('Everything went ok: '+data2.respond);
                        $('#ipsdash').hide();
                    }
                    if (data2.error == 1) {
                        alert('Error: '+data2.respond);
                    }
                },
                error: function (request, status, error) {
                    alert('We have trouble, see logs!');
                }
            });
    });


    function togglewarmup() {
        var checkbox = $("#warmupcheck");
        var ssection = $("#warmupstep0");
        if (checkbox.is(':checked')) {
            ssection.show();
        } else {
            ssection.hide();
        }
    }


    function save() {
        var duom = {};
        var count = 1;
        $('.phrases_fields').each(function(i, obj) {
            var perh = $(obj).find("input[name='perh']").val();
            var total = $(obj).find("input[name='totalas']").val();
            duom[count] = { perh: perh, total: total };
            count++;
        });
        console.log(duom);
        jQuery.ajax({
            type: "POST",
            data: { data: JSON.stringify(duom), _token: secure_token },
            url: "/settings/warmup_test_test",
            dataType: "json",
            success: function (data2) {
                if (data2.status != 1) {
                    alert('some error');
                }
            },
            error: function (request, status, error) {
                alert('We have trouble, see logs!');
            }
        });
    }

    function addphase() {
        jQuery(wrapper).append('<div class="phrases_fields"><label for="perh">Per hour:</label>'+
            '<input type="number" id="perh" name="perh" class="blocknumeric">'+
            ' <label for="totalas">Total sendout:</label>'+
            '<input type="number" id="totalas" name="totalas" class="blocknumeric">'+
            '<a href="#" class="delete">Delete</a></div>');
    }

    function FindInArray(arr, ind) {
        var dom;
        jQuery.each( arr, function( index, value ) {
            if (ind == index) {
                dom = value;
            }
        });
        return dom;
    }

    function bigtest() {
        var server_ipcount = 5;
        masschange_enabled = jQuery('input#masschange').is(':checked');
        var domenai = jQuery('input#domains_for_tracking').val().split(' ');
        var domenas = FindInArray(domenai,2-1);
        console.log("domain count: "+domenai.length);
        console.log("second domain: "+domenas);
        if (masschange_enabled == true) {
                if (domenai.length == server_ipcount) {
                    alert('Everything ok');
                } else {
                    alert('There must be some domains missing? You need one domain per server!');
                }
        }
    }

    function updateserverinfo(ip, type, val) {
       // alert("Server: "+ip+ "type: "+type+" val: "+val);
        jQuery.ajax({
            type:"POST",
            url:"/settings/setserverinfo",
            dataType:"json",
            data: { ip: ip, type: type, val: val, _token: secure_token },
            success: function () {
              console.log("pass ok");
            },
            error: function (request, status, error) {
                console.log('We have trouble, see logs!');
                console.log(error);
            }
        });
    }

    function updatesrv() {
        $("input[name^='serverip_']").each(function() {
            var ip = $(this).data("ip");
            var type = $(this).data("type");
            var value = $(this).val();
            updateserverinfo(ip,type,value);
            //console.log($(this).val())
        });
        alert("Information updated!");
    }

    //jQuery('.blocknumeric').keyup(function(e) {
    jQuery('body').on('keyup', 'input.blocknumeric', function() {
        this.value=this.value.replace(/[^\d]/,'');
    });

    jQuery(wrapper).on("click", ".delete", function(e) {
        e.preventDefault();
        jQuery(this).parent('div').remove();
    });

    </script>
@endsection
