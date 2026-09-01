<!DOCTYPE html>
<html lang="es" class="h-full bg-[#0B0C0E]">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Stamless</title>
    <meta name="description" content="Una fuente. Todos los sitios.">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400&family=IBM+Plex+Serif:wght@500&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'IBM Plex Sans', sans-serif;
            background-color: #0B0C0E;
            color: #F4F1EA;
            margin: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            min-height: 100dvh;
            padding: 2rem;
            box-sizing: border-box;
            overflow: hidden;
            position: relative;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .main-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            max-width: 40rem;
            width: 100%;
            transform: translateY(-4vh);
        }

        .title {
            font-family: 'IBM Plex Serif', serif;
            font-size: clamp(3.25rem, 9vw, 6.5rem);
            font-weight: 500;
            margin: 0;
            letter-spacing: -0.04em;
            line-height: 0.95;
            color: #F4F1EA;
        }

        .subtitle {
            font-family: 'IBM Plex Sans', sans-serif;
            font-size: clamp(0.95rem, 1.6vw, 1.125rem);
            font-weight: 400;
            color: #8B8680;
            margin: 1.25rem 0 0 0;
            letter-spacing: 0.02em;
        }

        .footer {
            position: absolute;
            bottom: 2rem;
            left: 2rem;
            right: 2rem;
            display: flex;
            justify-content: space-between;
            width: calc(100% - 4rem);
            box-sizing: border-box;
            font-family: 'IBM Plex Sans', sans-serif;
            font-size: 0.7rem;
            font-weight: 400;
            letter-spacing: 0.14em;
            color: #5C5854;
        }

        .footer-left {
            text-transform: lowercase;
        }

        .footer-right {
            text-transform: uppercase;
            color: #C4A574;
        }

        @keyframes genesis-in {
            from { opacity: 0; transform: translate3d(0, 10px, 0); will-change: opacity, transform; }
            to   { opacity: 1; transform: translate3d(0, 0, 0); will-change: auto; }
        }

        .titulo,
        .linea,
        .footer {
            backface-visibility: hidden;
            animation-name: genesis-in;
            animation-timing-function: ease-out;
            animation-fill-mode: both;
        }

        .titulo { animation-duration: 700ms; animation-delay: 100ms; }
        .linea  { animation-duration: 700ms; animation-delay: 350ms; }
        .footer { animation-duration: 600ms; animation-delay: 600ms; }

        @media (prefers-reduced-motion: reduce) {
            .titulo,
            .linea,
            .footer {
                animation: none !important;
                will-change: auto !important;
                opacity: 1 !important;
                transform: none !important;
            }
        }

        @media (max-width: 640px) {
            body {
                padding: 1.5rem;
            }
            .title {
                font-size: clamp(2.75rem, 11vw, 4rem);
            }
            .footer {
                bottom: 1.25rem;
                left: 1.5rem;
                right: 1.5rem;
                width: calc(100% - 3rem);
            }
            .subtitle {
                max-width: 18rem;
                margin-left: auto;
                margin-right: auto;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <h1 class="title titulo">Stamless</h1>
        <p class="subtitle linea">Una fuente. Todos los sitios.</p>
    </div>

    <div class="footer">
        <div class="footer-left">{{ parse_url(config('app.url'), PHP_URL_HOST) }}</div>
        <div class="footer-right">Pronto</div>
    </div>
</body>
</html>
