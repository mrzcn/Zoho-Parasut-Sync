<?php
// config/helpers/turkish.php
// Turkish language utilities

/**
 * Convert a numeric amount to Turkish words
 * Example: 480.00 USD => "DörtYüzSeksenAmerikanDolarıSıfırSent"
 */
function amountToTurkishWords(float $amount, string $currency = 'TRY'): string
{
    $ones = ['', 'Bir', 'İki', 'Üç', 'Dört', 'Beş', 'Altı', 'Yedi', 'Sekiz', 'Dokuz'];
    $tens = ['', 'On', 'Yirmi', 'Otuz', 'Kırk', 'Elli', 'Altmış', 'Yetmiş', 'Seksen', 'Doksan'];
    $thousands = ['', 'Bin', 'Milyon', 'Milyar', 'Trilyon'];

    $currencyNames = [
        'USD' => ['AmerikanDoları', 'Sent'],
        'EUR' => ['Euro', 'Sent'],
        'TRY' => ['TürkLirası', 'Kuruş'],
        'TRL' => ['TürkLirası', 'Kuruş'],
        'GBP' => ['İngilizSterlini', 'Peni'],
    ];

    $mainCurrency = $currencyNames[$currency][0] ?? $currency;
    $subCurrency = $currencyNames[$currency][1] ?? 'Kuruş';

    $amount = abs($amount);
    $intPart = floor($amount);
    $decPart = round(($amount - $intPart) * 100);

    $intWords = numberToTurkishWords($intPart, $ones, $tens, $thousands);
    if (empty($intWords))
        $intWords = 'Sıfır';

    $decWords = numberToTurkishWords($decPart, $ones, $tens, $thousands);
    if (empty($decWords))
        $decWords = 'Sıfır';

    return "Yazıyla Toplam Tutar: " . $intWords . $mainCurrency . $decWords . $subCurrency;
}

/**
 * Convert an integer number to Turkish words
 */
function numberToTurkishWords(int $number, array $ones, array $tens, array $thousands): string
{
    if ($number == 0)
        return '';
    if ($number < 0)
        return 'Eksi' . numberToTurkishWords(abs($number), $ones, $tens, $thousands);

    $result = '';
    $number = intval($number);

    if ($number >= 1000000000000) {
        $trillions = floor($number / 1000000000000);
        $result .= numberToTurkishWords($trillions, $ones, $tens, $thousands) . 'Trilyon';
        $number %= 1000000000000;
    }

    if ($number >= 1000000000) {
        $billions = floor($number / 1000000000);
        $result .= numberToTurkishWords($billions, $ones, $tens, $thousands) . 'Milyar';
        $number %= 1000000000;
    }

    if ($number >= 1000000) {
        $millions = floor($number / 1000000);
        $result .= numberToTurkishWords($millions, $ones, $tens, $thousands) . 'Milyon';
        $number %= 1000000;
    }

    if ($number >= 1000) {
        $thousandsVal = floor($number / 1000);
        if ($thousandsVal == 1) {
            $result .= 'Bin';
        } else {
            $result .= numberToTurkishWords($thousandsVal, $ones, $tens, $thousands) . 'Bin';
        }
        $number %= 1000;
    }

    if ($number >= 100) {
        $hundreds = floor($number / 100);
        if ($hundreds == 1) {
            $result .= 'Yüz';
        } else {
            $result .= $ones[$hundreds] . 'Yüz';
        }
        $number %= 100;
    }

    if ($number >= 10) {
        $result .= $tens[floor($number / 10)];
        $number %= 10;
    }

    if ($number > 0) {
        $result .= $ones[$number];
    }

    return $result;
}
