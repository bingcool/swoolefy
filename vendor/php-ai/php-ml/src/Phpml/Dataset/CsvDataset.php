<?php

declare(strict_types=1);

namespace Phpml\Dataset;

use Phpml\Exception\FileException;

class CsvDataset extends ArrayDataset
{
    /**
     * @var array
     */
    protected $columnNames;

    /**
     * @param string $filepath
     * @param int    $features
     * @param bool   $headingRow
     *
     * @throws FileException
     */
    public function __construct(string $filepath, int $features, bool $headingRow = true)
    {
        if (!file_exists($filepath)) {
            throw FileException::missingFile(basename($filepath));
        }

        if (false === $handle = fopen($filepath, 'rb')) {
            throw FileException::cantOpenFile(basename($filepath));
        }

        if ($headingRow) {
            $data = fgetcsv($handle, 1000, ',');
            $this->columnNames = array_slice($data, 0, $features);
        } else {
            $this->columnNames = range(0, $features - 1);
        }

        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            $this->samples[] = array_slice($data, 0, $features);
            $this->targets[] = $data[$features];
        }
        fclose($handle);
    }

    /**
     * @return array
     */
    public function getColumnNames()
    {
        return $this->columnNames;
    }
}
