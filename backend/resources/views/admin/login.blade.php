{{--
    Operator login for the Horizon dashboard.

    Self-contained on purpose: styles are inline rather than pulled through
    Vite. `backend/public/` has no build output and the React client is a
    separate application, so an @vite() directive here would fail on exactly
    the machine this page exists to serve — a production box where something in
    the queue has gone wrong and nobody has run `npm run build` for the Laravel
    app (nor should they need to).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Operator sign in · {{ config('app.name') }}</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f5f7;
            --card: #ffffff;
            --ink: #16181d;
            --muted: #6b7280;
            --line: #d8dbe0;
            --accent: #2f6fed;
            --error: #b42318;
            --error-bg: #fef3f2;
            --error-line: #fecdca;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0e1013;
                --card: #191c21;
                --ink: #e8eaed;
                --muted: #9aa1ac;
                --line: #2c3138;
                --accent: #6f9bff;
                --error: #ff9a92;
                --error-bg: #2a1614;
                --error-line: #5c2a25;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--bg);
            color: var(--ink);
            font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        .card {
            width: 100%;
            max-width: 380px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .04), 0 8px 24px rgba(0, 0, 0, .06);
        }

        h1 { margin: 0 0 4px; font-size: 18px; font-weight: 600; }

        .sub { margin: 0 0 22px; color: var(--muted); font-size: 13px; }

        label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; }

        input[type="email"], input[type="password"] {
            width: 100%;
            padding: 9px 11px;
            margin-bottom: 16px;
            font: inherit;
            color: var(--ink);
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 7px;
        }

        input:focus {
            outline: 2px solid var(--accent);
            outline-offset: 1px;
            border-color: var(--accent);
        }

        button {
            width: 100%;
            padding: 10px;
            font: inherit;
            font-weight: 600;
            color: #fff;
            background: var(--accent);
            border: 0;
            border-radius: 7px;
            cursor: pointer;
        }

        button:hover { filter: brightness(1.07); }

        .errors {
            margin: 0 0 18px;
            padding: 10px 12px;
            background: var(--error-bg);
            border: 1px solid var(--error-line);
            border-radius: 7px;
            color: var(--error);
            font-size: 13px;
        }

        .errors ul { margin: 0; padding-left: 18px; }
        .errors li + li { margin-top: 4px; }

        .foot { margin: 20px 0 0; color: var(--muted); font-size: 12px; text-align: center; }
    </style>
</head>
<body>
    <main class="card">
        <h1>Operator sign in</h1>
        <p class="sub">Queue dashboard access. Administrator accounts only.</p>

        @if ($errors->any())
            <div class="errors" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.login.attempt') }}">
            @csrf

            <label for="email">Email</label>
            <input id="email"
                   type="email"
                   name="email"
                   value="{{ old('email') }}"
                   required
                   autofocus
                   autocomplete="username">

            <label for="password">Password</label>
            <input id="password"
                   type="password"
                   name="password"
                   required
                   autocomplete="current-password">

            <button type="submit">Sign in</button>
        </form>

        <p class="foot">Signing in grants access to the queue dashboard only.</p>
    </main>
</body>
</html>
