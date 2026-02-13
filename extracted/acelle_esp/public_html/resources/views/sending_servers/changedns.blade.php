<div class="modal-header bg-teal">
    <button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title">Edit DNS</h4>
</div>
<div class="modal-content">
    <form action="" method="POST" class="ajax_upload_form form-validate-jquery">
        {{ csrf_field() }}
        <input type="hidden" name="_method" value="">
        <input type="hidden" name="uids" value="{{ $uids }}">

        <div class="modal-body">
            <p>Enter domain name, all needed dns entries will be generated automatically and changed on selected servers...</p>
            @include('helpers.form_control', [
                'type' => 'text',
                'class' => '',
                'label' => 'Domain name',
                'name' => 'domain',
                'value' => '',
                'help_class' => 'sending_server',
                'rules' => ['domain' => 'required']
            ])
        </div>
        <div class="modal-footer text-left">
            <a
                href="{{ action('SendingServerController@changedns') }}"
                type="button"
                class="btn bg-teal mr-5 ajax_link"
                data-in-form="true"
                data-method="POST"
                mask-title="Changing"
            >
                Change
            </a>
            <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('messages.close') }}</button>
        </div>
    </form>
</div>
