@extends('layouts.frontend')

@section('title', trans('messages.campaigns'))

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
            @if ($type == "normal")
            <span class="text-semibold"><i class="icon-list2"></i> {{ trans('messages.campaigns') }}</span>
                @else
                <span class="text-semibold"><i class="icon-list2"></i> Campaign archive</span>
                @endif
        </h1>
    </div>

@endsection

@section('content')
    <form class="listing-form"
        sort-url="{{ action('CampaignController@sort',[ 'type' => $type ]) }}"
        data-url="{{ action('CampaignController@listing', [ 'type' => $type ]) }}"
          per-page="50"
          type="{{ $type }}"
        {{--per-page="{{ Acelle\Model\MailList::$itemsPerPage }}"--}}
    >
        <div class="row top-list-controls">
            <div class="col-md-10">
                @if ($campaigns->count() >= 0)
                    <div class="filter-box">
                        <div class="btn-group list_actions hide">
                            <button type="button" class="btn btn-xs btn-grey-600 dropdown-toggle" data-toggle="dropdown">
                                {{ trans('messages.actions') }} <span class="caret"></span>
                            </button>
                            <ul class="dropdown-menu">
                                @if ($type == "normal")
                                <li><a link-confirm="{{ trans('messages.restart_campaigns_confirm') }}" href="{{ action('CampaignController@restart') }}"><i class="icon-history"></i> {{ trans("messages.restart") }}</a></li>
                                <li><a link-confirm="{{ trans('messages.pause_campaigns_confirm') }}" href="{{ action('CampaignController@pause') }}"><i class="icon-pause"></i> {{ trans("messages.pause") }}</a></li>
                                <li><a  link-confirm="Are you about to copy these campaigns ?" href="{{ action('CampaignController@copymass') }}"><i class="icon-copy"></i> {{ trans('messages.copy') }}</a></li>
                                <li><a link-confirm="Do you really want to archive these campaigns ?" href="{{ action('CampaignController@archive') }}"><i class="icon-archive"></i> Archive</a></li>
                                <li><a  link-confirm="Are you about to restart background jobs for these campaigns ?" href="{{ action('CampaignController@RestartBackground') }}"><i class="icon-meter-fast"></i> Restart Background Sending</a></li>
                                @else
                                <li><a delete-confirm="{{ trans('messages.delete_campaigns_confirm') }}" href="{{ action('CampaignController@delete') }}"><i class="icon-trash"></i> {{ trans('messages.delete') }}</a></li>

                                    @endif
                            </ul>
                        </div>
                        <div class="checkbox inline check_all_list">
                            <label>
                                <input type="checkbox" class="styled check_all">
                            </label>
                        </div>
                        <span class="filter-group">
                            <span class="title text-semibold text-muted">{{ trans('messages.sort_by') }}</span>
                            <select class="select" name="sort-order">
                                <option value="created_at" class="active">{{ trans('messages.created_at') }}</option>
                                <option value="custom_order">{{ trans('messages.custom_order') }}</option>
                                <option value="name">{{ trans('messages.name') }}</option>
                                {{--<option value="country_id"> {{ trans('messages.country') }}</option>--}}
                            </select>
                            <button class="btn btn-xs sort-direction" rel="desc" data-popup="tooltip" title="{{ trans('messages.change_sort_direction') }}" type="button" class="btn btn-xs">
                                <i class="icon-sort-amount-asc"></i>
                            </button>
                        </span>
                        <span class="filter-group" style="display:none">
                            <span class="title text-semibold text-muted">{{ trans('messages.sort_by') }}</span>
                            <select class="select" name="sort-order2" id="sort-order2">
                                <option value="0" class="active">All Countries</option>
                                {{ Acelle\Model\Country::getFilterOptions() }}
                            </select>
                            <button class="btn btn-xs sort-direction" rel="asc" data-popup="tooltip" title="{{ trans('messages.change_sort_direction') }}" type="button" class="btn btn-xs">
                                <i class="icon-sort-amount-asc"></i>
                            </button>
                        </span>
                        <span class="text-nowrap">
                            <input name="search_keyword" class="form-control search" placeholder="{{ trans('messages.type_to_search') }}" />
                            <i class="icon-search4 keyword_search_button"></i>
                        </span>
                    </div>
                @endif
            </div>
            @if ($type == "normal")
            <div class="col-md-2 text-right">
                <a href="{{ action('CampaignController@selectType') }}" type="button" class="btn bg-info-800">
                    <i class="icon icon-plus2"></i> {{ trans('messages.create_campaign') }}
                </a>
            </div>
                @endif
        </div>

        <div class="pml-table-container">



        </div>
    </form>
@endsection
