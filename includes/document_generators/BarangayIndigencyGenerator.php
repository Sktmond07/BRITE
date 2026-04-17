<?php
require_once 'BaseDocumentGenerator.php';

class BarangayIndigencyGenerator extends BaseDocumentGenerator {
    
    protected function generateSpecificDocument($request, $data, $filepath) {
        $pdf = $this->setupPDF();
        $this->addHeader($pdf);
        $this->addReferenceNumber($pdf, $request['id']);
        $this->addTitle($pdf, 'CERTIFICATE OF INDIGENCY', 16);
        
        $full_name = isset($data['full_name']) ? $data['full_name'] : $request['first_name'] . ' ' . $request['last_name'];
        $purok = isset($data['purok']) ? $data['purok'] : '_____';
        $house_number = isset($data['house_number']) ? $data['house_number'] : '_____';
        $birthdate = isset($data['birthdate']) ? $this->formatDate($data['birthdate']) : '_____';
        $monthly_income = isset($data['monthly_income']) ? number_format($data['monthly_income'], 2) : '_____';
        $number_of_dependents = isset($data['number_of_dependents']) ? $data['number_of_dependents'] : '0';
        $purpose = isset($data['purpose']) ? $data['purpose'] : '_____';
        
        $this->addToWhomItMayConcern($pdf);
        
       $pdf->SetFont('times', '', 12);
        $text = "This is to certify that " . ucwords($full_name) . ", born on {$birthdate}, is a resident of {$purok}, " . 
                (!empty($house_number) ? "House No. {$house_number}, " : "") . "{$this->barangay_name}, {$this->municipality}, {$this->province}.\n\n";
        $text .= "Based on the records of this office and community validation, the above-named individual belongs to an economically disadvantaged household " .
                "with an estimated monthly income of PHP {$monthly_income} and has {$number_of_dependents} dependent/s.\n\n";
        $text .= "This certification is issued upon the request of the above-named resident for {$purpose} purposes to avail of government assistance programs and social services.\n\n";
        $text .= "Issued this " . date('jS') . " day of " . date('F') . ", " . date('Y') . " at {$this->barangay_name}, {$this->municipality}, {$this->province}.";
        
        $pdf->MultiCell(0, 8, $text, 0, 'J');
        
        $this->addSignatures($pdf, $full_name);
        $this->addFooter($pdf);
        
        $pdf->Output($filepath, 'F');
    }
}