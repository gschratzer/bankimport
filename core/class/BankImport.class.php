<?php
/* Copyright (C) 2024 Tilo Thiele <tilo.thiele@hamburg.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

/**
 * BankImport class
 */
class BankImport extends CommonObject
{
    /**
     * @var DoliDB Database handler.
     */
    public $db;

    /**
     * @var string Error code (or message)
     */
    public $error = '';

    /**
     * @var string[] Error codes (or messages)
     */
    public $errors = array();

    /**
     * @var int Bank account ID
     */
    public $accountid;

    /**
     * @var string File encoding
     */
    public $encoding = 'UTF-8';

    /**
     * @var string Import format
     */
    public $importformat = 'csv';

    /**
     * @var array CSV field mapping
     */
    public $fieldMapping = array(
        'account' => 0,
        'booking_date' => 1,
        'value_date' => 2,
        'booking_text' => 3,
        'payment_purpose' => 4,
        'creditor_id' => 5,
        'mandate_reference' => 6,
        'customer_reference' => 7,
        'collector_reference' => 8,
        'counterparty_name' => 11,
        'counterparty_iban' => 12,
        'counterparty_bic' => 13,
        'amount' => 14,
        'currency' => 15,
        'info' => 16
    );

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Set account ID
     *
     * @param int $accountid Bank account ID
     * @return void
     */
    public function setAccountId($accountid)
    {
        $this->accountid = (int) $accountid;
    }

    /**
     * Set encoding
     *
     * @param string $encoding File encoding
     * @return void
     */
    public function setEncoding($encoding)
    {
        $this->encoding = $encoding;
    }

    /**
     * Set import format
     *
     * @param string $importformat Import format
     * @return void
     */
    public function setImportFormat($importformat)
    {
        $importformat = strtolower(trim((string) $importformat));
        $this->importformat = ($importformat === 'victoriabank_xml') ? 'victoriabank_xml' : 'csv';
    }

    /**
     * Validate uploaded file
     *
     * @param array $file $_FILES array element
     * @return bool True if valid, false otherwise
     */
    public function validateFile($file)
    {
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            $this->error = 'No file uploaded';
            return false;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $this->error = 'Invalid file upload';
            return false;
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            $this->error = 'File too large (max 10MB)';
            return false;
        }

        $name = isset($file['name']) ? (string) $file['name'] : '';
        $type = isset($file['type']) ? (string) $file['type'] : '';

        if ($this->importformat === 'victoriabank_xml') {
            $allowedTypes = array('text/xml', 'application/xml');
            if (!in_array($type, $allowedTypes) && !preg_match('/\.xml$/i', $name)) {
                $this->error = 'Invalid file type (XML required)';
                return false;
            }
            return true;
        }

        $allowedTypes = array('text/csv', 'text/plain', 'application/csv');
        if (!in_array($type, $allowedTypes) && !preg_match('/\.csv$/i', $name)) {
            $this->error = 'Invalid file type (CSV required)';
            return false;
        }

        return true;
    }

    /**
     * Process input file (CSV or XML transformed to CSV)
     *
     * @param string $filename File path
     * @return array Array with success count and errors
     */
    public function processFile($filename)
    {
        $result = array(
            'success' => 0,
            'errors' => array(),
            'skipped' => 0
        );

        if (empty($this->accountid) || $this->accountid <= 0) {
            $this->error = 'No valid bank account selected';
            $result['errors'][] = 'No valid bank account selected';
            return $result;
        }

        $csvFilename = $filename;
        $cleanupCsv = false;

        if ($this->importformat === 'victoriabank_xml') {
            $csvFilename = $this->vicxml2csv($filename);
            if ($csvFilename === false) {
                $result['errors'][] = $this->error;
                return $result;
            }
            $cleanupCsv = true;
        }

        $handle = fopen($csvFilename, 'r');
        if (!$handle) {
            if ($cleanupCsv && is_file($csvFilename)) {
                @unlink($csvFilename);
            }
            $this->error = 'Could not open file';
            $result['errors'][] = 'Could not open file';
            return $result;
        }

        $row = 0;
        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $row++;
            if ($row == 1) {
                continue;
            }

            $data = $this->convertEncoding($data);

            if (!$this->validateRow($data, $row)) {
                $result['errors'][] = 'Row '.$row.': '.$this->error;
                continue;
            }

            $importResult = $this->processRow($data, $row);
            if ($importResult === true) {
                $result['success']++;
            } elseif ($importResult === 'skipped') {
                $result['skipped']++;
            } else {
                $result['errors'][] = 'Row '.$row.': '.$importResult;
            }
        }

        fclose($handle);
        if ($cleanupCsv && is_file($csvFilename)) {
            @unlink($csvFilename);
        }

        return $result;
    }

    /**
     * Convert encoding of data array
     *
     * @param array $data Data array
     * @return array Converted data array
     */
    private function convertEncoding($data)
    {
        if ($this->encoding && strtoupper($this->encoding) !== 'UTF-8') {
            foreach ($data as &$field) {
                $field = iconv($this->encoding, 'UTF-8//TRANSLIT', $field);
            }
        }
        return $data;
    }

    /**
     * Validate CSV row data
     *
     * @param array $data Row data
     * @param int $row Row number
     * @return bool True if valid, false otherwise
     */
    private function validateRow($data, $row)
    {
        if (count($data) < 17) {
            $this->error = 'Insufficient columns in CSV';
            return false;
        }

        if (empty($data[$this->fieldMapping['booking_date']])) {
            $this->error = 'Missing booking date';
            return false;
        }

        if ($data[$this->fieldMapping['amount']] === '' || $data[$this->fieldMapping['amount']] === null) {
            $this->error = 'Missing amount';
            return false;
        }

        return true;
    }

    /**
     * Process single CSV row
     *
     * @param array $data Row data
     * @param int $row Row number
     * @return bool|string True on success, 'skipped' if already imported, error message on failure
     */
    private function processRow($data, $row)
    {
        global $user;

        $dateo = $this->parseDate($data[$this->fieldMapping['booking_date']]);
        $datev = $this->parseDate($data[$this->fieldMapping['value_date']]);
        $label = $this->limitString($data[$this->fieldMapping['payment_purpose']]);
        $amount = price2num($data[$this->fieldMapping['amount']]);
        $oper = 'VIR';
        $ref = trim($data[$this->fieldMapping['mandate_reference']]);
        $categorie = null;
        $transaction_id = trim($data[$this->fieldMapping['customer_reference']]);
        $bank_other = $data[$this->fieldMapping['counterparty_bic']];
        $iban_other = $data[$this->fieldMapping['counterparty_iban']];
        $owner_other = $data[$this->fieldMapping['counterparty_name']];

        $import_key = $this->generateImportKey($transaction_id, $iban_other, $owner_other, $amount, $label, $ref);

        if ($this->isAlreadyImported($import_key)) {
            return 'skipped';
        }

        $note = $this->buildNote($data);

        $this->db->begin();

        try {
            $account = new Account($this->db);
            $account->fetch($this->accountid);

            $bankline_id = $account->addline(
                $dateo,
                $oper,
                $label,
                $amount,
                $ref,
                $categorie,
                $user,
                $owner_other,
                $bank_other,
                $iban_other,
                $datev,
                null,
                null,
                $note
            );

            if ($bankline_id > 0) {
                $this->updateImportKey($bankline_id, $import_key);
                $this->db->commit();
                return true;
            }

            $this->db->rollback();
            return $account->error;
        } catch (Exception $e) {
            $this->db->rollback();
            return $e->getMessage();
        }
    }

    /**
     * Convert Victoriabank XML export to temporary bankimport CSV file.
     *
     * @param string $filename XML filename
     * @return string|false Temp CSV filename or false on error
     */
    public function vicxml2csv($filename)
    {
        $xmlData = @file_get_contents($filename);
        if ($xmlData === false || trim($xmlData) === '') {
            $this->error = 'Could not read Victoriabank XML file';
            return false;
        }

        $xmlData = preg_replace('/^ï»¿/', '', $xmlData);
        $requestAccount = $this->extractXmlTagValue($xmlData, 'Account');
        $documents = $this->parseVictoriaXmlDocuments($xmlData);

        $myAccount = $this->normalizeAccount($requestAccount);
        if ($myAccount === '') {
            $this->error = 'Victoriabank XML does not contain a valid own account';
            return false;
        }

        if (empty($documents)) {
            $this->error = 'Victoriabank XML structure not recognized';
            return false;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'bankimport_vicxml_');
        if ($tmpFile === false) {
            $this->error = 'Could not create temporary CSV file';
            return false;
        }

        $handle = fopen($tmpFile, 'w');
        if (!$handle) {
            @unlink($tmpFile);
            $this->error = 'Could not create temporary CSV file';
            return false;
        }

        fputcsv($handle, array(
            'Auftragskonto', 'Buchungstag', 'Valutadatum', 'Buchungstext', 'Verwendungszweck',
            'Glaeubiger ID', 'Mandatsreferenz', 'Kundenreferenz', 'Sammlerreferenz',
            'Lastschrift Ursprungsbetrag', 'Auslagenersatz Ruecklastschrift',
            'Beguenstigter/Zahlungspflichtiger', 'Kontonummer/IBAN', 'BIC (SWIFT-Code)',
            'Betrag', 'Waehrung', 'Info'
        ), ';');

        $writtenRows = 0;
        foreach ($documents as $doc) {
            $row = $this->buildCsvRowFromVictoriaDocument($doc, $myAccount);
            if ($row === null) {
                continue;
            }
            fputcsv($handle, $row, ';');
            $writtenRows++;
        }

        fclose($handle);

        if ($writtenRows === 0) {
            @unlink($tmpFile);
            $this->error = 'No importable transactions found in Victoriabank XML';
            return false;
        }

        return $tmpFile;
    }

    /**
     * Build one CSV row from one Victoriabank XML document.
     *
     * @param SimpleXMLElement $doc XML document node
     * @param string $myAccount Own account
     * @return array|null
     */
    private function buildCsvRowFromVictoriaDocument($doc, $myAccount)
    {
        $accountDebit = $this->normalizeAccount($this->getVictoriaValue($doc, 'AccountDebit'));
        $accountCredit = $this->normalizeAccount($this->getVictoriaValue($doc, 'AccountCredit'));
        $isOutgoing = ($accountDebit !== '' && $accountDebit === $myAccount);
        $isIncoming = ($accountCredit !== '' && $accountCredit === $myAccount);

        if (!$isOutgoing && !$isIncoming) {
            return null;
        }

        $currency = $this->extractCurrency($this->getVictoriaValue($doc, 'AccountDebit'), $this->getVictoriaValue($doc, 'AccountCredit'), $myAccount);
        if ($currency === '') {
            $currency = 'MDL';
        }

        if ($isOutgoing) {
            $counterpartyName = $this->cleanupCounterpartyName($this->getVictoriaValue($doc, 'ClientCreditName'));
            $counterpartyFiscalCode = trim($this->getVictoriaValue($doc, 'ClientCreditFiscalCode'));
            $counterpartyAccount = $accountCredit;
            $counterpartyBic = trim($this->getVictoriaValue($doc, 'CreditBankBIC'));
            $amount = -1 * abs((float) $this->getVictoriaValue($doc, 'AmountDebit'));
        } else {
            $counterpartyName = $this->cleanupCounterpartyName($this->getVictoriaValue($doc, 'ClientDebitName'));
            $counterpartyFiscalCode = trim($this->getVictoriaValue($doc, 'ClientDebitFiscalCode'));
            $counterpartyAccount = $accountDebit;
            $counterpartyBic = trim($this->getVictoriaValue($doc, 'DebitBankBIC'));
            $amount = abs((float) $this->getVictoriaValue($doc, 'AmountCredit'));
        }

        if ($amount == 0.0) {
            $amount = (float) $this->getVictoriaValue($doc, 'Amount');
            if ($isOutgoing) {
                $amount = -1 * abs($amount);
            } else {
                $amount = abs($amount);
            }
        }

        $bookingDate = $this->formatDateForCsv($this->getVictoriaValue($doc, 'OrderDate'));
        $valueDate = $this->formatDateForCsv($this->getVictoriaValue($doc, 'ExecuteDate'));
        $bookingText = trim($this->getVictoriaValue($doc, 'DocumentTypeID'));
        if ($bookingText === '') {
            $bookingText = trim($this->getVictoriaValue($doc, 'MovementTypeID'));
        }

        $infoParts = array();
        if (trim($this->getVictoriaValue($doc, 'MovementTypeID')) !== '') {
            $infoParts[] = 'MovementTypeID='.trim($this->getVictoriaValue($doc, 'MovementTypeID'));
        }
        if (trim($this->getVictoriaValue($doc, 'DocumentTypeID')) !== '') {
            $infoParts[] = 'DocumentTypeID='.trim($this->getVictoriaValue($doc, 'DocumentTypeID'));
        }
        if (trim($this->getVictoriaValue($doc, 'Nr')) !== '') {
            $infoParts[] = 'Nr='.trim($this->getVictoriaValue($doc, 'Nr'));
        }

        return array(
            $myAccount,
            $bookingDate,
            $valueDate,
            $bookingText,
            trim($this->getVictoriaValue($doc, 'Destination')),
            $counterpartyFiscalCode,
            trim($this->getVictoriaValue($doc, 'DocumentID')),
            trim($this->getVictoriaValue($doc, 'MovementID')),
            trim($this->getVictoriaValue($doc, 'Nr')),
            '',
            '',
            $counterpartyName,
            $counterpartyAccount,
            $counterpartyBic,
            number_format($amount, 2, '.', ''),
            $currency,
            implode(' ', $infoParts)
        );
    }


    /**
     * Parse Victoriabank XML documents without requiring XML extensions.
     *
     * @param string $xmlData Raw XML data
     * @return array<int,array<string,string>>
     */
    private function parseVictoriaXmlDocuments($xmlData)
    {
        $documents = array();
        if (!preg_match_all('/<Document>(.*?)<\/Document>/si', $xmlData, $matches)) {
            return $documents;
        }

        foreach ($matches[1] as $block) {
            $documents[] = array(
                'Nr' => $this->extractXmlTagValue($block, 'Nr'),
                'OrderDate' => $this->extractXmlTagValue($block, 'OrderDate'),
                'DocumentID' => $this->extractXmlTagValue($block, 'DocumentID'),
                'DocumentTypeID' => $this->extractXmlTagValue($block, 'DocumentTypeID'),
                'ExecuteDate' => $this->extractXmlTagValue($block, 'ExecuteDate'),
                'ClientDebitName' => $this->extractXmlTagValue($block, 'ClientDebitName'),
                'ClientDebitFiscalCode' => $this->extractXmlTagValue($block, 'ClientDebitFiscalCode'),
                'AccountDebit' => $this->extractXmlTagValue($block, 'AccountDebit'),
                'DebitBankBIC' => $this->extractXmlTagValue($block, 'DebitBankBIC'),
                'ClientCreditName' => $this->extractXmlTagValue($block, 'ClientCreditName'),
                'ClientCreditFiscalCode' => $this->extractXmlTagValue($block, 'ClientCreditFiscalCode'),
                'AccountCredit' => $this->extractXmlTagValue($block, 'AccountCredit'),
                'CreditBankBIC' => $this->extractXmlTagValue($block, 'CreditBankBIC'),
                'Amount' => $this->extractXmlTagValue($block, 'Amount'),
                'AmountCredit' => $this->extractXmlTagValue($block, 'AmountCredit'),
                'AmountDebit' => $this->extractXmlTagValue($block, 'AmountDebit'),
                'Destination' => $this->extractXmlTagValue($block, 'Destination'),
                'MovementID' => $this->extractXmlTagValue($block, 'MovementID'),
                'MovementTypeID' => $this->extractXmlTagValue($block, 'MovementTypeID')
            );
        }

        return $documents;
    }

    /**
     * Extract a tag value from XML text.
     *
     * @param string $xmlData XML text
     * @param string $tag Tag name
     * @return string
     */
    private function extractXmlTagValue($xmlData, $tag)
    {
        if (preg_match('/<'.preg_quote($tag, '/').'>\s*(.*?)\s*<\/'.preg_quote($tag, '/').'>/si', $xmlData, $m)) {
            return html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return '';
    }


    /**
     * Read XML value from array or SimpleXMLElement.
     *
     * @param mixed $doc Document source
     * @param string $field Field name
     * @return string
     */
    private function getVictoriaValue($doc, $field)
    {
        if (is_array($doc)) {
            return isset($doc[$field]) ? (string) $doc[$field] : '';
        }

        if (is_object($doc) && isset($doc->{$field})) {
            return (string) $doc->{$field};
        }

        return '';
    }

    /**
     * Parse supported date formats.
     *
     * @param string $dateString Date string
     * @return int Timestamp
     */
    private function parseDate($dateString)
    {
        $dateString = trim((string) $dateString);
        if ($dateString === '') {
            return 0;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateString, $m)) {
            return dol_mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
        }

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $dateString, $m)) {
            return dol_mktime(0, 0, 0, (int) $m[2], (int) $m[1], (int) $m[3]);
        }

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{2})$/', $dateString, $m)) {
            return dol_mktime(0, 0, 0, (int) $m[2], (int) $m[1], (int) ('20'.$m[3]));
        }

        $timestamp = strtotime($dateString);
        return ($timestamp !== false) ? $timestamp : 0;
    }

    /**
     * Limit string length
     *
     * @param string|null $text Text to limit
     * @param int $length Maximum length
     * @param bool $fixed Fixed length
     * @return string Limited string
     */
    private function limitString($text, $length = 255, $fixed = false)
    {
        if ($text === null) {
            return $fixed ? str_repeat(' ', $length) : '';
        }
        $limited = substr($text, 0, $length);
        return $fixed ? str_pad($limited, $length) : $limited;
    }

    /**
     * Generate import key
     *
     * @param string|null $transaction_id Transaction ID
     * @param string $iban_other Counterparty IBAN
     * @param string $owner_other Counterparty name
     * @param float $amount Amount
     * @param string $label Label
     * @param string $ref Reference
     * @return string Import key
     */
    private function generateImportKey($transaction_id, $iban_other, $owner_other, $amount, $label, $ref)
    {
        if (!empty($transaction_id)) {
            return substr(trim($transaction_id), 0, 14);
        }

        $key = implode('|', array(
            trim($iban_other),
            trim($owner_other),
            number_format($amount, 2, '.', ''),
            trim($label),
            trim($ref)
        ));
        return substr(sha1($key), 0, 14);
    }

    /**
     * Check if transaction is already imported
     *
     * @param string $import_key Import key
     * @return bool True if already imported
     */
    private function isAlreadyImported($import_key)
    {
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX."bank WHERE import_key = '".$this->db->escape($import_key)."'";
        $resql = $this->db->query($sql);
        if ($resql) {
            return $this->db->num_rows($resql) > 0;
        }
        return false;
    }

    /**
     * Update import key for bank line
     *
     * @param int $bankline_id Bank line ID
     * @param string $import_key Import key
     * @return bool Success
     */
    private function updateImportKey($bankline_id, $import_key)
    {
        $sql = 'UPDATE '.MAIN_DB_PREFIX."bank SET import_key = '".$this->db->escape($import_key)."' WHERE rowid = ".((int) $bankline_id);
        return $this->db->query($sql);
    }

    /**
     * Build note from CSV data
     *
     * @param array $data CSV data
     * @return string Note
     */
    private function buildNote($data)
    {
        $noteParts = array();

        if (!empty($data[$this->fieldMapping['collector_reference']])) {
            $noteParts[] = 'Sammlerreferenz='.$data[$this->fieldMapping['collector_reference']];
        }

        if (!empty($data[$this->fieldMapping['creditor_id']])) {
            $noteParts[] = 'GlaeubigerId='.$data[$this->fieldMapping['creditor_id']];
        }

        if (!empty($data[$this->fieldMapping['info']])) {
            $noteParts[] = trim($data[$this->fieldMapping['info']]);
        }

        return implode(' ', $noteParts);
    }

    /**
     * Normalize account value.
     *
     * @param string $account Account value
     * @return string
     */
    private function normalizeAccount($account)
    {
        $account = trim((string) $account);
        if ($account === '') {
            return '';
        }
        if (strpos($account, '/') !== false) {
            $parts = explode('/', $account);
            $account = trim($parts[0]);
        }
        return $account;
    }

    /**
     * Extract currency from account values.
     *
     * @param string $accountDebit Debit account string
     * @param string $accountCredit Credit account string
     * @param string $myAccount Own normalized account
     * @return string
     */
    private function extractCurrency($accountDebit, $accountCredit, $myAccount)
    {
        $candidates = array($accountDebit, $accountCredit, $myAccount);
        foreach ($candidates as $candidate) {
            if (preg_match('/\/([A-Z]{3})$/', trim((string) $candidate), $m)) {
                return $m[1];
            }
            if (preg_match('/([A-Z]{3})$/', trim((string) $candidate), $m)) {
                return $m[1];
            }
        }
        return '';
    }

    /**
     * Cleanup counterparty names.
     *
     * @param string $name Counterparty name
     * @return string
     */
    private function cleanupCounterpartyName($name)
    {
        $name = trim((string) $name);
        $name = preg_replace('/^\(R\)\s*/', '', $name);
        return $name;
    }

    /**
     * Format XML date to CSV date format.
     *
     * @param string $date Date in YYYY-MM-DD
     * @return string
     */
    private function formatDateForCsv($date)
    {
        $date = trim((string) $date);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return $m[3].'.'.$m[2].'.'.substr($m[1], 2, 2);
        }
        return $date;
    }
}
