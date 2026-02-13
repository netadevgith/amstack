    <h3 class="mt-10 mb-0"><i class="icon-stats-dots"></i> Openers by Providers</h3>
    <div class="row">
        <div id="progressbar" style="display: none"><img src="/images/91.gif"> Working...</div>
        <input type="button" id="mygtukasloadopeners" value="Load data..." onclick="loadopeners('{{ $campaign->uid }}')"/>
        <div id="openersbyproviders"></div>


    </div>


    <h3 class="mt-10 mb-0"><i class="icon-stats-dots"></i> Clickers by Providers</h3>
    <div class="row">

        <div id="progressbar2" style="display: none"><img src="/images/91.gif"> Working...</div>
        <input type="button" id="mygtukasloadclickers" value="Load data..." onclick="loadclickers('{{ $campaign->uid }}')"/>
        <div id="clickersbyproviders"></div>
    </div>

    <script>
        function loadopeners(uid) {
            $("#progressbar").show();
            $("#mygtukasloadopeners").hide();
            $.ajax({
                url: "/campaigns/showopenersbyprovider/"+uid,
                dataType: 'html',
                type: 'get',
                cache:false,
                success: function(data){
                    $("#openersbyproviders").html(data);
                    $("#mygtukasloadopeners").hide();
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

        function loadclickers(uid) {
            $("#progressbar2").show();
            $("#mygtukasloadclickers").hide();
            $.ajax({
                url: "/campaigns/showclickersbyprovider/"+uid,
                dataType: 'html',
                type: 'get',
                cache:false,
                success: function(data){
                    $("#clickersbyproviders").html(data);

                    $("#progressbar2").hide();
                },
                error: function(d){
                    /*console.log("error");*/
                    $("#mygtukasloadclickers").show();
                    $("#progressbar2").hide();
                    alert("404. Please wait until the File is Loaded.");
                }
            });
        }

    </script>