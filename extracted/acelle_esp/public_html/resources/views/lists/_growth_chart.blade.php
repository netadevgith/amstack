<div class="row">
    <div class="col-md-6">
        <!-- Basic column chart -->
        <div class="panel panel-flat">
            <div class="panel-body">
                <div class="chart-container">
                    <div class="chart has-fixed-height" id="basic_columns" data-url="{{ action('MailListController@OpenerslistGrowthChart', $list->uid) }}"></div>
                </div>
            </div>
        </div>
        <!-- /basic column chart -->
    </div>
    <div class="col-md-6">
        @if ($list->readCache('SubscriberCount') || (!isset($list->id) && Auth::user()->customer->readCache('SubscriberCount')))
            <!-- Basic column chart -->
            <div class="panel panel-flat">
                <div class="panel-body">
                    <div class="chart-container">
                        <div class="chart has-fixed-height" id="basic_columns_pie" data-url="{{ action('MailListController@statisticsChart', $list->uid) }}"></div>
                    </div>
                </div>
            </div>
            <!-- /basic column chart -->
        @else
            <div class="empty-chart-pie">
                <div class="empty-list">
                    <i class="icon-file-text2"></i>
                    <span class="line-1">
                        {{ trans('messages.log_empty_line_1') }}
                    </span>
                </div>
            </div>
        @endif

    </div>


</div>
<form method="post" id="b-form" action="../createb.php">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <div id="progressbar" style="display: none"><img src="/images/91.gif"> Working...</div>
    <input type="button" id="mygtukasloadopeners" value="Load data..." onclick="loadopeners('{{ $list->uid }}')"/>
    <div id="openersbyproviders"></div>
</form>


    <script>
        function deletas_domenu() {
        $.ajax({
            type: 'POST',
            url: '/lists/deletebydomain/{{ $list->id }}/selected',
            headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')},
            data: $('#b-form input:checkbox:checked').serialize(),
            success: function (msg){
                alert(msg);
                location.reload();
            }
        })
        }
        function loadopeners(uid) {
            $("#mygtukasloadopeners").hide();
            $("#progressbar").show();
            $.ajax({
                url: "/lists/openersbyprovider/"+uid,
                dataType: 'html',
                type: 'get',
                cache:false,
                success: function(data){
                    $("#openersbyproviders").html(data);
                    $("#progressbar").hide();
                },
                error: function(d){
                    /*console.log("error");*/
                    $("#mygtukasloadopeners").show();
                    $("#progressbar").hide();
                    alert("404. Please wait until the File is Loaded.");
                }
            });
        }
    </script>