<!--
    Layout HTML compartido por TODOS los emails de notificación de
    formularios, de CUALQUIER tenant (ver ADR de este mismo día,
    2026-09-18: "esos contenidos del mailings tiene que ser como plantilla
    predeterminada para todos los clientes con sus tenants"). Lo único que
    cambia por tenant es $logoUrl/$siteName (ver
    `ContactSubmissionService::resolveBrandLogoUrl()`) — el resto del
    marcado es fijo, para que un cambio de diseño futuro se haga en UN
    solo lugar, no por tenant.

    HTML + estilos inline a propósito (no `@component('mail::message')`
    de Laravel): ese componente no expone un slot de header/footer
    override-able desde la vista de contenido sin publicar y tocar las
    vistas de `resources/views/vendor/mail/` — escribir el HTML propio acá
    da control total sobre dónde va el logo sin ese paso extra. Tablas +
    estilos inline porque los clientes de correo (Outlook, Gmail) no
    soportan CSS moderno de forma confiable.
-->
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('subject', $siteName ?? config('app.name'))</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:Helvetica,Arial,sans-serif;color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width:600px;background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="padding:28px 32px;border-bottom:1px solid #e4e4e7;">
                            @if(! empty($logoUrl))
                                <img src="{{ $logoUrl }}" alt="{{ $siteName ?? config('app.name') }}" style="max-height:44px;max-width:220px;display:block;border:0;">
                            @else
                                <span style="font-size:18px;font-weight:700;color:#18181b;">{{ $siteName ?? config('app.name') }}</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 32px;border-top:1px solid #e4e4e7;font-size:12px;color:#71717a;">
                            © {{ date('Y') }} {{ $siteName ?? config('app.name') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
