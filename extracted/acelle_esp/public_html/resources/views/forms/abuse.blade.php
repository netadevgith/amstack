<!DOCTYPE html>
<html lang="en">
<head>
    <title>Report</title>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Global stylesheets -->
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,300,100,500,700,900" rel="stylesheet" type="text/css">
    <link href="/assets/css/icons/icomoon/styles.css" rel="stylesheet" type="text/css">
    <link href="/assets/css/bootstrap.css" rel="stylesheet" type="text/css">
    <link href="/assets/css/core.css" rel="stylesheet" type="text/css">
    <link href="/assets/css/components.css" rel="stylesheet" type="text/css">
    <link href="/assets/css/colors.css" rel="stylesheet" type="text/css">
    <link href="/css/app.css" rel="stylesheet" type="text/css">
    <!-- /global stylesheets -->

    <!-- Core JS files -->
    <script type="text/javascript" src="/assets/js/plugins/loaders/pace.min.js"></script>
    <script type="text/javascript" src="/assets/js/core/libraries/jquery.min.js"></script>
    <script type="text/javascript" src="/assets/js/core/libraries/bootstrap.min.js"></script>
    <script type="text/javascript" src="/assets/js/plugins/loaders/blockui.min.js"></script>
    <script type="text/javascript" src="/assets/js/plugins/ui/nicescroll.min.js"></script>
    <script type="text/javascript" src="/assets/js/plugins/ui/drilldown.js"></script>
    <!-- /core JS files -->

    <!-- Theme JS files -->
    <script type="text/javascript" src="/assets/js/plugins/forms/styling/uniform.min.js"></script>
    <script type="text/javascript" src="/assets/js/core/app.js"></script>
    <!-- /theme JS files -->

</head>

    <body class="bg-slate-800" style="color: {{ $layout['background_foreground'] }};background-color: {{ $layout['background'] }}">

    <!-- Page container -->
    <div class="page-container login-container">

        <!-- Page content -->
        <div class="page-content">

            @if ($error > 0)
                <h3 style="color: red">Please specify the correct email address!</h3>
            @endif

            @if ($received > 0)
                <h3>We got your message. Thank you for filling out our form!</h3>
            @else
                <form action="" method="POST" class="form-validate-jqueryz">
                    {{ csrf_field() }}

                    <div class="sub_section">
                        <h2 class="text-semibold" style="color: {{ $layout['abuse_h2'] }}">Abuse</h2>
                        <p>To report abuse (spammy, phishing, malware, etc.)
                           Our group of misrepresentations goes through every report and defines the type of abuse.</p>

                        <div class="row">
                            <div class="col-md-6">
                                @include('helpers.form_control', ['type' => 'text', 'name' => 'name', 'label' => 'First name', 'value' => '', 'placeholder' => 'First Name'])
                            </div>
                            <div class="col-md-6">
                                @include('helpers.form_control', ['type' => 'text', 'name' => 'last', 'label' => 'Last name', 'value' => '', 'placeholder' => 'Last Name'])
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                @include('helpers.form_control', ['type' => 'text', 'name' => 'email', 'label' => 'Your email', 'value' => '', 'placeholder' => 'Your Email'])
                            </div>
                            <div class="col-md-6">
                                @include('helpers.form_control', ['type' => 'textarea', 'name' => 'report', 'label' => 'Report text', 'value' => ''])
                            </div>
                        </div>

                        <div class="text-left">
                            <button class="btn" style="color: {{ $layout['button'] }}"><i class="icon-check"></i> Report</button>
                        </div>
                    </div>

                </form>
            @endif

        </div>
        <!-- /page content -->

        <!-- Footer -->
        <div class="footer text-white"></div>
        <!-- /footer -->

    </div>
    <!-- /page container -->
</body>
</html>
