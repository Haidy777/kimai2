<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Invoice;

use App\Entity\InvoiceDocument;
use App\Model\InvoiceModel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\Response;

class CsvRenderer extends AbstractRenderer implements RendererInterface
{
    /**
     * @return string[]
     */
    protected function getFileExtensions()
    {
        return ['.csv'];
    }

    /**
     * @return string
     */
    protected function getContentType()
    {
        return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    /**
     * Render the given InvoiceDocument with the data from the InvoiceModel.
     *
     * @param InvoiceDocument $document
     * @param InvoiceModel $model
     * @return Response
     */
    public function render(InvoiceDocument $document, InvoiceModel $model): Response
    {
        $spreadsheet = IOFactory::load($document->getFilename());
        $worksheet = $spreadsheet->getActiveSheet();
        $entries = $model->getCalculator()->getEntries();
        $replacer = $this->modelToReplacer($model);
        $this->addTemplateRows($worksheet, count($entries));

        $entryRow = 0;
        foreach($worksheet->getRowIterator() as $row) {
            $timesheet = $entries[$entryRow];
            $sheetValues = false;
            foreach($row->getCellIterator() as $cell) {
                $value = $cell->getValue();
                if (stripos($value, '${entry.') !== false) {
                    if ($sheetValues === false) {
                        $sheetValues = $this->timesheetToArray($timesheet);
                    }
                    $searcher = str_replace('${', '', $value);
                    $searcher = str_replace('}', '', $searcher);
                    if (isset($sheetValues[$searcher])) {
                        $cell->setValue($sheetValues[$searcher]);
                    }
                } elseif (stripos($value, '${') !== false) {
                    $searcher = str_replace('${', '', $value);
                    $searcher = str_replace('}', '', $searcher);
                    if (isset($replacer[$searcher])) {
                        $cell->setValue($replacer[$searcher]);
                    }
                }
            }

            if ($sheetValues !== false) {
                $entryRow++;
            }
        }

        $filename = tempnam(sys_get_temp_dir(), 'kimai-csv');
        $writer = IOFactory::createWriter($spreadsheet, 'Csv');
        $writer->save($filename);

        return $this->getFileResponse($filename, basename($document->getFilename()));
    }

    /**
     * @param Worksheet $worksheet
     * @param int $timesheets
     */
    protected function addTemplateRows(Worksheet $worksheet, int $timesheets)
    {
        $startRow = null;
        foreach($worksheet->getRowIterator() as $row) {
            foreach($row->getCellIterator() as $cell) {
                $value = $cell->getValue();
                if (stripos($value, '${entry.') !== false) {
                    $startRow = $row->getRowIndex();
                    $worksheet->insertNewRowBefore($row->getRowIndex(), $timesheets-1);
                    break 2;
                }
            }
        }

        if ($startRow === null) {
            return;
        }

        // fill up all new rows with template values
        $templateRow = $timesheets + $startRow;
        $iterator = $worksheet->getRowIterator($templateRow - 1, $templateRow);
        $templateColumns = [];
        foreach($iterator->current()->getCellIterator() as $cell) {
            $templateColumns[$cell->getColumn()] = $cell->getValue();
        }

        $iterator = $worksheet->getRowIterator($startRow, $templateRow - 2);
        foreach($iterator as $row) {
            foreach($row->getCellIterator() as $cell) {
                $cell->setValue($templateColumns[$cell->getColumn()]);
            }
        }
    }
}
