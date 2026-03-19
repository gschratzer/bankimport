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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';

/**
 * BankImport class.
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
     * @var array<string,int> CSV field mapping
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
        'info' => 16,
    );

    /**
     * Constructor.
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Set account ID.
     *
     * @param int $accountid Bank account ID
     * @return void
     */
    public function setAccountId($accountid)
    {
        $this->accountid = (int) $accountid;
    }

    /**
     * Set encoding.
     *
     * @param string $encoding File encoding
     * @return void
     */
    public function setEncoding($encoding)
    {
        $this->encoding = $encoding;
    }

    /**
     * Set import format.
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
     * Validate uploaded file.
     *
     * @param array<string,mixed> $file $_FILES array element
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

        if (!isset($file['size']) || (int) $file['size'] > 10 * 1024 * 1024) {
            $this->error = 'File too large (max 10MB)';
            return false;
        }

        $name = isset($file['name']) ? (string) $file['name'] : '';
        $type = isset($file['type']) ? (string) $file['type'] : '';

        if ($this->importformat === 'victoriabank_xml') {
            $allowedTypes = array('text/xml', 'application/xml');
            if (!in_array($type, $allowedTypes, true) && !preg_match('/\.xml$/i', $name)) {
                $this->error = 'Invalid file type (XML required)';
                return false;
            }

            return true;
        }

        $allowedTypes = array('text/csv', 'text/plain', 'application/csv');
        if (!in_array($type, $allowedTypes, true) && !preg_match('/\.csv$/i', $name)) {
            $this->error = 'Invalid file type (CSV required)';
            return false;
        }

        return true;
    }

    /**
     * Process input file (CSV or XML transformed to CSV).
     *
     * @param string $filename File path
     * @return array<string,mixed> Array with success count and errors
     */
    public function processFile($filename)
    {
        $result = array(
            'success' => 0,
            'errors' => array(),
            'skipped' => 0,
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
        if ($handle === false) {
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

            if ($row === 1) {
                continue;
            }

            $data = $this->convertEncoding($data);

            if (!$this->validateRow($data)) {
                $result['errors'][] = 'Row ' . $row . ': ' . $this->error;
                continue;
            }

            $importResult = $this->processRow($data);
            if ($importResult === true) {
                $result['success']++;
            } elseif ($importResult === 'skipped') {
                $result['skipped']++;
            } else {
                $result['errors'][] = 'Row ' . $row . ': ' . $importResult;
            }
        }

        fclose($handle);

        if ($cleanupCsv && is_file($csvFilename)) {
            @unlink($csvFilename);
        }

        return $result;
    }

    /**
     * Convert encoding of data array.
     *
     * @param array<int,string> $data Data array
     * @return array<int,string> Converted data array
     */
    private function convertEncoding($data)
    {
        if ($this->encoding && strtoupper($this->encoding) !== 'UTF-8') {
            foreach ($data as &$field) {
                $converted = iconv($this->encoding, 'UTF-8//TRANSLIT', (string) $field);
                $field = ($converted !== false) ? $converted : (string) $field;
            }
            unset($field);
        }

        return $data;
    }

    /**
     * Validate CSV row data.
     *
     * @param array<int,string> $data Row data
     * @return bool True if valid, false otherwise
     */
    private function validateRow($data)
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
     * Process single CSV row.
     *
     * @param array<int,string> $data Row data
     * @return bool|string True on success, 'skipped' if already imported, error message on failure
     */
    private function processRow($data)
    {
        global $user;

        $dateo = $this->parseDate($data[$this->fieldMapping['booking_date']]);
        $datev = $this->parseDate($data[$this->fieldMapping['value_date']]);
        $label = $this->limitString($data[$this->fieldMapping['payment_purpose']]);
        $amount = (float) price2num($data[$this->fieldMapping['amount']]);
        $oper = 'VIR';
        $ref = trim($data[$this->fieldMapping['mandate_reference']]);
        $categorie = '';
        $transactionId = trim($data[$this->fieldMapping['customer_reference']]);
        $bankOther = trim((string) $data[$this->fieldMapping['counterparty_bic']]);
        $ibanOther = trim((string) $data[$this->fieldMapping['counterparty_iban']]);
        $ownerOther = trim((string) $data[$this->fieldMapping['counterparty_name']]);
        $note = $this->buildNote($data);

        if ($dateo <= 0) {
            return 'Invalid booking date';
        }

        if ($datev <= 0) {
            $datev = $dateo;
        }

        $importKey = $this->generateImportKey(
            $transactionId,
            $ibanOther,
            $ownerOther,
            $amount,
            $label,
            $ref
        );

        if ($this->isAlreadyImported($importKey)) {
            return 'skipped';
        }

        $this->db->begin();

        try {
            $account = new Account($this->db);
            $resultFetch = $account->fetch($this->accountid);

            if ($resultFetch <= 0) {
                $this->db->rollback();
                return 'Could not fetch bank account';
            }

            $numchq = $this->buildNumchq($account, $dateo);
            $amountMainCurrency = $this->computeAmountMainCurrency($account, $amount, $dateo);

            if ($amountMainCurrency === false) {
                $this->db->rollback();
                return $this->error;
            }

            $bankLineLabel = $this->buildBankLineLabel($label, $note);

            $banklineId = $account->addline(
                $dateo,
                $oper,
                $bankLineLabel,
                $amount,
                $numchq,
                $categorie,
                $user,
                $ownerOther,
                $bankOther,
                '',
                $datev,
                '',
                $amountMainCurrency
            );

            if ($banklineId > 0) {
                if (!$this->updateImportKey($banklineId, $importKey)) {
                    $this->db->rollback();
                    return 'Failed to update import key';
                }

                $this->db->commit();
                return true;
            }

            $this->db->rollback();

            return !empty($account->error) ? $account->error : 'Failed to create bank line';
        } catch (Exception $e) {
            $this->db->rollback();
            return $e->getMessage();
        }
    }

    /**
     * Compute amount in main currency for foreign-currency bank accounts.
     *
     * @param Account $account Bank account
     * @param float $amount Amount in bank account currency
     * @param int $date Date of transaction
     * @return float|false|null Null if account already uses main currency, false on error
     */
    private function computeAmountMainCurrency(Account $account, $amount, $date)
    {
        global $conf;

        $accountCurrency = $this->getAccountCurrencyCode($account);
        $mainCurrency = !empty($conf->currency) ? strtoupper((string) $conf->currency) : '';

        if ($accountCurrency === '' || $mainCurrency === '' || $accountCurrency === $mainCurrency) {
            return null;
        }

        if (!isModEnabled('multicurrency')) {
            $this->error = 'Multicurrency module is required for foreign-currency bank accounts';
            return false;
        }

        require_once DOL_DOCUMENT_ROOT . '/multicurrency/class/multicurrency.class.php';

        $currencyData = MultiCurrency::getIdAndTxFromCode($this->db, $accountCurrency, $date);
        if (
            !is_array($currencyData)
            || !isset($currencyData[1])
            || empty($currencyData[1])
            || (float) $currencyData[1] <= 0
        ) {
            $this->error = 'No multicurrency exchange rate found for ' . $accountCurrency;
            return false;
        }

        $rate = (float) $currencyData[1];

        return (float) price2num($amount / $rate, 'MT');
    }

    /**
     * Get normalized currency code of bank account.
     *
     * @param Account $account Bank account
     * @return string
     */
    private function getAccountCurrencyCode(Account $account)
    {
        $currencyCode = '';
        if (!empty($account->currency_code)) {
            $currencyCode = strtoupper(trim((string) $account->currency_code));
        }

        return $currencyCode;
    }

    /**
     * Merge main label and technical note into one bank line label.
     *
     * @param string $label Main label
     * @param string $note Technical note
     * @return string
     */
    private function buildBankLineLabel($label, $note)
    {
        $label = trim((string) $label);
        $note = trim((string) $note);

        if ($note === '') {
            return $this->limitString($label);
        }

        if ($label === '') {
            return $this->limitString($note);
        }

        return $this->limitString($label . ' | ' . $note);
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
        if ($handle === false) {
            @unlink($tmpFile);
            $this->error = 'Could not create temporary CSV file';
            return false;
        }

        fputcsv(
            $handle,
            array(
                'Auftragskonto',
                'Buchungstag',
                'Valutadatum',
                'Buchungstext',
                'Verwendungszweck',
                'Glaeubiger ID',
                'Mandatsreferenz',
                'Kundenreferenz',
                'Sammlerreferenz',
                'Lastschrift Ursprungsbetrag',
                'Auslagenersatz Ruecklastschrift',
                'Beguenstigter/Zahlungspflichtiger',
                'Kontonummer/IBAN',
                'BIC (SWIFT-Code)',
                'Betrag',
                'Waehrung',
                'Info',
            ),
            ';'
        );

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
     * @param array<string,string>|object $doc XML document data
     * @param string $myAccount Own account
     * @return array<int,string>|null
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

        $currency = $this->extractCurrency(
            $this->getVictoriaValue($doc, 'AccountDebit'),
            $this->getVictoriaValue($doc, 'AccountCredit'),
            $myAccount
        );

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

        if ((float) $amount === 0.0) {
            $amount = (float) $this->getVictoriaValue($doc, 'Amount');
            $amount = $isOutgoing ? -1 * abs($amount) : abs($amount);
        }

        $bookingDate = $this->formatDateForCsv($this->getVictoriaValue($doc, 'OrderDate'));
        $valueDate = $this->formatDateForCsv($this->getVictoriaValue($doc, 'ExecuteDate'));
        $bookingText = trim($this->getVictoriaValue($doc, 'DocumentTypeID'));

        if ($bookingText === '') {
            $bookingText = trim($this->getVictoriaValue($doc, 'MovementTypeID'));
        }

        $infoParts = array();
        if (trim($this->getVictoriaValue($doc, 'MovementTypeID')) !== '') {
            $infoParts[] = 'MovementTypeID=' . trim($this->getVictoriaValue($doc, 'MovementTypeID'));
        }

        if (trim($this->getVictoriaValue($doc, 'DocumentTypeID')) !== '') {
            $infoParts[] = 'DocumentTypeID=' . trim($this->getVictoriaValue($doc, 'DocumentTypeID'));
        }

        if (trim($this->getVictoriaValue($doc, 'Nr')) !== '') {
            $infoParts[] = 'Nr=' . trim($this->getVictoriaValue($doc, 'Nr'));
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
            implode(' ', $infoParts),
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
                'MovementTypeID' => $this->extractXmlTagValue($block, 'MovementTypeID'),
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
        if (preg_match(
            '/<' . preg_quote($tag, '/') . '>\s*(.*?)\s*<\/' . preg_quote($tag, '/') . '>/si',
            $xmlData,
            $matches
        )) {
            return html_entity_decode(
                trim(strip_tags($matches[1])),
                ENT_QUOTES | ENT_XML1,
                'UTF-8'
            );
        }

        return '';
    }

    /**
     * Build human-readable bank line reference.
     *
     * Example: VBL2603-001
     *
     * @param Account $bankaccount Bank account
     * @param int $date Transaction date
     * @return string
     */
    public function buildNumchq(Account $bankaccount, int $date): string
    {
        $accountRef = strtoupper(trim((string) $bankaccount->ref));
        $accountRef = preg_replace('/[^A-Z0-9]/', '', $accountRef);

        if ($accountRef === '') {
            $accountRef = 'BANK';
        }

        $period = dol_print_date($date, '%y%m');
        $seq = $this->getNextMonthlySequence($accountRef, (int) $bankaccount->id, $period);

        return $accountRef . $period . '-' . sprintf('%03d', $seq);
    }

    /**
     * Get next monthly sequence for num_chq.
     *
     * @param string $accountRef Sanitized account ref
     * @param int $bankAccountId Bank account ID
     * @param string $period Period in yymm format
     * @return int
     */
    private function getNextMonthlySequence($accountRef, $bankAccountId, $period)
    {
        $prefix = $this->db->escape($accountRef . $period . '-');

        $sql = 'SELECT num_chq';
        $sql .= ' FROM ' . MAIN_DB_PREFIX . 'bank';
        $sql .= ' WHERE fk_account = ' . ((int) $bankAccountId);
        $sql .= " AND num_chq LIKE '" . $prefix . "%'";
        $sql .= ' ORDER BY num_chq DESC';
        $sql .= ' LIMIT 1';

        $resql = $this->db->query($sql);
        if (!$resql) {
            return 1;
        }

        $obj = $this->db->fetch_object($resql);
        if (!$obj || empty($obj->num_chq)) {
            return 1;
        }

        if (preg_match('/-(\d{3})$/', (string) $obj->num_chq, $matches)) {
            return ((int) $matches[1]) + 1;
        }

        return 1;
    }

    /**
     * Read XML value from array or object.
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

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateString, $matches)) {
            return dol_mktime(0, 0, 0, (int) $matches[2], (int) $matches[3], (int) $matches[1]);
        }

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $dateString, $matches)) {
            return dol_mktime(0, 0, 0, (int) $matches[2], (int) $matches[1], (int) $matches[3]);
        }

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{2})$/', $dateString, $matches)) {
            return dol_mktime(0, 0, 0, (int) $matches[2], (int) $matches[1], (int) ('20' . $matches[3]));
        }

        $timestamp = strtotime($dateString);

        return ($timestamp !== false) ? $timestamp : 0;
    }

    /**
     * Limit string length.
     *
     * @param string|null $text Text to limit
     * @param int $length Maximum length
     * @param bool $fixed Fixed length
     * @return string
     */
    private function limitString($text, $length = 255, $fixed = false)
    {
        if ($text === null) {
            return $fixed ? str_repeat(' ', $length) : '';
        }

        $limited = substr((string) $text, 0, $length);

        return $fixed ? str_pad($limited, $length) : $limited;
    }

    /**
     * Generate import key.
     *
     * @param string|null $transactionId Transaction ID
     * @param string $ibanOther Counterparty IBAN
     * @param string $ownerOther Counterparty name
     * @param float $amount Amount
     * @param string $label Label
     * @param string $ref Reference
     * @return string Import key
     */
    private function generateImportKey($transactionId, $ibanOther, $ownerOther, $amount, $label, $ref)
    {
        if (!empty($transactionId)) {
            return substr(trim($transactionId), 0, 14);
        }

        $key = implode(
            '|',
            array(
                trim($ibanOther),
                trim($ownerOther),
                number_format($amount, 2, '.', ''),
                trim($label),
                trim($ref),
            )
        );

        return substr(sha1($key), 0, 14);
    }

    /**
     * Check if transaction is already imported.
     *
     * @param string $importKey Import key
     * @return bool True if already imported
     */
    private function isAlreadyImported($importKey)
    {
        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "bank WHERE import_key = '" . $this->db->escape($importKey) . "'";
        $resql = $this->db->query($sql);

        if ($resql) {
            return $this->db->num_rows($resql) > 0;
        }

        return false;
    }

    /**
     * Update import key for bank line.
     *
     * @param int $banklineId Bank line ID
     * @param string $importKey Import key
     * @return bool
     */
    private function updateImportKey($banklineId, $importKey)
    {
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . "bank";
        $sql .= " SET import_key = '" . $this->db->escape($importKey) . "'";
        $sql .= ' WHERE rowid = ' . ((int) $banklineId);

        return (bool) $this->db->query($sql);
    }

    /**
     * Build note from CSV data.
     *
     * @param array<int,string> $data CSV data
     * @return string
     */
    private function buildNote($data)
    {
        $noteParts = array();

        if (!empty($data[$this->fieldMapping['collector_reference']])) {
            $noteParts[] = 'Sammlerreferenz=' . $data[$this->fieldMapping['collector_reference']];
        }

        if (!empty($data[$this->fieldMapping['creditor_id']])) {
            $noteParts[] = 'GlaeubigerId=' . $data[$this->fieldMapping['creditor_id']];
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
            $candidate = trim((string) $candidate);

            if (preg_match('/\/([A-Z]{3})$/', $candidate, $matches)) {
                return $matches[1];
            }

            if (preg_match('/([A-Z]{3})$/', $candidate, $matches)) {
                return $matches[1];
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

        return (string) $name;
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

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return $matches[3] . '.' . $matches[2] . '.' . substr($matches[1], 2, 2);
        }

        return $date;
    }
}