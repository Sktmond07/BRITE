<?php
require_once 'BaseDocumentGenerator.php';

class BarangayClearanceGenerator extends BaseDocumentGenerator {
    
    protected function generateSpecificDocument($request, $data, $filepath) {
        $pdf = $this->setupPDF();
        $this->addHeader($pdf);
        $this->addReferenceNumber($pdf, $request['id']);
        $this->addTitle($pdf, 'BARANGAY CLEARANCE', 18);
        
        // Get data from custom fields
        $full_name = isset($data['full_name']) ? $data['full_name'] : $request['first_name'] . ' ' . $request['last_name'];
        $purok = isset($data['purok']) ? $data['purok'] : '_____';
        $house_number = isset($data['house_number']) ? $data['house_number'] : '_____';
        $birthdate = isset($data['birthdate']) ? $this->formatDate($data['birthdate']) : '_____';
        $gender = isset($data['gender']) ? $data['gender'] : '_____';
        $civil_status = isset($data['civil_status']) ? $data['civil_status'] : '_____';
        $purpose = isset($data['purpose']) ? $data['purpose'] : '_____';
        
        // Calculate age from birthdate
        $age = '_____';
        if (isset($data['birthdate']) && !empty($data['birthdate']) && $data['birthdate'] != '0000-00-00') {
            $birthDate = new DateTime($data['birthdate']);
            $today = new DateTime();
            $age = $birthDate->diff($today)->y;
        }
        $pdf->Ln(6);
        $this->addToWhomItMayConcern($pdf);
        $pdf->Ln(8);
        
        // Opening statement
        $pdf->SetFont('times', '', 12);
        $pdf->MultiCell(0, 7, "This is to certify that the person named herein is a bona fide resident of this barangay. Based on the records available in this office, he/she is known to be of good moral character, a law-abiding citizen, and has no derogatory record on file as of this date.", 0, 'L');
        $pdf->Ln(8);
        
        // Full Name
        $pdf->SetFont('times', '', 12);
        $pdf->Cell(40, 7, "Full Name:", 0, 0, 'L');
        $pdf->SetFont('times', 'B', 12);
        $pdf->Cell(0, 7, ucwords($full_name), 0, 1, 'L');
        
        // Address
        $pdf->SetFont('times', '', 12);
        $pdf->Cell(40, 7, "Address:", 0, 0, 'L');
        $pdf->SetFont('times', 'B', 12);
        $address = (!empty($house_number) ? "#  {$house_number}, " : "") . "{$purok}, San Bartolome, Sto Tomas, Pampanga";
        $pdf->Cell(0, 7, $address, 0, 1, 'L');
        
        // Date of Birth
        $pdf->SetFont('times', '', 12);
        $pdf->Cell(40, 7, "Date of Birth:", 0, 0, 'L');
        $pdf->SetFont('times', 'B', 12);
        $pdf->Cell(0, 7, $birthdate, 0, 1, 'L');
        
        // Gender
        $pdf->SetFont('times', '', 12);
        $pdf->Cell(40, 7, "Gender:", 0, 0, 'L');
        $pdf->SetFont('times', 'B', 12);
        $pdf->Cell(0, 7, $gender, 0, 1, 'L');
        
        // Civil Status
        $pdf->SetFont('times', '', 12);
        $pdf->Cell(40, 7, "Civil Status:", 0, 0, 'L');
        $pdf->SetFont('times', 'B', 12);
        $pdf->Cell(0, 7, $civil_status, 0, 1, 'L');
        
        // Purpose
        $pdf->SetFont('times', '', 12);
        $pdf->Cell(40, 7, "Purpose:", 0, 0, 'L');
        $pdf->SetFont('times', 'B', 12);
        $pdf->Cell(0, 7, $purpose, 0, 1, 'L');
        
        $pdf->Ln(10);
        
        // Issuance details
        $pdf->SetFont('times', 'I', 11);
        $pdf->MultiCell(0, 6, "Issued this " . date('jS') . " day of " . date('F') . ", " . date('Y') . " at {$this->barangay_name}, {$this->municipality}, {$this->province}.", 0, 'L');
        
        
        
        $this->addSignatures($pdf, $full_name);
        $this->addFooter($pdf);
        
        $pdf->Output($filepath, 'F');
    }
}