{{--
    Copia/constancia al visitante que completó el formulario — ver
    `App\Mail\ContactFormReceipt` y `ContactSubmissionService::notifySubmitter()`.
    Reusa el mismo contenido de la "Página de Agradecimiento"
    ($thankYou, resuelto por `ContactSubmissionService::resolveThankYouData()`)
    que el visitante ya ve en pantalla tras enviar — nunca textos propios
    duplicados acá.
--}}
@extends('emails.layouts.branded')

@section('content')
    <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;color:#18181b;">{{ $thankYou['title'] }}</h1>

    @if(! empty($thankYou['description']))
        <div style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#3f3f46;">{!! $thankYou['description'] !!}</div>
    @endif

    @if(! empty($thankYou['alert_title']) || ! empty($thankYou['alert_description']))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
            <tr>
                <td style="padding:14px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:13px;color:#92400e;">
                    @if(! empty($thankYou['alert_title']))
                        <strong style="display:block;margin-bottom:2px;">{{ $thankYou['alert_title'] }}</strong>
                    @endif
                    @if(! empty($thankYou['alert_description']))
                        {{ $thankYou['alert_description'] }}
                    @endif
                </td>
            </tr>
        </table>
    @endif

    <p style="margin:0;font-size:13px;color:#71717a;">Formulario: <strong>{{ $form->name }}</strong></p>
@endsection
