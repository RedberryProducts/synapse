<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Synapse</title>

    <script>
        window.Synapse = @json(\Redberry\Synapse\Synapse::scriptVariables());
    </script>

    {!! \Redberry\Synapse\Synapse::css() !!}
</head>
<body>
    <div id="synapse"></div>

    {!! \Redberry\Synapse\Synapse::js() !!}
</body>
</html>
