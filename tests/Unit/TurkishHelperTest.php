<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for Turkish language helper functions
 */
class TurkishHelperTest extends TestCase
{
    /**
     * Test basic TRY amount conversion
     */
    public function testAmountToTurkishWordsTRY(): void
    {
        $result = amountToTurkishWords(480.00, 'TRY');
        $this->assertStringContainsString('Yüz', $result);
        $this->assertStringContainsString('Seksen', $result);
        $this->assertStringContainsString('TürkLirası', $result);
    }

    /**
     * Test USD amount conversion
     */
    public function testAmountToTurkishWordsUSD(): void
    {
        $result = amountToTurkishWords(100.50, 'USD');
        $this->assertStringContainsString('Yüz', $result);
        $this->assertStringContainsString('AmerikanDoları', $result);
        $this->assertStringContainsString('Elli', $result);
        $this->assertStringContainsString('Sent', $result);
    }

    /**
     * Test zero amount
     */
    public function testAmountToTurkishWordsZero(): void
    {
        $result = amountToTurkishWords(0.00, 'TRY');
        $this->assertStringContainsString('Sıfır', $result);
        $this->assertStringContainsString('TürkLirası', $result);
    }

    /**
     * Test EUR currency
     */
    public function testAmountToTurkishWordsEUR(): void
    {
        $result = amountToTurkishWords(1250.75, 'EUR');
        $this->assertStringContainsString('Bin', $result);
        $this->assertStringContainsString('Euro', $result);
    }

    /**
     * Test large numbers
     */
    public function testAmountToTurkishWordsLargeNumber(): void
    {
        $result = amountToTurkishWords(1000000.00, 'TRY');
        $this->assertStringContainsString('Milyon', $result);
    }

    /**
     * Test "Bin" (1000) uses no prefix "Bir"
     * In Turkish, 1000 is "Bin" not "BirBin"
     */
    public function testBinNotBirBin(): void
    {
        $result = amountToTurkishWords(1000.00, 'TRY');
        $this->assertStringContainsString('Bin', $result);
        $this->assertStringNotContainsString('BirBin', $result);
    }

    /**
     * Test "Yüz" (100) uses no prefix "Bir"
     * In Turkish, 100 is "Yüz" not "BirYüz"
     */
    public function testYuzNotBirYuz(): void
    {
        $result = amountToTurkishWords(100.00, 'TRY');
        $this->assertStringContainsString('Yüz', $result);
        $this->assertStringNotContainsString('BirYüz', $result);
    }

    /**
     * Test numberToTurkishWords helper directly
     */
    public function testNumberToTurkishWordsBasic(): void
    {
        $ones = ['', 'Bir', 'İki', 'Üç', 'Dört', 'Beş', 'Altı', 'Yedi', 'Sekiz', 'Dokuz'];
        $tens = ['', 'On', 'Yirmi', 'Otuz', 'Kırk', 'Elli', 'Altmış', 'Yetmiş', 'Seksen', 'Doksan'];
        $thousands = ['', 'Bin', 'Milyon', 'Milyar', 'Trilyon'];

        $this->assertEquals('', numberToTurkishWords(0, $ones, $tens, $thousands));
        $this->assertEquals('Bir', numberToTurkishWords(1, $ones, $tens, $thousands));
        $this->assertEquals('On', numberToTurkishWords(10, $ones, $tens, $thousands));
        $this->assertEquals('YirmiÜç', numberToTurkishWords(23, $ones, $tens, $thousands));
        $this->assertEquals('Yüz', numberToTurkishWords(100, $ones, $tens, $thousands));
        $this->assertEquals('Bin', numberToTurkishWords(1000, $ones, $tens, $thousands));
    }
}
