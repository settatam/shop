<?php

namespace Tests\Feature;

use App\Services\AamvaParserService;
use Tests\TestCase;

class AamvaParserTest extends TestCase
{
    private AamvaParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AamvaParserService;
    }

    public function test_parses_standard_aamva_v8_barcode(): void
    {
        $barcode = "@\n\nANSI 636014080002DL00410278ZC03200024DLDCS SMITH\nDACJOHN\nDADWILLIAM\nDBB01151990\nDBA01152030\nDAG123 MAIN ST\nDAISACRAMENTO\nDAJCA\nDAK942030000\nDAQD1234567\nDBC1\nDAYJR";

        $result = $this->parser->parse($barcode);

        $this->assertEquals('John', $result['first_name']);
        $this->assertEquals('Smith', $result['last_name']);
        $this->assertEquals('William', $result['middle_name']);
        $this->assertEquals('Jr', $result['suffix']);
        $this->assertEquals('123 MAIN ST', $result['address']);
        $this->assertEquals('Sacramento', $result['city']);
        $this->assertEquals('CA', $result['state']);
        $this->assertEquals('94203', $result['zip']);
        $this->assertEquals('D1234567', $result['id_number']);
        $this->assertEquals('1990-01-15', $result['date_of_birth']);
        $this->assertEquals('2030-01-15', $result['id_expiration_date']);
        $this->assertEquals('M', $result['sex']);
        $this->assertEquals('CA', $result['id_issuing_state']);
    }

    public function test_parses_older_v3_format_with_mmddyyyy_dates(): void
    {
        $barcode = "@\n\nANSI 636001030002DL00410278DLDCSJONESON\nDACJANE\nDADMARIE\nDBB12251985\nDBA06302028\nDAG456 OAK AVE\nDAIALBANY\nDAJNY\nDAK12205\nDAQA1234567\nDBC2";

        $result = $this->parser->parse($barcode);

        $this->assertEquals('Jane', $result['first_name']);
        $this->assertEquals('Joneson', $result['last_name']);
        $this->assertEquals('Marie', $result['middle_name']);
        $this->assertEquals('1985-12-25', $result['date_of_birth']);
        $this->assertEquals('2028-06-30', $result['id_expiration_date']);
        $this->assertEquals('F', $result['sex']);
    }

    public function test_handles_daa_full_name_format(): void
    {
        $barcode = "@\n\nANSI 636001030002DL00410278DLDAADOE,JOHN,MICHAEL\nDBB03041988\nDBA03042029\nDAG789 PINE ST\nDAICHICAGO\nDAJIL\nDAK606010000\nDAQJ0987654\nDBC1";

        $result = $this->parser->parse($barcode);

        $this->assertEquals('John', $result['first_name']);
        $this->assertEquals('Doe', $result['last_name']);
        $this->assertEquals('Michael', $result['middle_name']);
    }

    public function test_handles_zip_plus_four_truncation(): void
    {
        $barcode = "@\n\nANSI 636014080002DL00410278DLDCSTEST\nDACUSER\nDAK942031234\nDAG1 ST\nDAICITY\nDAJCA\nDAQX111\nDBB01011990";

        $result = $this->parser->parse($barcode);

        $this->assertEquals('94203', $result['zip']);
    }

    public function test_handles_missing_fields_gracefully(): void
    {
        $barcode = "@\n\nANSI 636014080002DL00410278DLDCSSMITH\nDACJOHN\nDAQD999";

        $result = $this->parser->parse($barcode);

        $this->assertEquals('John', $result['first_name']);
        $this->assertEquals('Smith', $result['last_name']);
        $this->assertNull($result['middle_name']);
        $this->assertNull($result['address']);
        $this->assertNull($result['city']);
        $this->assertNull($result['state']);
        $this->assertNull($result['zip']);
        $this->assertNull($result['date_of_birth']);
        $this->assertNull($result['id_expiration_date']);
        $this->assertNull($result['sex']);
        $this->assertEquals('D999', $result['id_number']);
    }

    public function test_rejects_non_aamva_data(): void
    {
        $this->assertFalse($this->parser->isAamvaBarcode('012345678901'));
        $this->assertFalse($this->parser->isAamvaBarcode('UPC-A barcode'));
        $this->assertFalse($this->parser->isAamvaBarcode(''));
        $this->assertFalse($this->parser->isAamvaBarcode('short'));
    }

    public function test_detects_aamva_barcode_with_ansi_header(): void
    {
        $barcode = "@\n\nANSI 636014080002DL00410278DLDCSSMITH\nDACJOHN\nDAQD1234567";

        $this->assertTrue($this->parser->isAamvaBarcode($barcode));
    }

    public function test_detects_aamva_barcode_by_field_codes(): void
    {
        $barcode = str_repeat('X', 60) . "DCSSMITH\nDACJOHN\nDAG123 ST\nDAICITY\nDAQD1234567\nDBB01011990";

        $this->assertTrue($this->parser->isAamvaBarcode($barcode));
    }

    public function test_parses_dct_first_name_variant(): void
    {
        $barcode = "@\n\nANSI 636001080002DL00410278DLDCSGARCIA\nDCTMARIA\nDBB05201992\nDAG100 ELM ST\nDAIMIAMI\nDAJFL\nDAK33101\nDAQG5551234\nDBC2";

        $result = $this->parser->parse($barcode);

        $this->assertEquals('Maria', $result['first_name']);
        $this->assertEquals('Garcia', $result['last_name']);
    }

    public function test_handles_yyyymmdd_date_format(): void
    {
        $barcode = "@\n\nANSI 636014080002DL00410278DLDCSLEE\nDACJAMES\nDBB19870315\nDBA20290315\nDAQX1234\nDAG1 ST\nDAICITY\nDAJCA";

        $result = $this->parser->parse($barcode);

        $this->assertEquals('1987-03-15', $result['date_of_birth']);
        $this->assertEquals('2029-03-15', $result['id_expiration_date']);
    }
}
