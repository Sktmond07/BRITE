<?php
require_once 'BaseDocumentGenerator.php';

class FirstTimeJobSeekerGenerator extends BaseDocumentGenerator {
    
    protected function generateSpecificDocument($request, $data, $filepath) {
        $pdf = $this->setupPDF();
        $this->addHeader($pdf);
        $this->addReferenceNumber($pdf, $request['id']);
        $this->addTitle($pdf, 'CERTIFICATE OF FIRST TIME JOB SEEKER', 14);
        
        $full_name = isset($data['full_name']) ? $data['full_name'] : $request['first_name'] . ' ' . $request['last_name'];
        $purok = isset($data['purok']) ? $data['purok'] : '_____';
        $house_number = isset($data['house_number']) ? $data['house_number'] : '_____';
        $age = isset($data['age']) ? $data['age'] : '_____';
        $gender = isset($data['gender']) ? $data['gender'] : '_____';
        $purpose = isset($data['purpose']) ? $data['purpose'] : '_____';
        
        $this->addToWhomItMayConcern($pdf);
        
        $pdf->SetFont('times', '', 12);
        $text = "This is to certify that " . ucwords($full_name) . ", {$age} years old, {$gender}, is a bona fide resident of {$purok}, " . 
                (!empty($house_number) ? "House No. {$house_number}, " : "") . "{$this->barangay_name}, {$this->municipality}, {$this->province}.\n\n";
        $text .= "This certification is issued in accordance with Republic Act No. 11261, also known as the \"First Time Job Seekers Assistance Act\", " .
                "which exempts first time job seekers from payment of government fees and charges for the issuance of certain documents.\n\n";
        $text .= "This certification is requested for: {$purpose}.\n\n";
        $text .= "Issued this " . date('jS') . " day of " . date('F') . ", " . date('Y') . " at {$this->barangay_name}, {$this->municipality}, {$this->province}.";
        
        $pdf->MultiCell(0, 8, $text, 0, 'J');
        
        $pdf->Ln(5);
        $pdf->SetFont('times', 'B', 10);
        $pdf->SetTextColor(255, 0, 0);
        $pdf->Cell(0, 6, '*** No Fee Required Under RA 11261 ***', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
        
        $this->addSignatures($pdf, $full_name, true);
        $this->addFooter($pdf);
        
        $pdf->Output($filepath, 'F');
    }
}