<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Synapse</title>

    <script>
        window.Synapse = @json(\Redberry\Synapse\Synapse::scriptVariables());

        // Apply the saved (or OS) colour scheme before first paint to avoid a flash.
        (function () {
            var stored = localStorage.getItem('synapse-theme');
            var dark = stored === 'dark' || (stored !== 'light' &&
                window.matchMedia('(prefers-color-scheme: dark)').matches);

            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    {!! \Redberry\Synapse\Synapse::css() !!}
</head>
<body>
    <div id="synapse"></div>

    {!! \Redberry\Synapse\Synapse::js() !!}
</body>
</html>
