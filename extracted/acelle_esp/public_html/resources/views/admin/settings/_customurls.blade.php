                        @if (Auth::user()->admin->getPermission("setting_system_urls") == 'yes')
							<div class="tab-pane active" id="top-system_urls">
								<div class="text-left">
									<input type="text" placeholder="Unsubscribe part keyword" name="unsubscribepart" id="unsubscribepart" />
									<button onclick="updatecustom('unsubscribepart','add', encodeURIComponent(document.getElementById('unsubscribepart').value))">Insert</button><br>

<input type="text" placeholder="Open part keyword" name="openpart" id="openpart" />
<button onclick="updatecustom('openpart','add', encodeURIComponent(document.getElementById('openpart').value))">Insert</button><br>
									<input type="text" placeholder="Click part keyword" name="clickpart" id="clickpart" />
									<button onclick="updatecustom('clickpart','add', encodeURIComponent(document.getElementById('clickpart').value))">Insert</button><br>
									<input type="text" placeholder="Update profile part keyword" name="profilepart" id="profilepart" />
									<button onclick="updatecustom('profilepart','add', encodeURIComponent(document.getElementById('profilepart').value))">Insert</button><br>
									<input type="text" placeholder="Update source part keyword" name="sourcepart" id="sourcepart" />
									<button onclick="updatecustom('sourcepart','add', encodeURIComponent(document.getElementById('sourcepart').value))">Insert</button>
<script>
function updatecustom(type,action,item) {
    window.location='/settings/setcustomurl/' + type + '/' + action + '/' + item;
}
</script>

									<br/>
									<h1>test area</h1>
								</div>
								<br />
								<div class="col-md-3">
									<h3>Unsubscribe link part</h3>
									<div class="">
										<ul class="modern-listing mt-0 top-border-none">
											@foreach ($unsubscribepart as $unscribe)
												<li>
													<i class="icon-link text-grey"></i>
													<h5 class="mt-0 mb-0 text-semibold">
														<span class='text-info-600'>{{ $unscribe }}</span>
													</h5>
													<p><button onclick="updatecustom('unsubscribepart','del', '{{ $unscribe }}')">Delete</button></p>
												</li>
											@endforeach
										</ul>
									</div>
								</div>
								<div class="col-md-3">
								<h3>Open link part</h3>
								<div class="">
									<ul class="modern-listing mt-0 top-border-none">
										@foreach ($openpart as $openas)
												<li>
													<i class="icon-link text-grey"></i>
													<h5 class="mt-0 mb-0 text-semibold">
														<span class='text-info-600'>{{ $openas }}</span>
													</h5>
													<p><button onclick="updatecustom('openpart','del', '{{ $openas }}')">Delete</button></p>
												</li>
										@endforeach
									</ul>
								</div>
								</div>
								<div class="col-md-3">
								<h3>Click link part</h3>
								<div class="">
									<ul class="modern-listing mt-0 top-border-none">
										@foreach ($clickpart as $clickas)
											<li>
												<i class="icon-link text-grey"></i>
												<h5 class="mt-0 mb-0 text-semibold">
													<span class='text-info-600'>{{ $clickas }}</span>
												</h5>
												<p><button onclick="updatecustom('clickpart','del', '{{ $clickas }}')">Delete</button></p>
											</li>
										@endforeach
									</ul>
								</div>
								</div>
								<div class="col-md-3">
									<h3>Update profile link part</h3>
									<div class="">
										<ul class="modern-listing mt-0 top-border-none">
											@foreach ($profilepart as $profile)
												<li>
													<i class="icon-link text-grey"></i>
													<h5 class="mt-0 mb-0 text-semibold">
														<span class='text-info-600'>{{ $profile }}</span>
													</h5>
													<p><button onclick="updatecustom('profilepart','del', '{{ $profile }}')">Delete</button></p>
												</li>
											@endforeach
										</ul>
									</div>
								</div>
								<div class="col-md-3">
									<h3>Source link part</h3>
									<div class="">
										<ul class="modern-listing mt-0 top-border-none">
											@foreach ($sourcepart as $source)
												<li>
													<i class="icon-link text-grey"></i>
													<h5 class="mt-0 mb-0 text-semibold">
														<span class='text-info-600'>{{ $source }}</span>
													</h5>
													<p><button onclick="updatecustom('sourcepart','del', '{{ $source }}')">Delete</button></p>
												</li>
											@endforeach
										</ul>
									</div>
								</div>
							</div>
						@endif
