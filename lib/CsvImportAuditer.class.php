<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__.'/../vendor/composer/autoload.php';

/**
 * Auditer for CSV imports.
 *
 * @author  Mike Cantelon <mike@artefactual.com>
 */
class CsvImportAuditer
{
    protected $context;
    protected $data;
    protected $dbcon;
    protected $offset = 0;
    protected $errorLogHandle;
    protected $sourceName;
    protected $filename;
    protected $ormClasses;
    protected $reader;
    protected $rowsAudited = 0;
    protected $rowsTotal = 0;
    protected $missingIds = [];

    // Default options
    protected $options = [
        'errorLog' => null,
        'progressFrequency' => 1,
        'idColumnName' => 'legacyId'
    ];

    //
    // Public methods
    //

    public function __construct(
        sfContext $context = null,
        $dbcon = null,
        $options = []
    ) {
        if (null === $context) {
            $context = new sfContext(ProjectConfiguration::getActive());
        }

        $this->setOrmClasses([
            'informationObject' => QubitInformationObject::class,
            'keymap' => QubitKeymap::class,
        ]);

        $this->context = $context;
        $this->dbcon = $dbcon;

        $this->setOptions(array_merge($this->options, $options));
    }

    public function __get($name)
    {
        switch ($name) {
            case 'context':
                return $this->{$name};

                break;

            case 'dbcon':
                return $this->getDbConnection();

                break;

            default:
                throw new sfException("Unknown or inaccessible property \"{$name}\"");
        }
    }

    public function __set($name, $value)
    {
        switch ($name) {
            case 'dbcon':
                $this->{$name} = $value;

                break;

            default:
                throw new sfException("Couldn't set unknown property \"{$name}\"");
        }
    }

    public function setOrmClasses(array $classes)
    {
        $this->ormClasses = $classes;
    }

    public function setSourceName($sourceName)
    {
        $this->sourceName = $sourceName;
    }

    public function setFilename($filename)
    {
        $this->filename = $this->validateFilename($filename);
    }

    public function getFilename()
    {
        return $this->filename;
    }

    public function validateFilename($filename)
    {
        if (empty($filename)) {
            throw new sfException('Please specify a filename for import');
        }

        if (!file_exists($filename)) {
            throw new sfException("Can not find file {$filename}");
        }

        if (!is_readable($filename)) {
            throw new sfException("Can not read {$filename}");
        }

        return $filename;
    }

    public function setOptions(array $options = null)
    {
        if (empty($options)) {
            return;
        }

        foreach ($options as $name => $val) {
            $this->setOption($name, $val);
        }
    }

    public function getOptions()
    {
        return $this->options;
    }

    public function setOption(string $name, $value)
    {
        switch ($name) {
            default:
                $this->options[$name] = $value;
        }
    }

    public function getOption(string $name)
    {
        if (isset($this->options[$name])) {
            return $this->options[$name];
        }

        return null;
    }

    public function countRowsAudited()
    {
        return $this->rowsAudited;
    }

    public function countRowsTotal()
    {
        return $this->rowsTotal;
    }

    public function doAudit($filename = null)
    {
        if (null !== $filename) {
            $this->setFilename($filename);
        }

        $records = $this->loadCsvData($this->filename);

        foreach ($records as $record) {
            ++$this->offset;

            try {
                $this->processRow($record);
            } catch (UnexpectedValueException $e) {
                $this->logError(sprintf(
                    'Warning! skipped row [%u/%u]: %s',
                    $this->offset,
                    $this->rowsTotal,
                    $e->getMessage()
                ));

                continue;
            }

            ++$this->rowsAudited;
            $this->log($this->progressUpdate($this->rowsAudited, $data));
        }

        if (!empty($this->missingIds)) {
            $this->log('');
            $this->log('Source IDs not found in keymap data:');

            foreach ($this->missingIds as $sourceId => $rowNumber)
            {
                $this->log(sprintf('* %d (row %d)', $sourceId, $rowNumber));
            }
        }
    }

    public function loadCsvData($filename)
    {
        $this->validateFileName($filename);

        $this->reader = $this->readCsvFile($filename);
        $stmt = new \League\Csv\Statement();
        $records = $this->getRecords($stmt);

        $this->rowsTotal = count($records);

        return $records;
    }

    public function processRow($data)
    {
        // Determine column name to check
        $idColumnName = $this->getOption('idColumnName');

        // Throw error if not ID value is found
        if (empty($data[$idColumnName])) {
            throw new UnexpectedValueException(sprintf('ID column %s not found', $idColumnName));
        }

        // Attempt to fetch keymap entry corresponding to source ID
        $sourceId = $data[$idColumnName];

        if (false === $this->ormClasses['keymap']::getTargetId($this->sourceName, $sourceId))
        {
            $this->missingIds[$sourceId] = $this->rowsAudited + 1;
        }
    }

    public function savePhysicalobjects($data)
    {
        $saveTimer = $this->startTimer('save');

        // Setting the propel::defaultCulture is necessary for non-English rows
        // to prevent creating an empty i18n row with culture 'en'
        sfPropel::setDefaultCulture($data['culture']);

        $timer = $this->startTimer('matchExisting');
        $matches = $this->matchExistingRecords($data);
        $timer->add();

        if (null === $matches) {
            $this->insertPhysicalObject($data);

            return;
        }

        foreach ($matches as $item) {
            $timer = $this->startTimer('updateExisting');
            $this->updatePhysicalObject($item, $data);
            $timer->add();
        }

        $saveTimer->add();
    }

    public function getMissingIds()
    {
        return $this->missingIds;
    }

    //
    // Protected methods
    //

    protected function updateInfoObjRelations($physobj, $informationObjectIds)
    {
        $timer->startTimer('updateInfObjRelations');

        // Update the search index of related information objects
        $physobj->indexOnSave = $this->getOption('updateSearchIndex');

        if (isset($updates['informationObjectIds'])) {
            $physobj->updateInfobjRelations($informationObjectIds);
        }

        $timer->add();
    }

    protected function log($msg)
    {
        echo $msg.PHP_EOL;
    }

    protected function logError($msg)
    {
        // Write to error log (but not STDERR)
        if (STDERR != $this->getErrorLogHandle()) {
            fwrite($this->getErrorLogHandle(), $msg.PHP_EOL);
        }
    }

    protected function getDbConnection()
    {
        if (null === $this->dbcon) {
            $this->dbcon = Propel::getConnection();
        }

        return $this->dbcon;
    }

    protected function getErrorLogHandle()
    {
        if (null === $filename = $this->getOption('errorLog')) {
            return STDERR;
        }

        if (!isset($this->errorLogHandle)) {
            $this->errorLogHandle = fopen($filename, 'w');
        }

        return $this->errorLogHandle;
    }

    protected function readCsvFile($filename)
    {
        $reader = \League\Csv\Reader::createFromPath($filename, 'r');

        if (!isset($this->options['header'])) {
            // Use first row of CSV file as header
            $reader->setHeaderOffset(0);
        }

        return $reader;
    }

    protected function getRecords($stmt)
    {
        if (isset($this->options['header'])) {
            $records = $stmt->process($this->reader, $this->options['header']);
        } else {
            $records = $stmt->process($this->reader);
        }

        return $records;
    }

    public function progressUpdate($count, $data)
    {
        $freq = $this->getOption('progressFrequency');

        if (1 == $freq) {
            $msg = 'Row [%u/%u] audited';

            $output = sprintf(
                $msg,
                $count,
                $this->rowsTotal
            );
        } elseif ($freq > 1 && 0 == $count % $freq) {
            $output = sprintf(
                'Audited %u of %u rows...',
                $count,
                $this->rowsTotal
            );
        }

        return $output;
    }
}
