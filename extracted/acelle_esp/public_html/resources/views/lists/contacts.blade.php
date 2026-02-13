@extends('layouts.frontend')

@section('title', 'Contacts')

@section('page_script')
    {{--<script type="text/javascript" src="{{ URL::asset('assets/js/core/libraries/jquery_ui/interactions.min.js') }}"></script>--}}
    {{--<script type="text/javascript" src="{{ URL::asset('assets/js/core/libraries/jquery_ui/touch.min.js') }}"></script>--}}

@endsection

@section('page_header')

	<div class="page-title">
		<ul class="breadcrumb breadcrumb-caret position-right">
			<li><a href="{{ action("HomeController@index") }}">{{ trans('messages.home') }}</a></li>
		</ul>
		<h1>
			<span class="text-semibold"><i class="icon-list2"></i>Contacts</span>
		</h1>
	</div>

@endsection

@section('content')

    <button class="btn bg-teal mr-10" onclick="exportas(1)"><i class="icon-check"></i>Export {{ $openers }} Openers</button>
    <button class="btn bg-teal mr-10" onclick="exportas(2)"><i class="icon-check"></i>Export Without {{ $hardbounces }} HardBounces</button>

    <form class="listing-form">
        <div class="row top-list-controls">
            <div class="col-md-10">
                @if (count($lists) >= 0)
                    <div class="filter-box">
                        <span class="filter-group">
                            <span class="title text-semibold text-muted">{{ trans('messages.sort_by') }}</span>
                            <select class="select" name="sort-order" id="sort-order">
                                <option value="status">Status</option>
                                <option value="unconfirmed">Hard bounces</option>
                                <option value="subscribed">Subscribed</option>
                                 <option value="unsubscribed">Unsubscribed</option>
                                 <option value="blacklisted">Blacklisted</option>
                            </select>
                            {{--<button class="btn btn-xs sort-direction" rel="asc" data-popup="tooltip" title="{{ trans('messages.change_sort_direction') }}" type="button" class="btn btn-xs">--}}
                                {{--<i class="icon-sort-amount-asc"></i>--}}
                            {{--</button>--}}
                        </span>
                        <span class="text-nowrap">
                            <input id="search_keyword" name="search_keyword" value="{{ app('request')->input('search_keyword') }}" class="form-control search" placeholder="{{ trans('messages.type_to_search') }}" />
                            <i class="icon-search4 keyword_search_button"></i>
                        </span>
                    </div>
                @endif
            </div>
        </div>

    </form>
    @if (app('request')->input('sort-order'))
        {{ $paginator->setPath('?sort-order='.app('request')->input('sort-order').'&search_keyword='.app('request')->input('search_keyword')) }}
    @else
        {{ $paginator->setPath('') }}
    @endif
    <table class="table table-box pml-table"
           current-page="{{ empty(request()->page) ? 1 : empty(request()->page) }}"
    >
        @foreach ($lists as $key => $item)
            <tr>
                <td>
                </td>
                <td class="stat-fix-size-sm">
                    <div class="single-stat-box pull-left">
 {{ $item->email }}
                        <br />
                        @if ($item->opened_at != "")
                            <span class="label label-flat bg-opener">opener</span>
                            @elseif (isset($item->blacklistas))
                            <span class="label label-flat bg-blacklisted">blacklisted</span>
                            @elseif (!isset($item->message_id))
                            <span class="label label-flat bg-pending">Never send</span>
                            @else
                        <span class="label label-flat bg-{{ $item->status }}">{{ trans('messages.' . $item->status) }}</span>
@endif
                    </div>
                    <br style="clear:both" />
                </td>
                <td class="stat-fix-size-sm pull-left">
                    <div class="single-stat-box pull-left">
                    @if ($item->opened_at != "")
                            <span class="no-margin stat-num"> {{ $item->opened_at }}</span>
                            <br>
                            <span class="text-muted2">Opened at</span>
                        @else
                        <span class="no-margin stat-num"> {{ $item->updated_at }}</span>
                        <br>
                        <span class="text-muted2">Updated at</span>
                        @endif
                    </div>
                </td>
                <td class="stat-fix-size-sm pull-left">
                    <div class="single-stat-box pull-left">
                        <span class="no-margin stat-num"> {{ $item->created_at }}</span>
                        <br>
                        <span class="text-muted2">Created at</span>
                    </div>
                </td>
                <td class="text-right">
                    <a href="/lists/{{ $item->maillist_uid  }}/overview"> {{ $item->name }}</a>
                    <a href="/lists/{{ $item->maillist_uid }}/subscribers/{{ $item->suid }}/edit" type="button" class="btn bg-grey btn-icon">
                        <i class="icon-pencil"></i>
                    </a>
                    <script>
                        //  document.querySelector('#sort-order [value="' + {{ app('request')->input('sort-order') }} + '"]').addClass('active').selected = true;

                        function exportas(type) {
                            window.open('/lists/exportfunc/'+type,'_blank');
                        }
                        $( document ).ready(function() {
                            $("#sort-order").val('{{ app('request')->input('sort-order') }}').addClass('active');
                            $('#sort-order').change(function(){
                                var value = $(this).val();
                                var val2 = $("#search_keyword").val();
                                window.location.href = "/contacts?sort-order="+value+"&search_keyword="+val2;
                                //console.log(value);
                            });
                        });

                    </script>
                    {{--<div class="btn-group">--}}
                        {{--<button type="button" class="btn dropdown-toggle" data-toggle="dropdown"><span class="caret ml-0"></span></button>--}}
                        {{--<ul class="dropdown-menu dropdown-menu-right">--}}
                            {{--<li>--}}
                            {{--<a href="{{ action('MailListController@embeddedForm', $item->uid) }}">--}}
                            {{--<i class="icon-embed2"></i> {{ trans('messages.Embedded_form') }}--}}
                            {{--</a>--}}
                            {{--</li>--}}
                            {{--<li><a href="{{ action('PageController@update', ['list_uid' => $item->uid, 'alias' => 'sign_up_form']) }}"><i class="icon-certificate"></i> {{ trans('messages.custom_forms_and_emails') }}</a></li>--}}
                            {{--<li>--}}
                            {{--<a class="level-1" href="{{ action('FieldController@index', $item->uid) }}">--}}
                            {{--<i class="icon-list3"></i> {{ trans('messages.manage_list_fields') }}--}}
                            {{--</a>--}}
                            {{--</li>--}}
                            {{--<li><a href="{{ action('MailListController@verification', $item->uid) }}"><i class="icon-envelop5"></i> {{ trans("messages.email_verification") }}</a></li>--}}

                           {{----}}
                        {{--</ul>--}}
                    {{--</div>--}}
                </td>
            </tr>
        @endforeach
    </table>
    @if (app('request')->input('sort-order'))
        {{ $paginator->setPath('?sort-order='.app('request')->input('sort-order').'&search_keyword='.app('request')->input('search_keyword')) }}
        @else
    {{ $paginator->setPath('') }}
    @endif
@endsection
