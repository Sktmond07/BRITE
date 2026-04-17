<?php
require_once 'BaseDocumentGenerator.php';

class CertificateOfResidencyGenerator extends BaseDocumentGenerator {
    
    protected function generateSpecificDocument($request, $data, $filepath) {
        $pdf = $this->setupPDF();
        
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(0, 0, 0);
        
        $this->addHeader($pdf);
        $this->addReferenceNumber($pdf, $request['id']);
        $this->addTitle($pdf, 'CERTIFICATE OF RESIDENCY', 16);
        
        $full_name = isset($data['full_name']) ? strtoupper($data['full_name']) : strtoupper($request['first_name'] . ' ' . $request['last_name']);
        $age = isset($data['age']) ? $data['age'] : '_____';
        $gender = isset($data['gender']) ? $data['gender'] : '_____';
        $civil_status = isset($data['civil_status']) ? $data['civil_status'] : '_____';
        $house_number = isset($data['house_number']) ? $data['house_number'] : '_____';
        $purok = isset($data['purok']) ? $data['purok'] : '';
        $purpose = isset($data['purpose']) ? strtoupper($data['purpose']) : '_____';
        $pdf->Ln(9);
        $this->addToWhomItMayConcern($pdf);
        
        $pdf->SetFont('times', '', 12);
        $pdf->Ln(5);
        
        // Paragraph 1 - Only NAME and ADDRESS are bold
        $pdf->SetFont('times', '', 12);
        $pdf->Write(8, "THIS IS TO CERTIFY that ", 0, 0);
        $pdf->SetFont('times', 'B', 12);
        $pdf->Write(8, $full_name, 0, 0);
        $pdf->SetFont('times', '', 12);
        $pdf->Write(8, ", " . $age . " years old, " . $gender . ", " . $civil_status . ", Filipino is a bonafide resident of ", 0, 0);
        $pdf->SetFont('times', 'B', 12);
        
        $address = (!empty($house_number) ? "#  {$house_number}, " : "") . "{$purok}, San Bartolome, Sto Tomas, Pampanga";
        
        
        $pdf->Write(8, $address, 0, 1);
        $pdf->SetFont('times', '', 12);
        
        $pdf->Ln(10);
        
        // Paragraph 2 - No bold
        $pdf->Write(8, "This further certifies that the above-mentioned person has no derogatory nor adverse record filed in this barangay as to this day.", 0, 1);
        
        $pdf->Ln(10);
        
        // Paragraph 3 - Only PURPOSE is bold
        $pdf->Write(8, "This certification is issued upon request of the bearer in connection with his/her desire for  ", 0, 0);
        $pdf->SetFont('times', 'B', 12);
        $pdf->Write(8, $purpose, 0, 0);
        $pdf->SetFont('times', '', 12);
        $pdf->Write(8, " .", 0, 1);
        
        $pdf->Ln(10);
        
        // Paragraph 4 - No bold
        $pdf->Write(8, "Issued this " . date('jS') . " day of " . date('F') . ", " . date('Y') . ", at " . $this->barangay_name . ", " . $this->municipality . ", " . $this->province . ", Philippines.", 0, 1);
        
        $this->addSignatures($pdf, $full_name);
        $this->addFooter($pdf);
        
        $pdf->Output($filepath, 'F');
    }
}