<?php
require_once 'BaseDocumentGenerator.php';

class BarangayIDGenerator extends BaseDocumentGenerator {
    
    protected function generateSpecificDocument($request, $data, $filepath) {
        $pdf = $this->setupPDF();
        
        // Remove default margins for ID card
        $pdf->SetMargins(0, 0, 0);
        
        // Get data with defaults
        $full_name = isset($data['full_name']) ? $data['full_name'] : $request['first_name'] . ' ' . $request['last_name'];
        $purok = isset($data['purok']) ? $data['purok'] : '_____';
        $house_number = isset($data['house_number']) ? $data['house_number'] : '_____';
        $birthdate = isset($data['birthdate']) ? $this->formatDate($data['birthdate']) : '_____';
        $place_of_birth = isset($data['place_of_birth']) ? $data['place_of_birth'] : '_____';
        $gender = isset($data['gender']) ? $data['gender'] : '_____';
        $civil_status = isset($data['civil_status']) ? $data['civil_status'] : '_____';
        $blood_type = isset($data['blood_type']) ? $data['blood_type'] : 'Unknown';
        
        // ID Card dimensions (standard credit card size ~ 85.6mm x 54mm)
        $card_width = 90;
        $card_height = 55;
        
        // Center the card on A4
        $page_width = 210;
        $page_height = 297;
        $card_x = ($page_width - $card_width) / 2;
        $card_y = ($page_height - $card_height) / 2;
        
        // Draw rounded rectangle for the card
        $pdf->SetDrawColor(0, 51, 102); // Dark blue border
        $pdf->SetLineWidth(0.5);
        $pdf->RoundedRect($card_x, $card_y, $card_width, $card_height, 4, '1111', 'D');
        
        // Fill card with light gradient effect (light blue background)
        $pdf->SetFillColor(240, 248, 255); // Light blue background
        $pdf->RoundedRect($card_x, $card_y, $card_width, $card_height, 4, '1111', 'F');
        
        // Add header background
        $pdf->SetFillColor(0, 51, 102); // Dark blue header
        $pdf->Rect($card_x, $card_y, $card_width, 15, 'F');
        
        // Add header text
        $pdf->SetY($card_y + 3);
        $pdf->SetX($card_x);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell($card_width, 4, $this->barangay_name, 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 6);
        $pdf->SetX($card_x);
        $pdf->Cell($card_width, 3, 'BARANGAY IDENTIFICATION CARD', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 5);
        $pdf->SetX($card_x);
        $pdf->Cell($card_width, 3, $this->municipality . ', ' . $this->province, 0, 1, 'C');
        
        // Reset text color for content
        $pdf->SetTextColor(0, 0, 0);
        
        // Add photo (right side)
        $photo_x = $card_x + $card_width - 28;
        $photo_y = $card_y + 18;
        $photo_size = 22;
        
        // Photo frame
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->Rect($photo_x, $photo_y, $photo_size, $photo_size);
        
        // Check if photo exists in custom data
        if (isset($data['photo']) && !empty($data['photo']) && file_exists($data['photo'])) {
            $pdf->Image($data['photo'], $photo_x + 1, $photo_y + 1, $photo_size - 2, $photo_size - 2, '', '', '', false, 300);
        } else {
            // Photo placeholder
            $pdf->SetFont('helvetica', 'I', 5);
            $pdf->SetXY($photo_x + 4, $photo_y + 8);
            $pdf->Cell($photo_size - 8, 3, 'PHOTO', 0, 0, 'C');
            $pdf->SetXY($photo_x + 4, $photo_y + 11);
            $pdf->Cell($photo_size - 8, 3, '1x1', 0, 0, 'C');
        }
        
        // ID Number
        $pdf->SetFont('helvetica', 'B', 5);
        $pdf->SetXY($card_x + 5, $card_y + 18);
        $pdf->Cell(20, 3, 'ID NO:', 0, 0);
        $pdf->SetFont('helvetica', '', 5);
        $pdf->Cell(35, 3, str_pad($request['id'], 12, '0', STR_PAD_LEFT), 0, 1);
        
        // Name
        $pdf->SetX($card_x + 5);
        $pdf->SetFont('helvetica', 'B', 5);
        $pdf->Cell(20, 3, 'NAME:', 0, 0);
        $pdf->SetFont('helvetica', '', 5);
        $pdf->Cell(35, 3, strtoupper(substr($full_name, 0, 23)), 0, 1);
        
        // Birth Date
        $pdf->SetX($card_x + 5);
        $pdf->SetFont('helvetica', 'B', 5);
        $pdf->Cell(20, 3, 'BIRTH DATE:', 0, 0);
        $pdf->SetFont('helvetica', '', 5);
        $pdf->Cell(35, 3, $birthdate, 0, 1);
        
        // Birth Place
        $pdf->SetX($card_x + 5);
        $pdf->SetFont('helvetica', 'B', 5);
        $pdf->Cell(20, 3, 'BIRTH PLACE:', 0, 0);
        $pdf->SetFont('helvetica', '', 5);
        $pdf->Cell(35, 3, substr($place_of_birth, 0, 23), 0, 1);
        
        // Gender & Civil Status (same line)
        $pdf->SetX($card_x + 5);
        $pdf->SetFont('helvetica', 'B', 5);
        $pdf->Cell(20, 3, 'GENDER:', 0, 0);
        $pdf->SetFont('helvetica', '', 5);
        $pdf->Cell(15, 3, $gender, 0, 0);
        $pdf->SetFont('helvetica', 'B', 5);
        $pdf->Cell(15, 3, 'CIVIL STATUS:', 0, 0);
        $pdf->SetFont('helvetica', '', 5);
        $pdf->Cell(25, 3, substr($civil_status, 0, 12), 0, 1);
        
        // Blood Type
        $pdf->SetX($card_x + 5);
        $pdf->SetFont('helvetica', 'B', 5);
        $pdf->Cell(20, 3, 'BLOOD TYPE:', 0, 0);
        $pdf->SetFont('helvetica', '', 5);
        $pdf->Cell(15, 3, $blood_type, 0, 0);
        
        // Address
        $pdf->SetX($card_x + 5);
        $pdf->SetFont('helvetica', 'B', 5);
        $pdf->Cell(20, 3, 'ADDRESS:', 0, 0);
        $pdf->SetFont('helvetica', '', 5);
        $address_text = "{$purok}" . (!empty($house_number) ? " Blk {$house_number}" : "");
        $pdf->Cell(50, 3, substr($address_text, 0, 35), 0, 1);
        
        // Add separator line
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line($card_x + 5, $card_y + 43, $card_x + $card_width - 5, $card_y + 43);
        
        // Signature and thumbprint area
        $pdf->SetY($card_y + 45);
        
        // Left - Signature
        $pdf->SetX($card_x + 5);
        $pdf->SetFont('helvetica', 'I', 4);
        $pdf->Cell(40, 3, 'Signature', 0, 0, 'C');
        $pdf->SetX($card_x + 5);
        $pdf->SetY($card_y + 47);
        $pdf->SetFont('helvetica', '', 4);
        $pdf->Cell(40, 2, '____________________', 0, 0, 'C');
        
        // Right - Thumbprint
        $pdf->SetX($card_x + 50);
        $pdf->SetY($card_y + 45);
        $pdf->SetFont('helvetica', 'I', 4);
        $pdf->Cell(35, 3, 'Thumbprint', 0, 0, 'C');
        $pdf->SetX($card_x + 50);
        $pdf->SetY($card_y + 47);
        $pdf->SetFont('helvetica', '', 4);
        $pdf->Cell(35, 2, '____________________', 0, 0, 'C');
        
        // Add issue date at bottom
        $pdf->SetY($card_y + $card_height - 5);
        $pdf->SetX($card_x);
        $pdf->SetFont('helvetica', 'I', 4);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell($card_width, 2, 'Issued: ' . date('F d, Y'), 0, 0, 'C');
        
        // Add a subtle watermark/background text
        $pdf->SetTextColor(220, 220, 220);
        $pdf->SetFont('helvetica', 'B', 20);
        $pdf->SetXY($card_x + 15, $card_y + 25);
        $pdf->Cell(40, 8, $this->barangay_name, 0, 0, 'C');
        
        $pdf->Output($filepath, 'F');
    }
}