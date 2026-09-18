{{--
    Notificación al admin de un nuevo Contact — ver
    `App\Mail\ContactFormSubmitted` y `ContactSubmissionService::notifyAdmin()`.
    Plantilla compartida por todos los tenants (ver docblock de
    emails.layouts.branded); lo único personalizable por Form es
    $notificationIntro (opcional, `Form::notification_intro`).
--}}
@extends('emails.layouts.branded')

@section('content')
    <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;color:#18181b;">Nuevo contacto recibido</h1>

    @if(! empty($notificationIntro))
        <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#3f3f46;">{{ $notificationIntro }}</p>
    @else
        <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#3f3f46;">Se recibió un nuevo envío del formulario <strong>{{ $formName }}</strong>.</p>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#18181b;margin-bottom:20px;">
        @if($contactName)
            <tr>
                <td style="padding:4px 12px 4px 0;font-weight:600;white-space:nowrap;">Nombre</td>
                <td style="padding:4px 0;">{{ $contactName }}</td>
            </tr>
        @endif
        @if($contactEmail)
            <tr>
                <td style="padding:4px 12px 4px 0;font-weight:600;white-space:nowrap;">Email</td>
                <td style="padding:4px 0;">{{ $contactEmail }}</td>
            </tr>
        @endif
        @if($contactPhone)
            <tr>
                <td style="padding:4px 12px 4px 0;font-weight:600;white-space:nowrap;">Teléfono</td>
                <td style="padding:4px 0;">{{ $contactPhone }}</td>
            </tr>
        @endif
        @if($contactCompany)
            <tr>
                <td style="padding:4px 12px 4px 0;font-weight:600;white-space:nowrap;">Empresa</td>
                <td style="padding:4px 0;">{{ $contactCompany }}</td>
            </tr>
        @endif
    </table>

    @if(count($fields))
        <h2 style="margin:24px 0 12px;font-size:15px;color:#18181b;">Otros campos</h2>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;border-collapse:collapse;">
            <tr>
                <th align="left" style="padding:6px 8px;background:#f4f4f5;border:1px solid #e4e4e7;">Campo</th>
                <th align="left" style="padding:6px 8px;background:#f4f4f5;border:1px solid #e4e4e7;">Valor</th>
            </tr>
            @foreach($fields as $key => $value)
                <tr>
                    <td style="padding:6px 8px;border:1px solid #e4e4e7;">{{ $key }}</td>
                    <td style="padding:6px 8px;border:1px solid #e4e4e7;">{{ is_scalar($value) ? $value : json_encode($value) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
@endsection
