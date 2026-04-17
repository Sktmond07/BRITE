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
        $civil_status = isset($data['civil_status']) ? $data['civil_status'] : '_____';
        
        // Determine title (Mr. or Ms.)
        $title = '';
        if (strtolower($gender) == 'male') {
            $title = 'MR.';
        } elseif (strtolower($gender) == 'female') {
            $title = 'MS.';
        } else {
            $title = '';
        }
         $pdf->Ln(6);
        $this->addToWhomItMayConcern($pdf);
        $pdf->Ln(8);

        $pdf->SetFont('times', '', 12);
        
        // First paragraph - using Write to keep as continuous sentence
        $pdf->Write(6, "This is to certify that ");
        
        $pdf->SetFont('times', 'B', 12);
        $pdf->Write(6, $title . " " . strtoupper(ucwords($full_name)));
        
        $pdf->SetFont('times', '', 12);
        $pdf->Write(6, " is a resident of " . (!empty($house_number) ? "#. {$house_number}, " : "") . "{$purok}, San Bartolome, Sto Tomas, Pampanga . " .
                   "{$gender}, {$civil_status}, {$age} years old, Filipino, is qualified availer of the ");
        
        $pdf->SetFont('times', 'B', 12);
        $pdf->Write(6, "FIRST TIME JOB SEEKERS ACT OF 2019.");
        
        $pdf->SetFont('times', '', 12);
        $pdf->Write(6, "\n\n");
        
        // Second paragraph
        $text2 = "This is to certify that the holder/bearer was informed of her rights, including the duties and responsibilities accorded by RA 11261 though the Oath of Undertaking she has signed and executed in the presence of our Barangay Official.";
        $pdf->Write(6, $text2);
        $pdf->Write(6, "\n\n");
        
        // Signed statement
        $text3 = "Signed this " . date('jS') . " day of " . date('F') . ", " . date('Y') . " at {$this->barangay_name}, {$this->municipality}, {$this->province}.";
        $pdf->Write(6, $text3);
        $pdf->Write(6, "\n\n");
        
        // Validity statement (bold and red)
        $pdf->SetFont('times', 'B', 11);
        $pdf->SetTextColor(0, 0, 0);
        $text4 = "This certification is valid only for six (6) months from the date issued.";
        $pdf->Write(6, $text4);
        $pdf->SetTextColor(0, 0, 0);
        
        $pdf->Ln(15);
        
        // Perfect square thumbprint boxes
        $this->addSquareThumbprintBoxes($pdf);
        
        // Fee statement
       
        
        $this->addSignatures($pdf, $full_name, true);
        $this->addFooter($pdf);
        
        $pdf->Output($filepath, 'F');
    }
    
    protected function addSquareThumbprintBoxes($pdf) {
        // Calculate Y position
        $current_y = $pdf->GetY();
        
        // Square size (perfect square 40mm x 40mm)
        $square_size = 40;
        
        // Left Thumbprint Square Box
        $left_x = 35;
        $left_y = $current_y;
        
        $pdf->SetXY($left_x, $left_y);
        $pdf->SetFont('times', 'B', 9);
        $pdf->Cell($square_size, 5, "LEFT THUMBPRINT", 0, 0, 'C');
        
        $pdf->SetXY($left_x, $left_y + 5);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.5);
        $pdf->Rect($left_x, $pdf->GetY(), $square_size, $square_size);
        
        $pdf->SetXY($left_x, $left_y + 5 + $square_size);
        $pdf->SetFont('times', 'I', 7);
        $pdf->Cell($square_size, 4, "(Left Thumbprint)", 0, 0, 'C');
        
        // Right Thumbprint Square Box
        $right_x = 135;
        $right_y = $current_y;
        
        $pdf->SetXY($right_x, $right_y);
        $pdf->SetFont('times', 'B', 9);
        $pdf->Cell($square_size, 5, "RIGHT THUMBPRINT", 0, 0, 'C');
        
        $pdf->SetXY($right_x, $right_y + 5);
        $pdf->Rect($right_x, $pdf->GetY(), $square_size, $square_size);
        
        $pdf->SetXY($right_x, $right_y + 5 + $square_size);
        $pdf->SetFont('times', 'I', 7);
        $pdf->Cell($square_size, 4, "(Right Thumbprint)", 0, 0, 'C');
        
        // Move Y position below the boxes
        $pdf->SetY($current_y + $square_size + 20);
    }
}