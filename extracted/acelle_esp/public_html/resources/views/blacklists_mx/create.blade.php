@extends('layouts.frontend')

@section('title', 'Blacklist')

@section('page_script')
    <script type="text/javascript" src="{{ URL::asset('assets/js/core/libraries/jquery_ui/interactions.min.js') }}"></script>
    <script type="text/javascript" src="{{ URL::asset('assets/js/core/libraries/jquery_ui/touch.min.js') }}"></script>

    <script type="text/javascript" src="{{ URL::asset('js/listing.js') }}"></script>
@endsection

@section('page_header')
    <div class="page-title">
        <ul class="breadcrumb breadcrumb-caret position-right">
            <li><a href="{{ action("HomeController@index") }}">{{ trans('messages.home') }}</a></li>
        </ul>
        <h1>
            <span class="text-semibold"><i class="icon-list2"></i> MX blacklist</span>
        </h1>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-sm-12 col-md-6 col-lg-6">
            <div class="sub-section">
                <h2 class="text-semibold text-teal-800"><i class="icon-plus2"></i> New blacklist item</h2>

                <form action="{{ action('Blacklist_mxController@item_add') }}" method="POST" class="form-validate-jqueryz">
                    {{ csrf_field() }}

                    @include("blacklists_mx._form")

                    <div class="text-left">
                        <button class="btn bg-teal mr-10"><i class="icon-check"></i> Add</button>
                        <a href="{{ action('Blacklist_mxController@index') }}" class="btn bg-grey-800"><i class="icon-cross2"></i> {{ trans('messages.cancel') }}</a>
                    </div>
                    <form>
            </div>
        </div>
    </div>
@endsection
