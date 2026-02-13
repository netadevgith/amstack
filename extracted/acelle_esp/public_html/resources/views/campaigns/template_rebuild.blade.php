@extends('layouts.builder')

@section('title', trans('messages.create_template'))

@section('content')

        <div class="right">
            <form action="{{ action('CampaignController@template', $campaign->uid) }}" method="POST" class="form-validate-jqueryz">
                {{ csrf_field() }}
                <input type="hidden" name="template_source" value="builder" class="required" />
                <textarea class="hide template_content" name="html"></textarea>
                <div class="">
                    <button type="button" class="btn btn-lg bg-grey mr-5 send-a-test-email-link" data-uid="{{ $campaign->uid }}">Send a test email <i class="icon-envelop3 ml-5"></i> </button>
                    <button class="btn btn-primary mr-5">{{ trans('messages.save') }}</button>
                    <a href="{{ action('CampaignController@templatePreview', $campaign->uid) }}" class="btn bg-slate">{{ trans('messages.cancel') }}</a>
                </div>
            </form>
        </div>
        <div class="left">
            <h1>{{ $campaign->name }}: {{ trans('messages.build_template') }}</h1>
        </div>

    <script>
        // Ajax send a test email
        $(function() {
            $("#ajax_send_a_test_email_form").on("submit", function (e) {
                var url = $(this).attr("action");
                var form = $(".ajax_send_a_test_email_form");
                console.log('submit forma');
                var htmlas = $("[name=html]").val();
                var tokenas = $("[name=_token]").val();
                var duom = {_token: tokenas, template_source: "builder", html: htmlas};

                form.addClass("loading");
                form.find("button[type='submit']").removeClass("disabled");
                form.find('.loading-icon').remove();

                $.ajax({
                    type: "POST",
                    url: '/campaigns/{{ $campaign->uid }}/template',
                    async: false,
                    data: duom, // serializes the form's elements.
                    success: function (data) {
                       console.log('ok');
                    }
                });


                {{--console.log('save complete!');--}}
                if (form.valid()) {
                    form.addClass("loading");
                    form.find("button[type='submit']").addClass("disabled");
                    form.find("button[type='submit']").before('<i class="icon-spinner10 spinner position-left loading-icon"></i>');
                    $.ajax({
                        type: "POST",
                        url: url,
                        async: false,
                        data: form.serialize(), // serializes the form's elements.
                        success: function (data) {
                            data = JSON.parse(data);
                            swal({
                                title: '',
                                text: data.message,
                                confirmButtonColor: "#00695C",
                                type: data.status,
                                allowOutsideClick: true,
                                confirmButtonText: LANG_OK,
                                customClass: "swl-success",
                                html: true
                            });


                            $(".copy-campaign-close").trigger("click");
                        }
                    });
                }

                e.preventDefault(); // avoid to execute the actual submit of the form.
            });
        });
        $(document).on('click', '.send-a-test-email-link', function(e) {
            var uid = $(this).attr("data-uid");
            $('input[name=send_test_email_campaign_uid]').val(uid);
            $('#send_a_test_email').modal("show");
            e.preventDefault();
            console.log('test4');
        });
    </script>

@endsection

@section('template_content')

    {!! $campaign->html !!}
    
@endsection