<?php
require_once 'BaseDocumentGenerator.php';

class CertificateOfCohabitationGenerator extends BaseDocumentGenerator {
    
    protected function generateSpecificDocument($request, $data, $filepath) {
        $pdf = $this->setupPDF();
        $this->addHeader($pdf);
        $this->addReferenceNumber($pdf, $request['id']);
        $this->addTitle($pdf, 'CERTIFICATE OF COHABITATION', 16);
        
        $full_name = isset($data['full_name']) ? $data['full_name'] : $request['first_name'] . ' ' . $request['last_name'];
        $birthdate = isset($data['birthdate']) ? $this->formatDate($data['birthdate']) : '_____';
        $partner_full_name = isset($data['partner_full_name']) ? $data['partner_full_name'] : '_____';
        $partner_birthdate = isset($data['partner_birthdate']) ? $this->formatDate($data['partner_birthdate']) : '_____';
        $purok = isset($data['purok']) ? $data['purok'] : '_____';
        $house_number = isset($data['house_number']) ? $data['house_number'] : '_____';
        $date_of_cohabitation = isset($data['date_of_cohabitation']) ? $this->formatDate($data['date_of_cohabitation']) : '_____';
        
         $pdf->Ln(10);
        $this->addToWhomItMayConcern($pdf);
         $pdf->Ln(10);
        $pdf->SetFont('times', '', 12);
        
        // Start the sentence
        $pdf->Write(6, "THIS IS TO CERTIFY that ");
        
        // Full name - bold, all caps, underlined
        $pdf->SetFont('times', 'BU', 12);
        $pdf->Write(6, strtoupper(ucwords($full_name)));
        
        $pdf->SetFont('times', '', 12);
        $pdf->Write(6, " born on ");
        
        // Birthdate - bold, all caps, underlined
        $pdf->SetFont('times', 'BU', 12);
        $pdf->Write(6, strtoupper($birthdate));
        
        $pdf->SetFont('times', '', 12);
        $pdf->Write(6, ", and ");
        
        // Partner name - bold, all caps, underlined
        $pdf->SetFont('times', 'BU', 12);
        $pdf->Write(6, strtoupper(ucwords($partner_full_name)));
        
        $pdf->SetFont('times', '', 12);
        $pdf->Write(6, " born on ");
        
        // Partner birthdate - bold, all caps, underlined
        $pdf->SetFont('times', 'BU', 12);
        $pdf->Write(6, strtoupper($partner_birthdate));
        
        $pdf->SetFont('times', '', 12);
        $pdf->Write(6, ", have been living as spouses in good faith, taking on all of the tasks and responsibilities that follow with being in the said relationship, cohabiting in the same household at ");
        
        // Address - bold, all caps, underlined
        $address = (!empty($house_number) ? "#" . strtoupper($house_number) . ", " : "") . 
                   strtoupper($purok) . ", San Bartolome, Sto Tomas, Pampanga";
        $pdf->SetFont('times', 'BU', 12);
        $pdf->Write(6, $address);
        
        $pdf->SetFont('times', '', 12);
        $pdf->Write(6, " and both holding themselves out to the community as spouses since ");
        
        // Date of cohabitation - bold, all caps, underlined
        $pdf->SetFont('times', 'BU', 12);
        $pdf->Write(6, strtoupper($date_of_cohabitation));
        
        $pdf->SetFont('times', '', 12);
        $pdf->Write(6, ".");
        
        $pdf->Ln(12);
        
        // Given and signed statement
        $pdf->Write(6, "Given and signed this " . date('jS') . " day of " . date('F') . ", " . date('Y') . 
                   " at the Office of the  Barangay Captain of San Bartolome, Sto Tomas, Pampanga ");
        
        $pdf->Ln(15);
        
        $this->addSignatures($pdf, $full_name);
        $this->addFooter($pdf);
        
        $pdf->Output($filepath, 'F');
    }
}