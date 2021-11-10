<?php

use org\bovigo\vfs\vfsStream;

/**
 * @internal
 * @covers \CsvImportAuditer
 */
class CsvImportAuditerTest extends \PHPUnit\Framework\TestCase
{
    protected $csvHeader;
    protected $csvData;
    protected $typeIdLookupTable;
    protected $ormClasses;
    protected $vfs;               // virtual filesystem
    protected $vdbcon;            // virtual database connection

    // Fixtures

    public function setUp(): void
    {
        $this->context = sfContext::getInstance();
        $this->vdbcon = $this->createMock(DebugPDO::class);
        $this->ormClasses = [
            'keymap' => \AccessToMemory\test\mock\QubitKeymap::class,
        ];

        $this->csvHeader = 'legacyId,name,type,location,culture,descriptionSlugs';

        /*
        $this->csvData = [
            // Note: leading and trailing whitespace in first row is intentional
            '"B10101 "," DJ001","Folder "," Aisle 25,Shelf D"," en","test-fonds-1 | test-collection"',
            '"","","Chemise","","fr",""',
            '"", "DJ002", "", "Voûte, étagère 0074", "", ""',
            '"", "DJ003", "Hollinger box", "Aisle 11, Shelf J", "en", ""',
        ];

        $this->typeIdLookupTableFixture = [
            'en' => [
                'hollinger box' => 1,
                'folder' => 2,
            ],
            'fr' => [
                'boîte hollinger' => 1,
                'chemise' => 2,
            ],
        ];

        // define virtual file system
        $directory = [
            'unix.csv' => $this->csvHeader."\n".implode("\n", $this->csvData),
            'windows.csv' => $this->csvHeader."\r\n".
              implode("\r\n", $this->csvData)."\r\n",
            'noheader.csv' => implode("\n", $this->csvData)."\n",
            'duplicate.csv' => $this->csvHeader."\n".implode(
                "\n",
                $this->csvData + $this->csvData
            ),
            'unreadable.csv' => $this->csvData[0],
            'error.log' => '',
        ];

        // setup and cache the virtual file system
        $this->vfs = vfsStream::setup('root', null, $directory);

        // Make 'root.csv' owned and readable only by root user
        $file = $this->vfs->getChild('root/unreadable.csv');
        $file->chmod('0400');
        $file->chown(vfsStream::OWNER_USER_1);
        */
    }

    public function getCsvRowAsAssocArray($row = 0)
    {
        return array_combine(
            explode(',', $this->csvHeader),
            str_getcsv($this->csvData[$row])
        );
    }

    // Data providers

    public function setOptionsProvider()
    {
        $defaultOptions = [
            'errorLog' => null,
            'progressFrequency' => 1,
            'idColumnName' => 'legacyId'
        ];

        $inputs = [
            null,
            [],
            [
                'progressFrequency' => 2
            ],
        ];

        $outputs = [
            $defaultOptions,
            $defaultOptions,
            [
                'errorLog' => null,
                'progressFrequency' => 2,
                'idColumnName' => 'legacyId'
            ],
        ];

        return [
            [$inputs[0], $outputs[0]],
            [$inputs[1], $outputs[1]],
            [$inputs[2], $outputs[2]],
        ];
    }

    public function processRowProvider()
    {
        $inputs = [
            // Leading and trailing whitespace is intentional
            [
                'source' => 'test_import',
                'row' => [
                  'legacyId' => '123',
                  'title' => 'Row with no issues',
                ],
            ],
            [
                'source' => 'test_import',
                'row' => [
                  'legacyId' => '124',
                  'title' => 'Row with new source ID',
                ],
            ],
            [
                'source' => 'bad_source',
                'row' => [
                  'legacyId' => '123',
                  'title' => 'Row with bad source name',
                ],
            ],
        ];

        $expectedResults = [
            [
              'missing'  => [],
            ],
            [
              'missing'  => [124 => 1],
            ],
            [
              'missing'  => [123 => 1],
            ],
        ];

        return [
            [$inputs[0], $expectedResults[0]],
            [$inputs[1], $expectedResults[1]],
            [$inputs[2], $expectedResults[2]],
        ];
    }

    // Tests

    public function testConstructorWithNoContextPassed()
    {
        $auditer = new CsvImportAuditer(null, $this->vdbcon);

        $this->assertSame(sfContext::class, get_class($auditer->context));
    }

    public function testConstructorWithNoDbconPassed()
    {
        $auditer = new CsvImportAuditer($this->context, null);

        $this->assertSame(DebugPDO::class, get_class($auditer->dbcon));
    }

    public function testMagicGetInvalidPropertyException()
    {
        $this->expectException(sfException::class);
        $auditer = new CsvImportAuditer($this->context, $this->vdbcon);
        $foo = $auditer->blah;
    }

    public function testMagicSetInvalidPropertyException()
    {
        $this->expectException(sfException::class);
        $auditer = new CsvImportAuditer($this->context, $this->vdbcon);
        $auditer->foo = 'blah';
    }

    public function testSetFilenameFileNotFoundException()
    {
        $this->expectException(sfException::class);
        $auditer = new CsvImportAuditer($this->context, $this->vdbcon);
        $auditer->setFilename('bad_name.csv');
    }

    /*
    public function testSetFilenameFileUnreadableException()
    {
        $this->expectException(sfException::class);
        $auditer = new CsvImportAuditer($this->context, $this->vdbcon);
        $auditer->setFilename($this->vfs->url().'/unreadable.csv');
    }

    public function testSetFilenameSuccess()
    {
        // Explicit method call
        $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
        $importer->setFilename($this->vfs->url().'/unix.csv');
        $this->assertSame(
            $this->vfs->url().'/unix.csv',
            $importer->getFilename()
        );

        // Magic __set
        $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
        $importer->setFilename($this->vfs->url().'/windows.csv');
        $this->assertSame(
            $this->vfs->url().'/windows.csv',
            $importer->getFilename()
        );
    }
    */

    /**
     * @dataProvider setOptionsProvider
     *
     * @param mixed $options
     * @param mixed $expected
     */
    public function testSetOptions($options, $expected)
    {
        $importer = new CsvImportAuditer($this->context, $this->vdbcon);
        $importer->setOptions($options);
        $this->assertSame($expected, $importer->getOptions());
    }

    public function testSetOptionsThrowsTypeError()
    {
        $this->expectException(TypeError::class);

        $auditer = new CsvImportAuditer($this->context, $this->vdbcon);
        $auditer->setOptions(1);
        $auditer->setOptions(new stdClass());
    }

    public function testSetAndGetIdColumnName()
    {
        $auditer = new CsvImportAuditer($this->context, $this->vdbcon);

        $auditer->setOption('idColumnName', 'some_column');
        $this->assertSame('some_column', $auditer->getOption('idColumnName'));
    }

    public function testSetOptionFromOptions()
    {
        $auditer = new CsvImportAuditer($this->context, $this->vdbcon);
        $auditer->setOptions([
            'progressFrequency' => 5,
            'idColumnName' => 'some_column',
        ]);

        $this->assertSame(5, $auditer->getOption('progressFrequency'));
        $this->assertSame('some_column', $auditer->getOption('idColumnName'));
    }

    /*
    public function testSourceNameDefaultsToFilename()
    {
        $filename = $this->vfs->url().'/unix.csv';
        $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
        $importer->setFilename($filename);

        $this->assertSame(basename($filename), $importer->getOption('sourceName'));
    }
    */

    public function testDoAuditNoFilenameException()
    {
        $this->expectException(sfException::class);

        $auditer = new CsvImportAuditer($this->context, $this->vdbcon);
        $auditer->doAudit();
    }


    /**
     * @dataProvider processRowProvider
     *
     * @param mixed $data
     * @param mixed $expectedResult
     */
    public function testProcessRow($data, $expectedResult)
    {
        $auditer = new CsvImportAuditer(
            $this->context,
            $this->vdbcon,
        );
        $auditer->setOrmClasses($this->ormClasses);
        $auditer->setSourceName($data['source']);

        $result = $auditer->processRow($data['row']);

        $this->assertSame($auditer->getMissingIds(), $expectedResult['missing']);
    }

    public function testProcessRowThrowsExceptionIfBadLegacyIdColumnn()
    {
        $this->expectException(UnexpectedValueException::class);

        $row = [
            'id' => '123',
        ];

        $auditer = new CsvImportAuditer(
            $this->context,
            $this->vdbcon,
        );
        $auditer->setOrmClasses($this->ormClasses);
        $auditer->setSourceName('test_import');

        $result = $auditer->processRow($row);
    }

    public function testProcessRowCustomIdColumnn()
    {
        $row = [
            'id' => '123',
        ];

        $auditer = new CsvImportAuditer(
            $this->context,
            $this->vdbcon,
            ['idColumnName' => 'id']
        );
        $auditer->setOrmClasses($this->ormClasses);
        $auditer->setSourceName('test_import');

        $result = $auditer->processRow($row);

        $this->assertSame($auditer->getMissingIds(), []);
    }

    public function testProcessRowThrowsExceptionIfNoIdColumn()
    {
        $this->expectException(UnexpectedValueException::class);

        $auditer = new CsvImportAuditer($this->context, $this->vdbcon);

        $auditer->processRow([]);
    }

    public function testProgressUpdateFreqOne()
    {
        $auditer = new CsvImportAuditer($this->context, $this->vdbcon);
        $auditer->setOption('progressFrequency', 1);
        $data = $auditer->processRow(['legacyId' => '123']);

        $this->assertSame(
            'Row [0/0] audited',
            $auditer->progressUpdate(0, $data)
        );
    }

    public function testProgressUpdateFreqTwo()
    {
        $auditer = new CsvImportAuditer($this->context, $this->vdbcon);
        $auditer->setOption('progressFrequency', 2);

        $this->assertSame(
            'Audited 2 of 0 rows...',
            $auditer->progressUpdate(2, [])
        );
    }

    //
    // Protected method tests
    //

    /*
    public function testGetTypeIdLookupTableExceptionGettingTerms()
    {
        $stub = $this->createStub(QubitTaxonomy::class);
        $stub->method('getTermNameToIdLookupTable')
            ->willReturn(null)
        ;

        $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
        $importer->setPhysicalObjectTypeTaxonomy($stub);

        $this->expectException(sfException::class);
        $importer->typeIdLookupTable;
    }
    */
}
