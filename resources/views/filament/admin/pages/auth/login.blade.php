<!DOCTYPE html>
<html>

<head>
    <title>Test Login</title>
    @livewireStyles
</head>

<body>
    <form method="POST" action="{{ route('filament.admin.auth.login') }}">
        @csrf
        <input type="email" name="email" value="admin@haldeki.local">
        <input type="password" name="password" value="Admin.123!">
        <button type="submit">Login</button>
    </form>
    @livewireScripts
</body>

</html>
