<?php
require_once 'BaseDocumentGenerator.php';

class CertificateOfNonResidencyGenerator extends BaseDocumentGenerator {
    
    protected function generateSpecificDocument($request, $data, $filepath) {
        $pdf = $this->setupPDF();
        $this->addHeader($pdf);
        $this->addReferenceNumber($pdf, $request['id']);
        $this->addTitle($pdf, 'CERTIFICATE OF NON-RESIDENCY', 16);
        
        $full_name = isset($data['full_name']) ? $data['full_name'] : $request['first_name'] . ' ' . $request['last_name'];
        $current_address = isset($data['current_address']) ? $data['current_address'] : '_____';
        $previous_purok = isset($data['previous_purok']) ? $data['previous_purok'] : '_____';
        $previous_house_number = isset($data['previous_house_number']) ? $data['previous_house_number'] : '_____';
        $last_date_of_residency = isset($data['last_date_of_residency']) ? $this->formatDate($data['last_date_of_residency']) : '_____';
        $purpose = isset($data['purpose']) ? $data['purpose'] : '_____';
        
        $this->addToWhomItMayConcern($pdf);
        
        $pdf->SetFont('times', '', 12);
        $text = "This is to certify that based on the records of this office, " . ucwords($full_name) . " is NOT a resident of {$this->barangay_name}, " .
                "{$this->municipality}, {$this->province}.\n\n";
        $text .= "The individual previously resided at {$previous_purok}, " . (!empty($previous_house_number) ? "House No. {$previous_house_number}, " : "") . 
                "and last resided in this barangay on {$last_date_of_residency}. The individual has since transferred to: {$current_address}.\n\n";
        $text .= "This certification is issued upon the request of the above-named individual for {$purpose} purposes.\n\n";
        $text .= "Issued this " . date('jS') . " day of " . date('F') . ", " . date('Y') . " at {$this->barangay_name}, {$this->municipality}, {$this->province}.";
        
        $pdf->MultiCell(0, 8, $text, 0, 'J');
        
        $this->addSignatures($pdf, $full_name);
        $this->addFooter($pdf);
        
        $pdf->Output($filepath, 'F');
    }
}