<?php

namespace App\Lib\Helpers;

/**
 * ISO 3166-1 alpha-2 country code to continent. Region still needs a City DB;
 * continent does not.
 */
final class ContinentMap
{
    /**
     * @return array{label: string, code: string}|null
     */
    public static function fromCountryCode(string $countryCode): ?array
    {
        $code = strtoupper($countryCode);
        $continent = self::codes()[$code] ?? null;
        if ($continent === null) {
            return null;
        }

        return [
            'label' => self::labels()[$continent],
            'code' => $continent,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function codes(): array
    {
        return [
            'AF' => 'AS', 'AL' => 'EU', 'DZ' => 'AF', 'AS' => 'OC', 'AD' => 'EU',
            'AO' => 'AF', 'AI' => 'NA', 'AQ' => 'AN', 'AG' => 'NA', 'AR' => 'SA',
            'AM' => 'AS', 'AW' => 'NA', 'AU' => 'OC', 'AT' => 'EU', 'AZ' => 'AS',
            'BS' => 'NA', 'BH' => 'AS', 'BD' => 'AS', 'BB' => 'NA', 'BY' => 'EU',
            'BE' => 'EU', 'BZ' => 'NA', 'BJ' => 'AF', 'BM' => 'NA', 'BT' => 'AS',
            'BO' => 'SA', 'BQ' => 'NA', 'BA' => 'EU', 'BW' => 'AF', 'BV' => 'AN',
            'BR' => 'SA', 'IO' => 'AS', 'BN' => 'AS', 'BG' => 'EU', 'BF' => 'AF',
            'BI' => 'AF', 'CV' => 'AF', 'KH' => 'AS', 'CM' => 'AF', 'CA' => 'NA',
            'KY' => 'NA', 'CF' => 'AF', 'TD' => 'AF', 'CL' => 'SA', 'CN' => 'AS',
            'CX' => 'OC', 'CC' => 'AS', 'CO' => 'SA', 'KM' => 'AF', 'CG' => 'AF',
            'CD' => 'AF', 'CK' => 'OC', 'CR' => 'NA', 'CI' => 'AF', 'HR' => 'EU',
            'CU' => 'NA', 'CW' => 'NA', 'CY' => 'AS', 'CZ' => 'EU', 'DK' => 'EU',
            'DJ' => 'AF', 'DM' => 'NA', 'DO' => 'NA', 'EC' => 'SA', 'EG' => 'AF',
            'SV' => 'NA', 'GQ' => 'AF', 'ER' => 'AF', 'EE' => 'EU', 'SZ' => 'AF',
            'ET' => 'AF', 'FK' => 'SA', 'FO' => 'EU', 'FJ' => 'OC', 'FI' => 'EU',
            'FR' => 'EU', 'GF' => 'SA', 'PF' => 'OC', 'TF' => 'AN', 'GA' => 'AF',
            'GM' => 'AF', 'GE' => 'AS', 'DE' => 'EU', 'GH' => 'AF', 'GI' => 'EU',
            'GR' => 'EU', 'GL' => 'NA', 'GD' => 'NA', 'GP' => 'NA', 'GU' => 'OC',
            'GT' => 'NA', 'GG' => 'EU', 'GN' => 'AF', 'GW' => 'AF', 'GY' => 'SA',
            'HT' => 'NA', 'HM' => 'AN', 'VA' => 'EU', 'HN' => 'NA', 'HK' => 'AS',
            'HU' => 'EU', 'IS' => 'EU', 'IN' => 'AS', 'ID' => 'AS', 'IR' => 'AS',
            'IQ' => 'AS', 'IE' => 'EU', 'IM' => 'EU', 'IL' => 'AS', 'IT' => 'EU',
            'JM' => 'NA', 'JP' => 'AS', 'JE' => 'EU', 'JO' => 'AS', 'KZ' => 'AS',
            'KE' => 'AF', 'KI' => 'OC', 'KP' => 'AS', 'KR' => 'AS', 'KW' => 'AS',
            'KG' => 'AS', 'LA' => 'AS', 'LV' => 'EU', 'LB' => 'AS', 'LS' => 'AF',
            'LR' => 'AF', 'LY' => 'AF', 'LI' => 'EU', 'LT' => 'EU', 'LU' => 'EU',
            'MO' => 'AS', 'MG' => 'AF', 'MW' => 'AF', 'MY' => 'AS', 'MV' => 'AS',
            'ML' => 'AF', 'MT' => 'EU', 'MH' => 'OC', 'MQ' => 'NA', 'MR' => 'AF',
            'MU' => 'AF', 'YT' => 'AF', 'MX' => 'NA', 'FM' => 'OC', 'MD' => 'EU',
            'MC' => 'EU', 'MN' => 'AS', 'ME' => 'EU', 'MS' => 'NA', 'MA' => 'AF',
            'MZ' => 'AF', 'MM' => 'AS', 'NA' => 'AF', 'NR' => 'OC', 'NP' => 'AS',
            'NL' => 'EU', 'NC' => 'OC', 'NZ' => 'OC', 'NI' => 'NA', 'NE' => 'AF',
            'NG' => 'AF', 'NU' => 'OC', 'NF' => 'OC', 'MK' => 'EU', 'MP' => 'OC',
            'NO' => 'EU', 'OM' => 'AS', 'PK' => 'AS', 'PW' => 'OC', 'PS' => 'AS',
            'PA' => 'NA', 'PG' => 'OC', 'PY' => 'SA', 'PE' => 'SA', 'PH' => 'AS',
            'PN' => 'OC', 'PL' => 'EU', 'PT' => 'EU', 'PR' => 'NA', 'QA' => 'AS',
            'RE' => 'AF', 'RO' => 'EU', 'RU' => 'EU', 'RW' => 'AF', 'BL' => 'NA',
            'SH' => 'AF', 'KN' => 'NA', 'LC' => 'NA', 'MF' => 'NA', 'PM' => 'NA',
            'VC' => 'NA', 'WS' => 'OC', 'SM' => 'EU', 'ST' => 'AF', 'SA' => 'AS',
            'SN' => 'AF', 'RS' => 'EU', 'SC' => 'AF', 'SL' => 'AF', 'SG' => 'AS',
            'SX' => 'NA', 'SK' => 'EU', 'SI' => 'EU', 'SB' => 'OC', 'SO' => 'AF',
            'ZA' => 'AF', 'GS' => 'AN', 'SS' => 'AF', 'ES' => 'EU', 'LK' => 'AS',
            'SD' => 'AF', 'SR' => 'SA', 'SJ' => 'EU', 'SE' => 'EU', 'CH' => 'EU',
            'SY' => 'AS', 'TW' => 'AS', 'TJ' => 'AS', 'TZ' => 'AF', 'TH' => 'AS',
            'TL' => 'AS', 'TG' => 'AF', 'TK' => 'OC', 'TO' => 'OC', 'TT' => 'NA',
            'TN' => 'AF', 'TR' => 'AS', 'TM' => 'AS', 'TC' => 'NA', 'TV' => 'OC',
            'UG' => 'AF', 'UA' => 'EU', 'AE' => 'AS', 'GB' => 'EU', 'US' => 'NA',
            'UM' => 'OC', 'UY' => 'SA', 'UZ' => 'AS', 'VU' => 'OC', 'VE' => 'SA',
            'VN' => 'AS', 'VG' => 'NA', 'VI' => 'NA', 'WF' => 'OC', 'EH' => 'AF',
            'YE' => 'AS', 'ZM' => 'AF', 'ZW' => 'AF',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            'AF' => 'Africa',
            'AN' => 'Antarctica',
            'AS' => 'Asia',
            'EU' => 'Europe',
            'NA' => 'North America',
            'OC' => 'Oceania',
            'SA' => 'South America',
        ];
    }
}
