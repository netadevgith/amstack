@if ($groups->count() > 0)
	<table class="table table-box pml-table table-log mt-10"
		current-page="{{ empty(request()->page) ? 1 : empty(request()->page) }}"
	>
		<tr>
			<th>
				<div class="checkbox inline check_all_list">
					<label>
						<input type="checkbox" class="styled check_all">
					</label>
				</div>
			</th>
			<th>Name</th>
			<th class="text-right">{{ trans('messages.action') }}</th>
		</tr>
		@foreach ($groups as $key => $group)
			<tr>
				<td width="1%">
					<div class="checkbox inline">
						<label>
							<input type="checkbox" class="node styled"
								name="ids[]"
								value="{{ $group->id }}"
							/>
						</label>
					</div>
				</td>
				<td>
					<span class="no-margin kq_search">{{ $group->name }}</span>
					<span class="text-muted second-line-mobile">Name</span>
				</td>
				<td class="text-right">
{{--					@if (Auth::user()->customer->can('delete', $group))--}}
						<a
							delete-confirm="Are you sure you want to remove this group item ?"
							href="{{ action('ServGroupController@delete', ["uids" => $group->id]) }}"
							class="btn btn-primary btn-xs bg-grey"
							data-popup="tooltip" title="Groups"
						>
							{{ trans('messages.blacklist.remove') }}
						</a>
{{--					@endif--}}
				</td>
			</tr>
		@endforeach
	</table>
	@include('elements/_per_page_select', ["items" => $groups])
	{{ $groups->links() }}
@elseif (!empty(request()->keyword) || !empty(request()->filters["campaign_uid"]))
	<div class="empty-list">
		<i class="glyphicon glyphicon-minus-sign"></i>
		<span class="line-1">
			{{ trans('messages.no_search_result') }}
		</span>
	</div>
@else
	<div class="empty-list">
		<i class="glyphicon glyphicon-minus-sign"></i>
		<span class="line-1">
			Empty
		</span>
	</div>
@endif
