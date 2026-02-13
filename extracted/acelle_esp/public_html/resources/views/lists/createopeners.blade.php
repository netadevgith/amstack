@extends('layouts.frontend')

@section('title', trans('messages.create_list'))

@section('page_script')
    <script type="text/javascript" src="{{ URL::asset('assets/js/plugins/forms/styling/uniform.min.js') }}"></script>

    <script type="text/javascript" src="{{ URL::asset('js/validate.js') }}"></script>
@endsection

@section('page_header')
	<div class="page-title">
		<ul class="breadcrumb breadcrumb-caret position-right">
			<li><a href="{{ action("HomeController@index") }}">{{ trans('messages.home') }}</a></li>
			<li><a href="{{ action("MailListController@index") }}">{{ trans('messages.lists') }}</a></li>
		</ul>
		<h1>
			<span class="text-semibold"><i class="icon-plus-circle2"></i> List generation from openers</span>
		</h1>
	</div>
    <form action="{{ action('MailListController@storeopeners') }}" method="POST" class="form-validate-jqueryz">
	How we will generate the list ?
        <div class="row">
            <div class="col-md-6">
                By providers:
                @include('helpers.form_control', ['type' => 'text', 'name' => 'providers', 'value' => '', 'placeholder' => '@gmail.com @yahoo.com', 'help_class' => 'list'])
                @include('helpers.form_control', ['type' => 'checkbox', 'name' => 'enable_providers', 'value' => 'false', 'options' => [ 'false', 'true', ]])
            </div>
            <div class="col-md-6">
                By open location:
                @include('helpers.form_control', ['type' => 'text', 'name' => 'location', 'value' => '', 'placeholder' => 'Germany Italy Belgium', 'help_class' => 'list'])
                @include('helpers.form_control', ['type' => 'checkbox', 'name' => 'enable_location', 'value' => 'false', 'options' => [ 'false', 'true', ]])
            </div>

Few items can be specified, using spaces in the edit box.

@endsection


@section('content')

		{{ csrf_field() }}
		@include("lists._form")
		<hr>
		<div class="text-left">
			<button class="btn bg-teal mr-10"><i class="icon-check"></i> {{ trans('messages.save') }}</button>
			<a href="{{ action('MailListController@index') }}" class="btn bg-grey-800"><i class="icon-cross2"></i> {{ trans('messages.cancel') }}</a>
		</div>
	</form>
@endsection
