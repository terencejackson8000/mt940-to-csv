<?php
/**
 * Converter implementation
 */
class Converter implements IConverter
{
    private array $rows = [];

    public function __construct()
    {
    }

    
    /**
     * Convert the input MT940 file to a list of transactions.
     *
     * @param  string $input The path to the input file name
     * @return array The list of transactions
     */
    public function convert(string $input): array
    {
        if (!file_exists($input)) {
            throw new FileNotFoundException();
        }

        $fileContents = file_get_contents($input);
        $transactions = $this->extractTransactions($fileContents);
        $this->rows = $this->convertToRows($transactions);
        return $this->rows;
    }

    /**
     * Extract transactions from the given file contents using regular expressions.
     *
     * @param string $fileContents The contents of the input file
     * @return array The list of transactions
     */
    private function extractTransactions(string $fileContents): array
    {
        preg_match_all('/(?<=:61:).*?(?=:[0-9]{2}[A-Z]{0,1}:)|(?<=:86:).*?(?=:[0-9]{2}[A-Z]{0,1}:)/s', $fileContents, $matches);
        return $matches[0];
    }

    /**
     * Convert a list of transactions to rows.
     *
     * @param array $transactions The list of transactions
     * @return array The list of rows
     */
    private function convertToRows(array $transactions): array
    {
        $rows = [];
        foreach (array_chunk($transactions, 2) as [$transaction, $description]) {
            $row = $this->convertToRow($transaction, $description);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Convert a transaction to a row.
     *
     * @param string $transaction The transaction to convert
     * @param string $description The description of the transaction
     * @return Transaction|null The converted row or null if an error occurred
     */
    private function convertToRow(string $transaction, string $description): ?Transaction
    {
        preg_match('/(\d{6})(\d{4})?([A-Z])([A-Z]{1,2})?(\d+,\d+)?/', $transaction, $matches);
        if (sizeof($matches) !== 6) {
            echo "Error in parsing line " . $transaction;
            return null;
        }

        $trx = new Transaction();

        $transactionDate = $matches[1];
        $date = DateTime::createFromFormat('ymd', $transactionDate);

        $cleanedDescription = $this->cleanDescription($description);

        $iban = $this->getIBAN($cleanedDescription);
        $name = $this->getName($cleanedDescription);

        $memo = $this->getMemo($cleanedDescription);
        $sepa = $this->getSEPAMandateReference($memo); 

        $trx->transactionDate = $date;
        $trx->description = rtrim($memo);
        $trx->sepaReference = $sepa;

        $transactionAmount = str_replace(',', '.', $matches[5]);
        $type = $matches[3];
        if ($type === 'D') {
            $trx->transactionAmount = floatval(-$transactionAmount);
            $trx->recepientIban = $iban;
            $trx->recipientName = $name;
        }else{
            $trx->transactionAmount = floatval($transactionAmount);
            $trx->payerIban = $iban;
            $trx->payerName = $name;
        }

        return $trx;
    }

    /**
     * Cleans the given description by removing any special characters and replacing the TAN number with "xxxxxx".
     * 
     * @param string $description The description to be cleaned
     * @return string The cleaned description
    */
    private function cleanDescription($description) : string{
        //$cleanedDescription = preg_replace('/\?[0-9]{2}/', '', $description);
        $cleanedDescription = preg_replace('/TAN: (\d{6})/', 'TAN: xxxxxx', $description);
        $cleanedDescription = preg_replace('/\R+/', '', $cleanedDescription);
        return $cleanedDescription;
    }

        
    /**
     * Gets the SEPA mandate reference out of the description
     *
     * @param  string $description The description or text to extract the SEPA mandate reference
     * @return string The extracted SEPA reference or NULL
     */
    private function getSEPAMandateReference(string $description) : ?string {
        if($description === null || trim($description) === "") {
            return null;
        }
        $matches = array();
        preg_match("/\b([A-Z]{2}\d{2}[A-Z0-9]{3}\d{1,30})\b/", $description, $matches);
        if(sizeof($matches) <= 0){
            return null;
        }
        return $matches[1];
    }

        
    /**
     * Get the IBAN out of the bank text
     *
     * @param  string $description The description or text to extract the IBAN from
     * @return string The extracted IBAN or null
     */
    private function getIBAN(string $description) : ?string{
        preg_match('/\?31([A-Z]{2}\d{2}[A-Z0-9]{18})/', $description, $ibanMatches);
        if (sizeof($ibanMatches) === 2) {
            return $ibanMatches[1];
        }
        return null; 
    }
    
    /**
     * Get the name of the principal
     *
     * @param  string $description The description or text to extract the name from
     * @return string The name of the principal or null
     */
    private function getName(string $description) : ?string{
        $pattern = '/\?32(.*?)(\?33(.*?)\?|$)/'; // updated pattern to include optional part between ?33 and ?

        preg_match($pattern, $description, $matches);

        if (isset($matches[1])) {
            $result = $matches[1]; // contains the extracted substring before ?33
            
            $result = str_replace('?', '', $result); // remove any remaining question marks
            
            if (isset($matches[3])) {
                $optionalPart = $matches[3]; // contains the extracted substring between ?33 and ?
                $optionalPart = str_replace('?', '', $optionalPart); // remove any remaining question marks
                $result .= $optionalPart; // concatenate both parts
            }
            
            return $result;
        } 

        return null;
    }

    private function getPostingText(string $description) : ?string{
        preg_match('/\?00([\w\d]{1,27})\?/', $description, $matches);
        if (sizeof($matches) === 2) {
            return $matches[1];
        }
        return null; 
    }

    private function getMemo(string $description) : ?string {
        //First filter everything between the ?20 and the ?30
        preg_match('/\?20(.*)\?30/', $description, $initialMatches);

        //No match => Does not have ?20 in it => Return the string
        if (!$initialMatches) {
            return $description;
        }

        /**
         * The following regex are based on these assumptions:
         * 1. If there is a character before the ? and a character after the ? it is one word and no space is added
         * 2. If there is a number before the ? and a number after the ? it is one word and no space is added
         * 3. If there is a character before the ? and a number after the ? it is two words and a space is added
         * 4. If there is a number before the ? and a character after the ? it is two words and a space is added
         * 5. If there is a lower case character before the ? and a upper case character after the ? it is two words and a space is added
         * 6. If there is space before the ? and a number after the ? it is directly combined as there is alredy a space
         * 7. If there is space before the ? and a character after the ? it is directly combined as there is alredy a space
         * 8. If there is character before the ? and a space after the ? it is directly combined as there is alredy a space
         * 9. If there is number before the ? and a space after the ? it is directly combined as there is alredy a space
         */
        if (sizeof($initialMatches) === 2) {
            $workingString = $initialMatches[1];
            $workingString = preg_replace('/([a-zA-Z]{1})\?2\d([a-zA-Z]{1})/', '$1$2', $workingString);
            $workingString = preg_replace('/([0-9]{1})\?2\d([0-9]{1})/', '$1$2', $workingString);
            $workingString = preg_replace('/([a-zA-Z]{1})\?2\d([0-9]{1})/', '$1 $2', $workingString);
            $workingString = preg_replace('/([0-9]{1})?\?2\d([a-zA-Z]{1})/', '$1 $2', $workingString);
            $workingString = preg_replace('/([a-z]{1})\?2\d([A-Z]{1})/', '$1 $2', $workingString);
            $workingString = preg_replace('/(\s{1})\?2\d([0-9]{1})/', '$1$2', $workingString);
            $workingString = preg_replace('/(\s{1})?\?2\d([a-zA-Z]{1})/', '$1$2', $workingString);
            $workingString = preg_replace('/([a-zA-Z]{1})\?2\d(\s{1})/', '$1$2', $workingString);
            $workingString = preg_replace('/([0-9]{1})?\?2\d(\s{1})/', '$1$2', $workingString);
            return $workingString;   
        }
        return null; 
    }
}
