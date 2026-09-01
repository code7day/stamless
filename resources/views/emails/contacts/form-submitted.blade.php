@component('mail::message')
# Nuevo contacto recibido

Se recibió un nuevo envío del formulario **{{ $formName }}**.

@if($contactName)
**Nombre:** {{ $contactName }}
@endif
@if($contactEmail)
**Email:** {{ $contactEmail }}
@endif
@if($contactPhone)
**Teléfono:** {{ $contactPhone }}
@endif
@if($contactCompany)
**Empresa:** {{ $contactCompany }}
@endif

@if(count($fields))
## Otros campos

@component('mail::table')
| Campo | Valor |
| :---- | :---- |
@foreach($fields as $key => $value)
| {{ $key }} | {{ is_scalar($value) ? $value : json_encode($value) }} |
@endforeach
@endcomponent
@endif

Gracias,<br>
{{ config('app.name') }}
@endcomponent
