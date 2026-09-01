<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Países disponibles para un `Service` (campo `countries`, jsonb, array de
 * códigos), y para cualquier otro recurso del CMS que en el futuro necesite
 * un selector de país por tenant.
 *
 * 2026-08-31 — Rediseño a pedido del Tech Lead (ver ADR-035): reemplaza la
 * versión anterior (6 casos, específicos de CICA360 — ar/uy/py/ec/us/global)
 * por el listado ISO 3166-1 alpha-2 completo (249 entradas). Motivo:
 *
 * - Un `Enum` PHP es, por definición, un catálogo global compartido por todo
 *   el código — no vive en una tabla `tenant_id`-scoped. Al cubrir TODOS los
 *   países del mundo (no solo los de CICA360), este mismo Enum ya sirve tal
 *   cual para cualquier tenant futuro, sin tocar código ni sembrar datos por
 *   cliente — eso es lo que lo hace "reutilizable por cualquier tenant".
 * - El VALOR de cada caso es el código ISO 3166-1 alpha-2 en mayúsculas
 *   (ej.: `PE` para Perú) — es la misma clave que usa la convención de
 *   banderas del frontend: `media/flags/flag_{ISO}.webp` (ver `flagPath()`).
 * - `Global` (valor `GLOBAL`) es la única excepción no-ISO: cubre servicios
 *   sin país puntual ("Regional / Global"). No tiene bandera — el frontend
 *   debe mostrar un ícono genérico cuando `flagPath()` devuelve `null`.
 *
 * Nombres en español tomados de CLDR/es (Unicode). El campo `countries` en
 * `Service` NO es requerido — un servicio puede no tener país (ej.: aplica a
 * cualquier país, o el país no es un dato relevante para ese servicio).
 */
enum CountryEnum: string implements HasLabel
{
    case Andorra = 'AD';
    case EmiratosArabesUnidos = 'AE';
    case Afganistan = 'AF';
    case AntiguaYBarbuda = 'AG';
    case Anguila = 'AI';
    case Albania = 'AL';
    case Armenia = 'AM';
    case Angola = 'AO';
    case Antartida = 'AQ';
    case Argentina = 'AR';
    case SamoaAmericana = 'AS';
    case Austria = 'AT';
    case Australia = 'AU';
    case Aruba = 'AW';
    case IslasAland = 'AX';
    case Azerbaiyan = 'AZ';
    case BosniaYHerzegovina = 'BA';
    case Barbados = 'BB';
    case Banglades = 'BD';
    case Belgica = 'BE';
    case BurkinaFaso = 'BF';
    case Bulgaria = 'BG';
    case Barein = 'BH';
    case Burundi = 'BI';
    case Benin = 'BJ';
    case SanBartolome = 'BL';
    case Bermudas = 'BM';
    case Brunei = 'BN';
    case Bolivia = 'BO';
    case BonaireSanEustaquioYSaba = 'BQ';
    case Brasil = 'BR';
    case Bahamas = 'BS';
    case Butan = 'BT';
    case IslaBouvet = 'BV';
    case Botsuana = 'BW';
    case Bielorrusia = 'BY';
    case Belice = 'BZ';
    case Canada = 'CA';
    case IslasCocos = 'CC';
    case RepublicaDemocraticaDelCongo = 'CD';
    case RepublicaCentroafricana = 'CF';
    case Congo = 'CG';
    case Suiza = 'CH';
    case CostaDeMarfil = 'CI';
    case IslasCook = 'CK';
    case Chile = 'CL';
    case Camerun = 'CM';
    case China = 'CN';
    case Colombia = 'CO';
    case CostaRica = 'CR';
    case Cuba = 'CU';
    case CaboVerde = 'CV';
    case Curazao = 'CW';
    case IslaDeNavidad = 'CX';
    case Chipre = 'CY';
    case RepublicaCheca = 'CZ';
    case Alemania = 'DE';
    case Yibuti = 'DJ';
    case Dinamarca = 'DK';
    case Dominica = 'DM';
    case RepublicaDominicana = 'DO';
    case Argelia = 'DZ';
    case Ecuador = 'EC';
    case Estonia = 'EE';
    case Egipto = 'EG';
    case SaharaOccidental = 'EH';
    case Eritrea = 'ER';
    case Espana = 'ES';
    case Etiopia = 'ET';
    case Finlandia = 'FI';
    case Fiyi = 'FJ';
    case IslasMalvinas = 'FK';
    case Micronesia = 'FM';
    case IslasFeroe = 'FO';
    case Francia = 'FR';
    case Gabon = 'GA';
    case ReinoUnido = 'GB';
    case Granada = 'GD';
    case Georgia = 'GE';
    case Guernsey = 'GG';
    case Ghana = 'GH';
    case Gibraltar = 'GI';
    case Groenlandia = 'GL';
    case Gambia = 'GM';
    case Guinea = 'GN';
    case Guadalupe = 'GP';
    case GuineaEcuatorial = 'GQ';
    case Grecia = 'GR';
    case GeorgiaDelSurYLasIslasSandwichDelSur = 'GS';
    case Guatemala = 'GT';
    case Guam = 'GU';
    case GuineaBisau = 'GW';
    case Guyana = 'GY';
    case HongKong = 'HK';
    case IslasHeardYMcdonald = 'HM';
    case Honduras = 'HN';
    case Croacia = 'HR';
    case Haiti = 'HT';
    case Hungria = 'HU';
    case Indonesia = 'ID';
    case Irlanda = 'IE';
    case Israel = 'IL';
    case IslaDeMan = 'IM';
    case India = 'IN';
    case TerritorioBritanicoDelOceanoIndico = 'IO';
    case Irak = 'IQ';
    case Iran = 'IR';
    case Islandia = 'IS';
    case Italia = 'IT';
    case Jersey = 'JE';
    case Jamaica = 'JM';
    case Jordania = 'JO';
    case Japon = 'JP';
    case Kenia = 'KE';
    case Kirguistan = 'KG';
    case Camboya = 'KH';
    case Kiribati = 'KI';
    case Comoras = 'KM';
    case SanCristobalYNieves = 'KN';
    case CoreaDelNorte = 'KP';
    case CoreaDelSur = 'KR';
    case Kuwait = 'KW';
    case IslasCaiman = 'KY';
    case Kazajistan = 'KZ';
    case Laos = 'LA';
    case Libano = 'LB';
    case SantaLucia = 'LC';
    case Liechtenstein = 'LI';
    case SriLanka = 'LK';
    case Liberia = 'LR';
    case Lesoto = 'LS';
    case Lituania = 'LT';
    case Luxemburgo = 'LU';
    case Letonia = 'LV';
    case Libia = 'LY';
    case Marruecos = 'MA';
    case Monaco = 'MC';
    case Moldavia = 'MD';
    case Montenegro = 'ME';
    case SanMartin = 'MF';
    case Madagascar = 'MG';
    case IslasMarshall = 'MH';
    case MacedoniaDelNorte = 'MK';
    case Mali = 'ML';
    case Myanmar = 'MM';
    case Mongolia = 'MN';
    case Macao = 'MO';
    case IslasMarianasDelNorte = 'MP';
    case Martinica = 'MQ';
    case Mauritania = 'MR';
    case Montserrat = 'MS';
    case Malta = 'MT';
    case Mauricio = 'MU';
    case Maldivas = 'MV';
    case Malaui = 'MW';
    case Mexico = 'MX';
    case Malasia = 'MY';
    case Mozambique = 'MZ';
    case Namibia = 'NA';
    case NuevaCaledonia = 'NC';
    case Niger = 'NE';
    case IslaNorfolk = 'NF';
    case Nigeria = 'NG';
    case Nicaragua = 'NI';
    case PaisesBajos = 'NL';
    case Noruega = 'NO';
    case Nepal = 'NP';
    case Nauru = 'NR';
    case Niue = 'NU';
    case NuevaZelanda = 'NZ';
    case Oman = 'OM';
    case Panama = 'PA';
    case Peru = 'PE';
    case PolinesiaFrancesa = 'PF';
    case PapuaNuevaGuinea = 'PG';
    case Filipinas = 'PH';
    case Pakistan = 'PK';
    case Polonia = 'PL';
    case SanPedroYMiquelon = 'PM';
    case IslasPitcairn = 'PN';
    case PuertoRico = 'PR';
    case Palestina = 'PS';
    case Portugal = 'PT';
    case Palaos = 'PW';
    case Paraguay = 'PY';
    case Catar = 'QA';
    case Reunion = 'RE';
    case Rumania = 'RO';
    case Serbia = 'RS';
    case Rusia = 'RU';
    case Ruanda = 'RW';
    case ArabiaSaudita = 'SA';
    case IslasSalomon = 'SB';
    case Seychelles = 'SC';
    case Sudan = 'SD';
    case Suecia = 'SE';
    case Singapur = 'SG';
    case SantaElena = 'SH';
    case Eslovenia = 'SI';
    case SvalbardYJanMayen = 'SJ';
    case Eslovaquia = 'SK';
    case SierraLeona = 'SL';
    case SanMarino = 'SM';
    case Senegal = 'SN';
    case Somalia = 'SO';
    case Surinam = 'SR';
    case SudanDelSur = 'SS';
    case SantoTomeYPrincipe = 'ST';
    case ElSalvador = 'SV';
    case SintMaarten = 'SX';
    case Siria = 'SY';
    case Esuatini = 'SZ';
    case IslasTurcasYCaicos = 'TC';
    case Chad = 'TD';
    case TerritoriosAustralesFranceses = 'TF';
    case Togo = 'TG';
    case Tailandia = 'TH';
    case Tayikistan = 'TJ';
    case Tokelau = 'TK';
    case TimorOriental = 'TL';
    case Turkmenistan = 'TM';
    case Tunez = 'TN';
    case Tonga = 'TO';
    case Turquia = 'TR';
    case TrinidadYTobago = 'TT';
    case Tuvalu = 'TV';
    case Taiwan = 'TW';
    case Tanzania = 'TZ';
    case Ucrania = 'UA';
    case Uganda = 'UG';
    case IslasMenoresAlejadasDeEeUu = 'UM';
    case EstadosUnidos = 'US';
    case Uruguay = 'UY';
    case Uzbekistan = 'UZ';
    case CiudadDelVaticano = 'VA';
    case SanVicenteYLasGranadinas = 'VC';
    case Venezuela = 'VE';
    case IslasVirgenesBritanicas = 'VG';
    case IslasVirgenesDeEeUu = 'VI';
    case Vietnam = 'VN';
    case Vanuatu = 'VU';
    case WallisYFutuna = 'WF';
    case Samoa = 'WS';
    case Kosovo = 'XK';
    case Yemen = 'YE';
    case Mayotte = 'YT';
    case Sudafrica = 'ZA';
    case Zambia = 'ZM';
    case Zimbabue = 'ZW';

    // Cobertura regional / sin país puntual — no es un código ISO real.
    case Global = 'GLOBAL';

    public function getLabel(): string
    {
        return match ($this) {
            self::Andorra => 'Andorra',
            self::EmiratosArabesUnidos => 'Emiratos Árabes Unidos',
            self::Afganistan => 'Afganistán',
            self::AntiguaYBarbuda => 'Antigua y Barbuda',
            self::Anguila => 'Anguila',
            self::Albania => 'Albania',
            self::Armenia => 'Armenia',
            self::Angola => 'Angola',
            self::Antartida => 'Antártida',
            self::Argentina => 'Argentina',
            self::SamoaAmericana => 'Samoa Americana',
            self::Austria => 'Austria',
            self::Australia => 'Australia',
            self::Aruba => 'Aruba',
            self::IslasAland => 'Islas Åland',
            self::Azerbaiyan => 'Azerbaiyán',
            self::BosniaYHerzegovina => 'Bosnia y Herzegovina',
            self::Barbados => 'Barbados',
            self::Banglades => 'Bangladés',
            self::Belgica => 'Bélgica',
            self::BurkinaFaso => 'Burkina Faso',
            self::Bulgaria => 'Bulgaria',
            self::Barein => 'Baréin',
            self::Burundi => 'Burundi',
            self::Benin => 'Benín',
            self::SanBartolome => 'San Bartolomé',
            self::Bermudas => 'Bermudas',
            self::Brunei => 'Brunéi',
            self::Bolivia => 'Bolivia',
            self::BonaireSanEustaquioYSaba => 'Bonaire, San Eustaquio y Saba',
            self::Brasil => 'Brasil',
            self::Bahamas => 'Bahamas',
            self::Butan => 'Bután',
            self::IslaBouvet => 'Isla Bouvet',
            self::Botsuana => 'Botsuana',
            self::Bielorrusia => 'Bielorrusia',
            self::Belice => 'Belice',
            self::Canada => 'Canadá',
            self::IslasCocos => 'Islas Cocos',
            self::RepublicaDemocraticaDelCongo => 'República Democrática del Congo',
            self::RepublicaCentroafricana => 'República Centroafricana',
            self::Congo => 'Congo',
            self::Suiza => 'Suiza',
            self::CostaDeMarfil => 'Costa de Marfil',
            self::IslasCook => 'Islas Cook',
            self::Chile => 'Chile',
            self::Camerun => 'Camerún',
            self::China => 'China',
            self::Colombia => 'Colombia',
            self::CostaRica => 'Costa Rica',
            self::Cuba => 'Cuba',
            self::CaboVerde => 'Cabo Verde',
            self::Curazao => 'Curazao',
            self::IslaDeNavidad => 'Isla de Navidad',
            self::Chipre => 'Chipre',
            self::RepublicaCheca => 'República Checa',
            self::Alemania => 'Alemania',
            self::Yibuti => 'Yibuti',
            self::Dinamarca => 'Dinamarca',
            self::Dominica => 'Dominica',
            self::RepublicaDominicana => 'República Dominicana',
            self::Argelia => 'Argelia',
            self::Ecuador => 'Ecuador',
            self::Estonia => 'Estonia',
            self::Egipto => 'Egipto',
            self::SaharaOccidental => 'Sahara Occidental',
            self::Eritrea => 'Eritrea',
            self::Espana => 'España',
            self::Etiopia => 'Etiopía',
            self::Finlandia => 'Finlandia',
            self::Fiyi => 'Fiyi',
            self::IslasMalvinas => 'Islas Malvinas',
            self::Micronesia => 'Micronesia',
            self::IslasFeroe => 'Islas Feroe',
            self::Francia => 'Francia',
            self::Gabon => 'Gabón',
            self::ReinoUnido => 'Reino Unido',
            self::Granada => 'Granada',
            self::Georgia => 'Georgia',
            self::Guernsey => 'Guernsey',
            self::Ghana => 'Ghana',
            self::Gibraltar => 'Gibraltar',
            self::Groenlandia => 'Groenlandia',
            self::Gambia => 'Gambia',
            self::Guinea => 'Guinea',
            self::Guadalupe => 'Guadalupe',
            self::GuineaEcuatorial => 'Guinea Ecuatorial',
            self::Grecia => 'Grecia',
            self::GeorgiaDelSurYLasIslasSandwichDelSur => 'Georgia del Sur y las Islas Sandwich del Sur',
            self::Guatemala => 'Guatemala',
            self::Guam => 'Guam',
            self::GuineaBisau => 'Guinea-Bisáu',
            self::Guyana => 'Guyana',
            self::HongKong => 'Hong Kong',
            self::IslasHeardYMcdonald => 'Islas Heard y McDonald',
            self::Honduras => 'Honduras',
            self::Croacia => 'Croacia',
            self::Haiti => 'Haití',
            self::Hungria => 'Hungría',
            self::Indonesia => 'Indonesia',
            self::Irlanda => 'Irlanda',
            self::Israel => 'Israel',
            self::IslaDeMan => 'Isla de Man',
            self::India => 'India',
            self::TerritorioBritanicoDelOceanoIndico => 'Territorio Británico del Océano Índico',
            self::Irak => 'Irak',
            self::Iran => 'Irán',
            self::Islandia => 'Islandia',
            self::Italia => 'Italia',
            self::Jersey => 'Jersey',
            self::Jamaica => 'Jamaica',
            self::Jordania => 'Jordania',
            self::Japon => 'Japón',
            self::Kenia => 'Kenia',
            self::Kirguistan => 'Kirguistán',
            self::Camboya => 'Camboya',
            self::Kiribati => 'Kiribati',
            self::Comoras => 'Comoras',
            self::SanCristobalYNieves => 'San Cristóbal y Nieves',
            self::CoreaDelNorte => 'Corea del Norte',
            self::CoreaDelSur => 'Corea del Sur',
            self::Kuwait => 'Kuwait',
            self::IslasCaiman => 'Islas Caimán',
            self::Kazajistan => 'Kazajistán',
            self::Laos => 'Laos',
            self::Libano => 'Líbano',
            self::SantaLucia => 'Santa Lucía',
            self::Liechtenstein => 'Liechtenstein',
            self::SriLanka => 'Sri Lanka',
            self::Liberia => 'Liberia',
            self::Lesoto => 'Lesoto',
            self::Lituania => 'Lituania',
            self::Luxemburgo => 'Luxemburgo',
            self::Letonia => 'Letonia',
            self::Libia => 'Libia',
            self::Marruecos => 'Marruecos',
            self::Monaco => 'Mónaco',
            self::Moldavia => 'Moldavia',
            self::Montenegro => 'Montenegro',
            self::SanMartin => 'San Martín',
            self::Madagascar => 'Madagascar',
            self::IslasMarshall => 'Islas Marshall',
            self::MacedoniaDelNorte => 'Macedonia del Norte',
            self::Mali => 'Malí',
            self::Myanmar => 'Myanmar',
            self::Mongolia => 'Mongolia',
            self::Macao => 'Macao',
            self::IslasMarianasDelNorte => 'Islas Marianas del Norte',
            self::Martinica => 'Martinica',
            self::Mauritania => 'Mauritania',
            self::Montserrat => 'Montserrat',
            self::Malta => 'Malta',
            self::Mauricio => 'Mauricio',
            self::Maldivas => 'Maldivas',
            self::Malaui => 'Malaui',
            self::Mexico => 'México',
            self::Malasia => 'Malasia',
            self::Mozambique => 'Mozambique',
            self::Namibia => 'Namibia',
            self::NuevaCaledonia => 'Nueva Caledonia',
            self::Niger => 'Níger',
            self::IslaNorfolk => 'Isla Norfolk',
            self::Nigeria => 'Nigeria',
            self::Nicaragua => 'Nicaragua',
            self::PaisesBajos => 'Países Bajos',
            self::Noruega => 'Noruega',
            self::Nepal => 'Nepal',
            self::Nauru => 'Nauru',
            self::Niue => 'Niue',
            self::NuevaZelanda => 'Nueva Zelanda',
            self::Oman => 'Omán',
            self::Panama => 'Panamá',
            self::Peru => 'Perú',
            self::PolinesiaFrancesa => 'Polinesia Francesa',
            self::PapuaNuevaGuinea => 'Papúa Nueva Guinea',
            self::Filipinas => 'Filipinas',
            self::Pakistan => 'Pakistán',
            self::Polonia => 'Polonia',
            self::SanPedroYMiquelon => 'San Pedro y Miquelón',
            self::IslasPitcairn => 'Islas Pitcairn',
            self::PuertoRico => 'Puerto Rico',
            self::Palestina => 'Palestina',
            self::Portugal => 'Portugal',
            self::Palaos => 'Palaos',
            self::Paraguay => 'Paraguay',
            self::Catar => 'Catar',
            self::Reunion => 'Reunión',
            self::Rumania => 'Rumania',
            self::Serbia => 'Serbia',
            self::Rusia => 'Rusia',
            self::Ruanda => 'Ruanda',
            self::ArabiaSaudita => 'Arabia Saudita',
            self::IslasSalomon => 'Islas Salomón',
            self::Seychelles => 'Seychelles',
            self::Sudan => 'Sudán',
            self::Suecia => 'Suecia',
            self::Singapur => 'Singapur',
            self::SantaElena => 'Santa Elena',
            self::Eslovenia => 'Eslovenia',
            self::SvalbardYJanMayen => 'Svalbard y Jan Mayen',
            self::Eslovaquia => 'Eslovaquia',
            self::SierraLeona => 'Sierra Leona',
            self::SanMarino => 'San Marino',
            self::Senegal => 'Senegal',
            self::Somalia => 'Somalia',
            self::Surinam => 'Surinam',
            self::SudanDelSur => 'Sudán del Sur',
            self::SantoTomeYPrincipe => 'Santo Tomé y Príncipe',
            self::ElSalvador => 'El Salvador',
            self::SintMaarten => 'Sint Maarten',
            self::Siria => 'Siria',
            self::Esuatini => 'Esuatini',
            self::IslasTurcasYCaicos => 'Islas Turcas y Caicos',
            self::Chad => 'Chad',
            self::TerritoriosAustralesFranceses => 'Territorios Australes Franceses',
            self::Togo => 'Togo',
            self::Tailandia => 'Tailandia',
            self::Tayikistan => 'Tayikistán',
            self::Tokelau => 'Tokelau',
            self::TimorOriental => 'Timor Oriental',
            self::Turkmenistan => 'Turkmenistán',
            self::Tunez => 'Túnez',
            self::Tonga => 'Tonga',
            self::Turquia => 'Turquía',
            self::TrinidadYTobago => 'Trinidad y Tobago',
            self::Tuvalu => 'Tuvalu',
            self::Taiwan => 'Taiwán',
            self::Tanzania => 'Tanzania',
            self::Ucrania => 'Ucrania',
            self::Uganda => 'Uganda',
            self::IslasMenoresAlejadasDeEeUu => 'Islas Menores Alejadas de EE. UU.',
            self::EstadosUnidos => 'Estados Unidos',
            self::Uruguay => 'Uruguay',
            self::Uzbekistan => 'Uzbekistán',
            self::CiudadDelVaticano => 'Ciudad del Vaticano',
            self::SanVicenteYLasGranadinas => 'San Vicente y las Granadinas',
            self::Venezuela => 'Venezuela',
            self::IslasVirgenesBritanicas => 'Islas Vírgenes Británicas',
            self::IslasVirgenesDeEeUu => 'Islas Vírgenes de EE. UU.',
            self::Vietnam => 'Vietnam',
            self::Vanuatu => 'Vanuatu',
            self::WallisYFutuna => 'Wallis y Futuna',
            self::Samoa => 'Samoa',
            self::Kosovo => 'Kosovo',
            self::Yemen => 'Yemen',
            self::Mayotte => 'Mayotte',
            self::Sudafrica => 'Sudáfrica',
            self::Zambia => 'Zambia',
            self::Zimbabue => 'Zimbabue',
            self::Global => 'Regional / Global',
        };
    }

    /**
     * Ruta relativa (bajo el root de media público) al ícono de bandera, en la
     * convención que usa el frontend: `media/flags/flag_{ISO}.webp`. `null`
     * para `Global`, que no representa un país real — el frontend debe caer a
     * un ícono genérico (globo) en ese caso.
     */
    public function flagPath(): ?string
    {
        return $this === self::Global ? null : "media/flags/flag_{$this->value}.webp";
    }

    /**
     * Forma `['iso' => ..., 'name' => ...]` — la forma que debe exponer la API
     * pública para cada país seleccionado (ver ADR-035). Pensado para mapear
     * sobre el array `countries` guardado en el modelo.
     *
     * @return array{iso: string, name: string}
     */
    public function toApiArray(): array
    {
        return ['iso' => $this->value, 'name' => $this->getLabel()];
    }

    /**
     * Resuelve un array de códigos guardados (ej.: `$service->countries`) a su
     * forma `['iso' => ..., 'name' => ...]` por cada uno, descartando en
     * silencio cualquier código que ya no exista en el catálogo (dato viejo o
     * corrupto) en vez de romper la respuesta.
     *
     * @param  array<int, string>  $codes
     * @return array<int, array{iso: string, name: string}>
     */
    public static function resolveMany(array $codes): array
    {
        return collect($codes)
            ->map(fn (string $code) => self::tryFrom($code))
            ->filter()
            ->map(fn (self $country) => $country->toApiArray())
            ->values()
            ->all();
    }
}
