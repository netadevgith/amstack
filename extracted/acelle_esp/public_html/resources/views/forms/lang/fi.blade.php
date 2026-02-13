<!DOCTYPE html>
<html lang="en">
<head>
    <title>Report</title>

{{--@include('layouts._favicon')--}}

@include('layouts._head')

<!-- Global stylesheets -->
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,300,100,500,700,900" rel="stylesheet" type="text/css">
    <link href="{{ URL::asset('assets/css/icons/icomoon/styles.css') }}" rel="stylesheet" type="text/css">
    <link href="{{ URL::asset('assets/css/bootstrap.css') }}" rel="stylesheet" type="text/css">
    <link href="{{ URL::asset('assets/css/core.css') }}" rel="stylesheet" type="text/css">
    <link href="{{ URL::asset('assets/css/components.css') }}" rel="stylesheet" type="text/css">
    <link href="{{ URL::asset('assets/css/colors.css') }}" rel="stylesheet" type="text/css">
    <link href="{{ URL::asset('css/app.css') }}" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.8.0/css/bootstrap-datepicker.css" rel="stylesheet" type="text/css">
    <!-- /global stylesheets -->

    <!-- Core JS files -->
    <script type="text/javascript" src="{{ URL::asset('assets/js/plugins/loaders/pace.min.js') }}"></script>
    <script type="text/javascript" src="{{ URL::asset('assets/js/core/libraries/jquery.min.js') }}"></script>
    <script type="text/javascript" src="{{ URL::asset('assets/js/core/libraries/bootstrap.min.js') }}"></script>
    <script type="text/javascript" src="{{ URL::asset('assets/js/plugins/loaders/blockui.min.js') }}"></script>
    <script type="text/javascript" src="{{ URL::asset('assets/js/plugins/ui/nicescroll.min.js') }}"></script>
    <script type="text/javascript" src="{{ URL::asset('assets/js/plugins/ui/drilldown.js') }}"></script>
    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.8.0/js/bootstrap-datepicker.js"></script>
    <!-- /core JS files -->

    <!-- Theme JS files -->
    <script type="text/javascript" src="{{ URL::asset('assets/js/plugins/forms/styling/uniform.min.js') }}"></script>

    <script type="text/javascript" src="{{ URL::asset('assets/js/core/app.js') }}"></script>
    <script type="text/javascript" src="{{ URL::asset('assets/js/pages/login.js') }}"></script>
    <!-- /theme JS files -->

</head>
{{--@section('content')--}}

<body class="bg-slate-800" style="color: {{ $layout['background_foreground'] }};background-color: {{ $layout['background'] }}">

<!-- Page container -->
<div class="page-container login-container">

    <!-- Page content -->
    <div class="page-content">

        @yield('content')

    </div>
    @if ($error > 0)
        <h3 font="color: red">Määritä oikea sähköpostiosoite!</h3>
    @endif

    @if ($received > 0)
        <h3>Raportti sai!</h3>
@else
    <!-- /page content -->
        <form action="" method="POST" class="form-validate-jqueryz">
            {{ csrf_field() }}

            <div class="sub_section">
                <h2 class="text-semibold" style="color: {{ $layout['abuse_h2'] }}">Väärinkäyttö</h2>
<p>Ilmoittaaksesi väärinkäytöstä (spämmi, tietojen kalastelu, haittaohjelmat jne.)

    Meidän väärinkäyttöilmoituksia käsittelevä ryhmämme käy jokaisen ilmoituksen läpi ja määrittelee väärinkäytön lajin.</p>
                <div class="row">
                    <div class="col-md-6">
                        @include('helpers.form_control', ['type' => 'text', 'name' => 'name', 'label' => 'Nimi:', 'value' => '', 'placeholder'=>'Nimi'])
                    </div>
                    <div class="col-md-6">
                        @include('helpers.form_control', ['type' => 'text', 'name' => 'last', 'label' => 'Sukunimi:', 'value' => '', 'placeholder'=> 'Sukunimi'])
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        @include('helpers.form_control', ['type' => 'text', 'name' => 'email', 'label' => 'Sähköposti:', 'value' => '', 'placeholder' => 'Sähköposti' ])
                    </div>
                    {{--<div class="col-md-6">--}}
                        {{--<div class="form-group control-text">--}}
                            {{--<label>Date reported:</label>--}}
                            {{--<input id="datepicker" type="text" name="datetime" class="form-control">--}}
                        {{--</div>--}}
                    {{--</div>--}}
                    <div class="col-md-6">
                        @include('helpers.form_control', ['type' => 'textarea', 'name' => 'report', 'label' => 'Viesti', 'value' => ''])
                    </div>
                </div>

                <div class="text-left">
                    <button class="btn" style="color: {{ $layout['button'] }}"><i class="icon-check"></i>Lähetä</button>
                </div>
            </div>

        </form>
        <script>
            $('#datepicker').datepicker({
                format: 'yyyy-dd-mm'
            });
        </script>
@endif
<!-- Footer -->
    <div class="footer text-white">
        {{--{!! trans('messages.copy_right_light') !!}			--}}
    </div>
    <!-- /footer -->

</div>
<!-- /page container -->

{{--@endsection--}}