<?php
require_once 'BaseDocumentGenerator.php';

class CertificateOfGuardianshipGenerator extends BaseDocumentGenerator {
    
    protected function generateSpecificDocument($request, $data, $filepath) {
        $pdf = $this->setupPDF();
        
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(0, 0, 0);
        
        $this->addHeader($pdf);
        $this->addReferenceNumber($pdf, $request['id']);
        $this->addTitle($pdf, 'CERTIFICATE OF GUARDIANSHIP', 16);
        
        // Get guardian data from custom fields
        $guardian_full_name = isset($data['full_name']) ? strtoupper($data['full_name']) : strtoupper($request['first_name'] . ' ' . $request['last_name']);
        $guardian_civil_status = isset($data['civil_status']) ? $data['civil_status'] : '_____';
        $purok = isset($data['purok']) ? $data['purok'] : '_____';
        $house_number = isset($data['house_number']) ? $data['house_number'] : '_____';
        $guardian_birthdate = isset($data['birthdate']) ? $data['birthdate'] : '';
        
        // Calculate age from birthdate
        $guardian_age = '';
        if (!empty($guardian_birthdate) && $guardian_birthdate != '_____') {
            $birth_date = new DateTime($guardian_birthdate);
            $today = new DateTime();
            $guardian_age = $birth_date->diff($today)->y;
        } else {
            $guardian_age = 'of legal age';
        }
        
        // Parse children data from JSON string
        $children = [];
        
        if (isset($data['children'])) {
            $children_data = $data['children'];
            
            if (is_string($children_data)) {
                $clean_json = stripslashes($children_data);
                $decoded = json_decode($clean_json, true);
                
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $children = $decoded;
                } else {
                    $decoded = json_decode($children_data, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $children = $decoded;
                    }
                }
            } elseif (is_array($children_data)) {
                $children = $children_data;
            }
        }
        
        // Get purpose
        $purpose = isset($data['purpose']) ? strtoupper($data['purpose']) : '_____';
        $pdf->Ln(12);
        $this->addToWhomItMayConcern($pdf);
        
        $pdf->SetFont('times', '', 12);
        $pdf->Ln(5);
        
        // PARAGRAPH 1: Certification of Guardian
        $pdf->SetFont('times', '', 12);
        $pdf->Write(8, "This is to certify that ", 0, 0);
        $pdf->SetFont('times', 'B', 12);
        $pdf->Write(8, $guardian_full_name, 0, 0);
        $pdf->SetFont('times', '', 12);
        $pdf->Write(8, ", Filipino, ", 0, 0);
        $pdf->SetFont('times', 'B', 12);
        $pdf->Write(8, $guardian_civil_status, 0, 0);
        $pdf->SetFont('times', '', 12);
        
        if (is_numeric($guardian_age)) {
            $pdf->Write(8, ", " . $guardian_age . " years of age", 0, 0);
        } else {
            $pdf->Write(8, ", " . $guardian_age, 0, 0);
        }
        
        $pdf->Write(8, ", a resident of ", 0, 0);
        $pdf->SetFont('times', 'B', 12);
        
        $address = (!empty($house_number) ? "#  {$house_number}, " : "") . "{$purok}, San Bartolome, Sto Tomas, Pampanga";
        
        $pdf->Write(8, $address, 0, 0);
        $pdf->SetFont('times', '', 12);
        $pdf->Write(8, ".", 0, 1);
        
        $pdf->Ln(8);
        
        // PARAGRAPH 2: Guardian of minors
        $pdf->Write(8, "This is to certify further that the above-mentioned person is the legal guardian of the following minor/s:", 0, 1);
        
        $pdf->Ln(10);
        
        // TABLE FOR MINORS - WITHOUT BORDERS
        // Table header (no border)
        $pdf->SetFont('times', 'B', 11);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(80, 8, "Name of Minor/s", 0, 0, 'L', true);
        $pdf->Cell(50, 8, "Date of Birth", 0, 0, 'L', true);
        $pdf->Cell(40, 8, "Age", 0, 1, 'L', true);
        
        // Add a line separator after header
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + 170, $pdf->GetY());
        $pdf->Ln(2);
        
        // Table rows (no borders)
        if (!empty($children) && is_array($children) && count($children) > 0) {
            $pdf->SetFont('times', '', 11);
            
            foreach ($children as $index => $child) {
                // Get child name
                $child_name = '_____';
                if (isset($child['full_name']) && !empty($child['full_name'])) {
                    $child_name = strtoupper($child['full_name']);
                } elseif (isset($child['name']) && !empty($child['name'])) {
                    $child_name = strtoupper($child['name']);
                }
                
                // Get child birthdate
                $child_birthdate = '_____';
                if (isset($child['birthdate']) && !empty($child['birthdate'])) {
                    $child_birthdate = $this->formatDate($child['birthdate']);
                }
                
                // Get child age
                $child_age = '_____';
                if (isset($child['age']) && !empty($child['age'])) {
                    $child_age = $child['age'];
                } elseif (!empty($child['birthdate'])) {
                    $birth_date = new DateTime($child['birthdate']);
                    $today = new DateTime();
                    $child_age = $birth_date->diff($today)->y;
                }
                
                // Draw row (no border)
                $pdf->SetFont('times', '', 11);
                $pdf->Cell(80, 7, $child_name, 0, 0, 'L');
                $pdf->Cell(50, 7, $child_birthdate, 0, 0, 'L');
                $pdf->Cell(40, 7, (string)$child_age, 0, 1, 'L');
                
                // Add dotted line after each row (optional)
                $pdf->SetDrawColor(220, 220, 220);
                $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + 170, $pdf->GetY());
                $pdf->Ln(2);
            }
        } else {
            // No children data - show blank rows without borders
            $pdf->SetFont('times', 'I', 10);
            $pdf->Cell(170, 8, "No minor/s listed", 0, 1, 'C');
        }
        
        $pdf->Ln(8);
        
        // PARAGRAPH 3: Purpose (BOLD ITALIC)
        $pdf->SetFont('times', '', 12);
        $pdf->Write(8, "This certification is issued upon the request of the above-named person for ", 0, 0);
        $pdf->SetFont('times', 'BI', 12);
        $pdf->Write(8, $purpose, 0, 0);
        $pdf->SetFont('times', '', 12);
        $pdf->Write(8, ".", 0, 1);
        
        $pdf->Ln(10);
        
        // PARAGRAPH 4: Issuance date
        $pdf->Write(8, "Given this " . date('jS') . " day of " . date('F') . ", " . date('Y') . " at San Bartolome, Sto Tomas, Pampanga, Philippines.", 0, 1);
        
        $this->addSignatures($pdf, $guardian_full_name);
        $this->addFooter($pdf);
        
        $pdf->Output($filepath, 'F');
    }
    
    /**
     * Format date to readable format
     */
   
}