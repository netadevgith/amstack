<h3>Deliverability issues</h3>
<ul>
    <li>Sent: <span id="{{ $campaign->uid }}_sent"></span> <a onclick="exportas(1)" style="display:none" id="down_data">download data</a></li>
    <li>Bounced: <span id="{{ $campaign->uid }}_bounce"></span> <a onclick="exportas(2)" style="display:none" id="down_data2">download data</a></li>
    <li>Deferred: <span id="{{ $campaign->uid }}_deferred"></span></li>
    <li id="deferred_enabled" style="display: none">Deferred handling enabled, already sent from deferred list: <span id="{{ $campaign->uid }}_deferred_sent"></span></li>
</ul>
<h4>Last unsuccessfull deliveries</h4>
<div id="pask_irasai"></div>
<script>
    var i = setInterval(function(){
        jQuery.ajax({
            type:"GET",
            url:"/api/get_data/{{ $campaign->uid }}",
            dataType:"json",
            success:function(data) {
                jQuery('#{{ $campaign->uid }}_sent').html(data.sent);
                jQuery('#{{ $campaign->uid }}_bounce').html(data.bounce);
                jQuery('#{{ $campaign->uid }}_deferred').html(data.deferred);

                if (data.deferred_enabled >0) {
                    console.log("deferred enabled");
                    jQuery('#deferred_enabled').show();
                    jQuery('#{{ $campaign->uid }}_deferred_sent').html(data.deferred_sent);
                }

                if (data.download > 0) {
                    jQuery('#down_data').show();
                }
                if (data.bounce > 0) {
                    jQuery('#down_data2').show();
                }
                var undelivered = '<ul class="list-group">';
                var countas = 0;

                    $.each(data.undelivered, function (i1, val) {
                        countas++;
                        undelivered += '<li class="list-group-item"><span class="badge badge-default badge-pill pull-left">' + val.email + '</span><span>' + val.type + ' Status: ' + val.status + '</span></li>';
                    });

                undelivered += '</ul>';
                jQuery('#pask_irasai').html(undelivered);



            }
        });
    },1000);
    </script>