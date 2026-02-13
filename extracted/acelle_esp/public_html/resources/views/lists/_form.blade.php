<div class="sub_section">
    <h2 class="text-semibold text-teal-800">{{ trans('messages.list_details') }}</h2>

    <div class="row">
        <div class="col-md-6">
                @include('helpers.form_control', ['type' => 'text', 'name' => 'name', 'value' => $list->name, 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
        </div>
        <div class="col-md-6">
                @include('helpers.form_control', ['type' => 'text', 'name' => 'from_email', 'label' => trans('messages.default_from_email_address'), 'value' => $list->from_email, 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
                @include('helpers.form_control', ['type' => 'text', 'name' => 'from_name', 'label' => trans('messages.default_from_name'), 'value' => $list->from_name, 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
        </div>
        <div class="col-md-6" style="display:none">
                @include('helpers.form_control', ['type' => 'text', 'name' => 'default_subject', 'label' => trans('messages.default_email_subject'), 'value' => $list->default_subject, 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
        </div>
    </div>


</div>

<div class="sub_section" style="display: none">
    <h2 class="text-semibold text-teal-800">
        {{ trans('messages.contact_information') }}
        <span class="subhead">{!! trans('messages.default_from_your_contact_information', ['link' => action('AccountController@contact')]) !!}</span>
    </h2>
    <div class="row">
        <div class="col-md-6">
                @include('helpers.form_control', ['type' => 'text', 'name' => 'contact[company]', 'label' => trans('messages.company_organization'), 'value' => 'kompanija', 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
        </div>
        <div class="col-md-6">
                @include('helpers.form_control', ['type' => 'text', 'name' => 'contact[state]', 'label' => trans('messages.state_province_region'), 'value' => 'N/A', 'rules' => Acelle\Model\MailList::$rules])
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            @include('helpers.form_control', ['type' => 'text', 'name' => 'contact[address_1]', 'label' => trans('messages.address_1'), 'value' => 'addr', 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
        </div>
        <div class="col-md-6">
            @include('helpers.form_control', ['type' => 'text', 'name' => 'contact[city]', 'label' => trans('messages.city'), 'value' => 'City', 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            @include('helpers.form_control', ['type' => 'text', 'name' => 'contact[address_2]', 'label' => trans('messages.address_2'), 'value' => 'addr2', 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
        </div>
        <div class="col-md-6">
            @include('helpers.form_control', ['type' => 'text', 'name' => 'contact[zip]', 'label' => trans('messages.zip_postal_code'), 'value' => '0000', 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
        </div>
    </div>
    <div class="row">
        {{--<div class="col-md-6">--}}
            {{--@include('helpers.form_control', ['type' => 'select', 'name' => 'contact[country_id]', 'label' => trans('messages.country'), 'value' => '1', 'options' => Acelle\Model\Country::getSelectOptions(), 'include_blank' => trans('messages.choose'), 'rules' => Acelle\Model\MailList::$rules])--}}
        {{--</div>--}}
        <div class="col-md-6">
            @include('helpers.form_control', ['type' => 'text', 'name' => 'contact[phone]', 'label' => trans('messages.phone'), 'value' => '000000000', 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            @include('helpers.form_control', ['type' => 'text', 'name' => 'contact[email]', 'label' => trans('messages.email'), 'value' => 'info@default.lt', 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
        </div>
        <div class="col-md-6">
            @include('helpers.form_control', ['type' => 'text', 'name' => 'contact[url]', 'label' => trans('messages.url'), 'label' => trans('messages.home_page'), 'value' => $list->contact->url, 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
        </div>
    </div>
</div>

<div style="display:none">
@include('helpers.form_control', ['type' => 'text', 'name' => 'trackurl', 'label' => 'Tracking domain (without http://)', 'value' => $list->trackurl, 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])

{{--@include('helpers.form_control', ['type' => 'text', 'name' => 'country', 'label' => 'Country', 'value' => $list->country, 'help_class' => 'list'])--}}

{{--@include('helpers.form_control', ['type' => 'select', 'name' => 'contact[country_id]', 'label' => trans('messages.country'), 'options' => Acelle\Model\Country::getSelectOptions(), 'value' => $list->contact->country->id, 'include_blank' => trans('messages.choose'), 'rules' => Acelle\Model\MailList::$rules])--}}
Subscribers recovery:
<select id="recovery" name="recovery" class="select">
    <option value="0">Never</option>
    <option value="1">Daily</option>
    <option value="3">Weekly</option>
    <option value="4">Monthly</option>
</select>

Country:
<select id="salis" name="contact[country_id]" class="select">
    @foreach (Acelle\Model\Country::getAll() as $item)

        <option value="{{ $item->id }}">{{ $item->name }}</option>

        @endforeach
</select>
<script>
        document.querySelector('#salis [value="' + {{ $list->contact->country_id }} + '"]').selected = true
        document.querySelector('#recovery [value="' + {{ $list->recovery }} + '"]').selected = true
</script>


<div class="sub_section">
   <h2 class="text-semibold text-teal-800">
       Imap Checking
   </h2>
   <div class="row">
       <div class="col-md-6">
           @include('helpers.form_control', ['type' => 'text', 'name' => 'imap_host', 'label' => 'Hostname', 'value' => $list->imap_host, 'help_class' => 'list'])
           @include('helpers.form_control', ['type' => 'text', 'name' => 'imap_mail', 'label' => 'Username', 'value' => $list->imap_mail, 'help_class' => 'list'])
           @include('helpers.form_control', ['type' => 'text', 'name' => 'imap_pass', 'label' => 'Password', 'value' => $list->imap_pass, 'help_class' => 'list'])
           @include('helpers.form_control', ['type' => 'text', 'name' => 'imap_spam', 'label' => 'Spam folder', 'value' => $list->imap_spam, 'help_class' => 'list'])
       </div>
       <div class="col-md-6">
       </div>
   </div>


</div>
</div>

<div class="sub_section" style="display: none">
   <h2 class="text-semibold text-teal-800">{{ trans('messages.settings') }}</h2>
   <div class="row">
       <div class="col-md-6 hide">
           @include('helpers.form_control', ['type' => 'text', 'name' => 'email_subscribe', 'value' => $list->email_subscribe, 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
           @include('helpers.form_control', ['type' => 'text', 'name' => 'email_unsubscribe', 'value' => $list->email_unsubscribe, 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
           <br />
       </div>
       <div class="col-md-6">
           <div class="form-group checkbox-right-switch">
               @include('helpers.form_control', [
                   'type' => 'checkbox',
                   'name' => 'subscribe_confirmation',
                   'value' => $list->subscribe_confirmation,
                   'options' => [false,true],
                   'help_class' => 'list',
                   'rules' => Acelle\Model\MailList::$rules
               ])
               @include('helpers.form_control', ['type' => 'checkbox', 'name' => 'unsubscribe_notification', 'value' => $list->unsubscribe_notification, 'options' => [false,true], 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
           </div>
       </div>
       <div class="col-md-6">
           <div class="form-group checkbox-right-switch">
               @include('helpers.form_control', ['type' => 'checkbox', 'name' => 'send_welcome_email', 'value' => $list->send_welcome_email, 'options' => [false,true], 'help_class' => 'list', 'rules' => Acelle\Model\MailList::$rules])
           </div>
       </div>
   </div>
</div>

<script>
    function select_byname() {
        var searchas = prompt("Search text", "");
        if (searchas == null || searchas == "") {
            alert('bad input');
        } else {
            $("label").each(function() {
                if ($(this).html().toLowerCase().indexOf(searchas) >= 0) {
                    if($(this).is(':checked') == false) {
                        $(this).find(".checker span input").click();
                    }
                }
            });
        }
    }

    function unselect_byname() {
        var searchas = prompt("Search text", "");
        if (searchas == null || searchas == "") {
            alert('bad input');
        } else {
            $("label").each(function() {
                //alert($(this).html());
                if ($(this).html().toLowerCase().indexOf(searchas) >= 0) {
                    if($(this).is(':checked') == true)
                    {
                        $(this).find(".checker span input").click();
                    }
                }
            });
        }
    }

    function set_speed() {
        var searchas = prompt("Set speed:", "");
        if (searchas == null || searchas == "") {
            alert('bad speed input');
        } else {
            $(".sending-servers").find("input[type=text]").each(function() {
                console.log($(this).val());
                $(this).val(searchas);
            });
        }
    }

    function invert_click() {
        $(".checker span input").each(function () {
            if ($(this).attr('name') != 'all_sending_servers') {
                $(this).click();
            }
        });
    }

    function switch_checkbox(type) {
        $(".checker span input").each(function() {
            if($(this).attr('name') != 'all_sending_servers')
            {
                if (type == 1) {
                    if($(this).is(':checked') == false)
                    {
                        $(this).click();
                    }
                } else {
                    if($(this).is(':checked') == true)
                    {
                        $(this).click();
                    }
                }
                $.uniform.update();
            }
        });
    }
</script>

@if (Auth::user()->customer->can('create', new Acelle\Model\SendingServer()))

   <div class="sub_section">
       <h2 class="text-semibold text-teal-800">{{ trans('messages.sending_servers') }}</h2>
       <div class="row mb-20 form-groups-bottom-0">
           <div class="col-md-3">
               @include('helpers.form_control', ['type' => 'checkbox2',
                   'class' => '',
                   'name' => 'all_sending_servers',
                   'value' => $list->all_sending_servers,
                   'label' => trans('messages.use_all_sending_servers'),
                   'options' => [false,true],
                   'help_class' => 'list',
                   'rules' => Acelle\Model\MailList::$rules
               ])
               @include('helpers.form_control', ['type' => 'text',
                   'class' => 'numeric',
                   'name' => 'speed',
                   'value' => ($list->speed ? $list->speed : \DB::table('nustatymai')->where('id', 2)->first()->reiksm),
                   'label' => 'Sending speed for all servers',
                   'help_class' => 'list',
                   'rules' => Acelle\Model\MailList::$rules
               ])
           </div>
       </div>
       @if(!\Auth::user()->customer->activeSendingServers()->count())
           <div class="alert alert-danger">
               {!! trans('messages.list.there_no_subaccount_sending_server') !!}
           </div>
       @else
           <div class="sending-servers">
               <hr>
               <input type="button" value="Select All Servers" onclick="switch_checkbox(1)" />
               <input type="button" value="Select None" onclick="switch_checkbox(0)" />
               <input type="button" value="Select by name" onclick="select_byname()" />
{{--               <input type="button" value="Unselect by name" onclick="unselect_byname()" />--}}
               <input type="button" value="Invert click" onclick="invert_click()" />
               <input type="button" value="Set Speed" onclick="set_speed()" />
               {{--<input type="checkbox" name="all" id="all" />--}}
               <div class="row text-muted text-semibold">
                   <div class="col-md-3">
                       <label>{{ trans('messages.select_sending_servers') }}</label>
                   </div>
                   <div class="col-md-3">
                       <label>{{ trans('messages.fitness') }}</label>
                   </div>
               </div>
               @foreach (\Auth::user()->customer->activeSendingServers()->where('id','>',1)->orderBy("name")->get() as $server)
                   <div class="row mb-5 form-groups-bottom-0">
                       <div class="col-md-3">
                           @include('helpers.form_control', [
                               'type' => 'checkbox2',
                               'name' => 'sending_servers[' . $server->uid . '][check]',
                               'value' => $list->mailListsSendingServers->contains('sending_server_id', $server->id),
                               'label' => $server->name,
                               'options' => [false, true],
                               'help_class' => 'list',
                               'rules' => Acelle\Model\MailList::$rules
                           ])
                       </div>
                       <div class="col-md-3" show-with-control="input[name='{{ 'sending_servers[' . $server->uid . '][check]' }}']">
                           @include('helpers.form_control', [
                               'type' => 'text',
                               'class' => 'numeric',
                               'name' => 'sending_servers[' . $server->uid . '][fitness]',
                               'label' => '',
                               'value' => (is_object($list->mailListsSendingServers()->where('sending_server_id', $server->id)->first()) ? $list->mailListsSendingServers()->where('sending_server_id', $server->id)->first()->fitness : "2000000"),
                               'help_class' => 'list',
                               'rules' => Acelle\Model\MailList::$rules
                           ])
                       </div>
                   </div>
               @endforeach
           </div>
       @endif
   </div>
   <script>
       $(document).ready(function() {
           // all sending servers checking
           $(document).on("change", "input[name='all_sending_servers']", function(e) {
               if($("input[name='all_sending_servers']:checked").length) {
                   $(".sending-servers").find("input[type=checkbox]").each(function() {
                       if($(this).is(":checked")) {
                           $(this).parents(".form-group").find(".switchery").eq(1).click();
                       }
                   });
                   $(".sending-servers").hide();
               } else {
                   $(".sending-servers").show();
               }
           });
           $("input[name='all_sending_servers']").trigger("change");


       });






   </script>
@endif
