                        @if ($items->count() > 0)
							<table class="table table-box pml-table table-log"
                                current-page="{{ empty(request()->page) ? 1 : empty(request()->page) }}"
                            >
								<tr>
									<th>{{ trans('messages.recipient') }}</th>
									<th>Earned</th>
									<th>Currency</th>
									<th>{{ trans('messages.created_at') }}</th>
								</tr>
								@foreach ($items as $key => $item)
									<tr>
										<td>
												<span class="no-margin kq_search">{{ $item->email }}</span>
											<span class="text-muted second-line-mobile">{{ trans('messages.recipient') }}</span>
										</td>
										<td>
											<span class="no-margin kq_search">{{ $item->val }}</span>
											<span class="text-muted second-line-mobile">Earn</span>
										</td>
										<td>
											<span class="no-margin kq_search">{{ $item->currency }}</span>
											<span class="text-muted second-line-mobile">Currency</span>
										</td>
										<td>
											<span class="no-margin kq_search">{{ $item->datetime }}</span>
											<span class="text-muted second-line-mobile">{{ trans('messages.created_at') }}</span>
										</td>
									</tr>
								@endforeach
							</table>
                            @include('elements/_per_page_select', ["items" => $items])
							{{ $items->links() }}
						@elseif (!empty(request()->keyword) || !empty(request()->filters["campaign_uid"]))
							<div class="empty-list">
								<i class="icon-file-text2"></i>
								<span class="line-1">
									{{ trans('messages.no_search_result') }}
								</span>
							</div>
						@else					
							<div class="empty-list">
								<i class="icon-file-text2"></i>
								<span class="line-1">
									{{ trans('messages.log_empty_line_1') }}
								</span>
							</div>
						@endif