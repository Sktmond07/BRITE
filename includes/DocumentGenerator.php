<?php
/**
 * Barangay Document Generator using TCPDF (Windows Compatible)
 */

// Check if TCPDF exists
$tcpdf_path = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
if (!file_exists($tcpdf_path)) {
    error_log("TCPDF not found at: " . $tcpdf_path);
    die("TCPDF library not found. Please install it via composer.");
}

require_once $tcpdf_path;

class BarangayDocumentGenerator {
    private $conn;
    private $upload_path;
    private $logo_path;
    private $error_log;
    
    public function __construct($conn) {
        $this->conn = $conn;
        
        // Use DIRECTORY_SEPARATOR for Windows compatibility
        $this->upload_path = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . 'generated_documents' . DIRECTORY_SEPARATOR;
        $this->logo_path = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . 'logo.jpg';
        $this->error_log = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . 'document_generator_error.log';
        
        // Create directory if not exists
        if (!file_exists($this->upload_path)) {
            if (!mkdir($this->upload_path, 0777, true)) {
                $this->logError("Failed to create directory: " . $this->upload_path);
            }
        }
        
        // Check if directory is writable
        if (!is_writable($this->upload_path)) {
            $this->logError("Directory not writable: " . $this->upload_path);
        }
    }
    
    private function logError($message) {
        $log_message = date('Y-m-d H:i:s') . " - " . $message . PHP_EOL;
        @file_put_contents($this->error_log, $log_message, FILE_APPEND);
        error_log($message);
    }
    
    /**
     * Generate document for a request
     */
    public function generateDocument($request_id) {
        try {
            // Get request details with custom data
            $request = $this->getRequestDetails($request_id);
            if (!$request) {
                $this->logError("Request not found: $request_id");
                return ['success' => false, 'message' => 'Request not found'];
            }
            
            // Get custom field values
            $custom_data = $this->getCustomData($request_id);
            
            // Generate filename
            $filename = $this->generateFilename($request, $custom_data);
            $filepath = $this->upload_path . $filename;
            
            $this->logError("Attempting to generate document for request ID: $request_id");
            $this->logError("File will be saved to: $filepath");
            
            // Generate PDF based on document type
            $document_type = $request['document_type'];
            
            switch($document_type) {
                case 'Barangay Clearance':
                    $this->generateBarangayClearance($request, $custom_data, $filepath);
                    break;
                case 'Certificate of Residency':
                    $this->generateCertificateOfResidency($request, $custom_data, $filepath);
                    break;
                default:
                    $this->generateGenericCertificate($request, $custom_data, $filepath);
            }
            
            // Check if file was created
            if (file_exists($filepath) && filesize($filepath) > 0) {
                // Update database with document path
                $this->updateDocumentPath($request_id, $filename);
                $this->logError("Document generated successfully: $filename");
                return ['success' => true, 'filepath' => $filepath, 'filename' => $filename];
            } else {
                $this->logError("File was not created: $filepath");
                return ['success' => false, 'message' => 'File was not created'];
            }
            
        } catch (Exception $e) {
            $this->logError("Exception: " . $e->getMessage());
            $this->logError("Stack trace: " . $e->getTraceAsString());
            return ['success' => false, 'message' => 'Error generating document: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get request details with resident info
     */
    private function getRequestDetails($request_id) {
        $sql = "SELECT dr.*, r.first_name, r.last_name, r.email, r.phone, r.address 
                FROM document_requests dr 
                JOIN resident r ON dr.resident_id = r.id 
                WHERE dr.id = ?";
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        return mysqli_fetch_assoc($result);
    }
    
    /**
     * Get custom data for request
     */
    private function getCustomData($request_id) {
        $sql = "SELECT field_name, field_value FROM document_requests_custom_data WHERE request_id = ?";
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[$row['field_name']] = $row['field_value'];
        }
        return $data;
    }
    
    /**
     * Generate filename
     */
    private function generateFilename($request, $custom_data) {
        $resident_name = preg_replace('/[^a-zA-Z0-9]/', '_', $request['first_name'] . '_' . $request['last_name']);
        $doc_type = preg_replace('/[^a-zA-Z0-9]/', '_', $request['document_type']);
        $date = date('Y-m-d');
        $time = time();
        return "{$doc_type}_{$resident_name}_{$date}_{$time}.pdf";
    }
    
    /**
     * Update database with document path
     */
    private function updateDocumentPath($request_id, $filename) {
        $sql = "UPDATE document_requests SET 
                document_generated = 1, 
                document_path = ?,
                document_generated_at = CURRENT_TIMESTAMP 
                WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "si", $filename, $request_id);
        return mysqli_stmt_execute($stmt);
    }
    
    /**
     * Generate Barangay Clearance
     */
    private function generateBarangayClearance($request, $data, $filepath) {
        // Create new PDF document
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set margins
        $pdf->SetMargins(20, 20, 20);
        
        // Add a page
        $pdf->AddPage();
        
        // Add logo if exists (using absolute Windows path)
        if (file_exists($this->logo_path)) {
            $pdf->Image($this->logo_path, 80, 10, 30, 0, 'JPG', '', '', false, 300);
        }
        
        // Header
        $pdf->SetY(35);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 7, 'REPUBLIC OF THE PHILIPPINES', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 6, 'PROVINCE OF PAMPANGA', 0, 1, 'C');
        $pdf->Cell(0, 6, 'MUNICIPALITY OF STO. TOMAS', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, 'BARANGAY SAN BARTOLOME', 0, 1, 'C');
        
        // Separator line
        $pdf->SetDrawColor(0, 100, 0);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(8);
        
        // Title
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->SetTextColor(0, 100, 0);
        $pdf->Cell(0, 10, 'BARANGAY CLEARANCE', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);
        
        // Get data
        $resident_name = $request['first_name'] . ' ' . $request['last_name'];
        $civil_status = isset($data['civil_status']) ? $data['civil_status'] : '_____';
        $purok = isset($data['purok']) ? $data['purok'] : '_____';
        $house_no = isset($data['house_number']) ? $data['house_number'] : '_____';
        $purpose = isset($data['purpose']) ? $data['purpose'] : '_____';
        
        // Write content
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->MultiCell(0, 8, "TO WHOM IT MAY CONCERN:", 0, 'L');
        $pdf->Ln(3);
        
        $pdf->SetFont('helvetica', '', 12);
        $text = "This is to certify that " . ucwords($resident_name) . ", of legal age, {$civil_status}, a resident of {$purok}, House No. {$house_no}, Barangay San Bartolome, Municipality of Sto. Tomas, Province of Pampanga, is known to be a law-abiding citizen of this barangay.\n\n";
        $text .= "This certification is issued upon the request of the above-named resident for {$purpose} purposes, to attest to his/her good moral character and to certify that he/she has no pending case or derogatory record in this barangay.\n\n";
        $text .= "Issued this " . date('jS') . " day of " . date('F') . ", " . date('Y') . " at Barangay San Bartolome, Sto. Tomas, Pampanga.";
        
        $pdf->MultiCell(0, 8, $text, 0, 'J');
        
        // Footer with signatures
        $pdf->Ln(15);
        $pdf->SetFont('helvetica', '', 10);
        
        // Left signature
        $pdf->SetXY(30, $pdf->GetY());
        $pdf->Cell(70, 6, '_________________________________', 0, 0, 'C');
        $pdf->SetXY(30, $pdf->GetY() + 5);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(70, 6, 'Signature of Applicant', 0, 0, 'C');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(30, $pdf->GetY() + 5);
        $pdf->Cell(70, 6, ucwords($resident_name), 0, 0, 'C');
        
        // Right signature
        $pdf->SetXY(110, $pdf->GetY() - 16);
        $pdf->Cell(70, 6, '_________________________________', 0, 0, 'C');
        $pdf->SetXY(110, $pdf->GetY() + 5);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(70, 6, 'Signature of Barangay Captain', 0, 0, 'C');
        
        $pdf->Ln(15);
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->Cell(0, 6, 'OR No. ______________________________', 0, 1, 'C');
        $pdf->Cell(0, 6, 'Issued on: ' . date('F d, Y'), 0, 1, 'C');
        
        // Save PDF
        $pdf->Output($filepath, 'F');
    }
    
    /**
     * Generate Certificate of Residency
     */
    private function generateCertificateOfResidency($request, $data, $filepath) {
        // Create new PDF document
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set margins
        $pdf->SetMargins(20, 20, 20);
        
        // Add a page
        $pdf->AddPage();
        
        // Add logo if exists
        if (file_exists($this->logo_path)) {
            $pdf->Image($this->logo_path, 80, 10, 30, 0, 'JPG', '', '', false, 300);
        }
        
        // Header
        $pdf->SetY(35);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 7, 'REPUBLIC OF THE PHILIPPINES', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 6, 'PROVINCE OF PAMPANGA', 0, 1, 'C');
        $pdf->Cell(0, 6, 'MUNICIPALITY OF STO. TOMAS', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, 'BARANGAY SAN BARTOLOME', 0, 1, 'C');
        
        // Separator line
        $pdf->SetDrawColor(0, 100, 0);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(8);
        
        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetTextColor(0, 100, 0);
        $pdf->Cell(0, 10, 'CERTIFICATE OF RESIDENCY', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);
        
        // Get data
        $resident_name = $request['first_name'] . ' ' . $request['last_name'];
        $length_of_stay = isset($data['length_of_stay']) ? $data['length_of_stay'] : '_____';
        $purok = isset($data['purok']) ? $data['purok'] : '_____';
        $house_no = isset($data['house_number']) ? $data['house_number'] : '_____';
        $purpose = isset($data['purpose']) ? $data['purpose'] : '_____';
        
        // Write content
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->MultiCell(0, 8, "TO WHOM IT MAY CONCERN:", 0, 'L');
        $pdf->Ln(3);
        
        $pdf->SetFont('helvetica', '', 12);
        $text = "This is to certify that " . ucwords($resident_name) . " is a bona fide resident of {$purok}, House No. {$house_no}, Barangay San Bartolome, Municipality of Sto. Tomas, Province of Pampanga, and has been residing in the said barangay for {$length_of_stay}.\n\n";
        $text .= "This certification is issued upon the request of the above-named resident for {$purpose} purposes.\n\n";
        $text .= "Issued this " . date('jS') . " day of " . date('F') . ", " . date('Y') . " at Barangay San Bartolome, Sto. Tomas, Pampanga.";
        
        $pdf->MultiCell(0, 8, $text, 0, 'J');
        
        // Footer with signatures
        $pdf->Ln(15);
        $pdf->SetFont('helvetica', '', 10);
        
        $pdf->SetXY(30, $pdf->GetY());
        $pdf->Cell(70, 6, '_________________________________', 0, 0, 'C');
        $pdf->SetXY(30, $pdf->GetY() + 5);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(70, 6, 'Signature of Applicant', 0, 0, 'C');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(30, $pdf->GetY() + 5);
        $pdf->Cell(70, 6, ucwords($resident_name), 0, 0, 'C');
        
        $pdf->SetXY(110, $pdf->GetY() - 16);
        $pdf->Cell(70, 6, '_________________________________', 0, 0, 'C');
        $pdf->SetXY(110, $pdf->GetY() + 5);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(70, 6, 'Signature of Barangay Captain', 0, 0, 'C');
        
        $pdf->Ln(15);
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->Cell(0, 6, 'OR No. ______________________________', 0, 1, 'C');
        $pdf->Cell(0, 6, 'Issued on: ' . date('F d, Y'), 0, 1, 'C');
        
        // Save PDF
        $pdf->Output($filepath, 'F');
    }
    
    /**
     * Generate Generic Certificate
     */
    private function generateGenericCertificate($request, $data, $filepath) {
        // Create new PDF document
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set margins
        $pdf->SetMargins(20, 20, 20);
        
        // Add a page
        $pdf->AddPage();
        
        // Add logo if exists
        if (file_exists($this->logo_path)) {
            $pdf->Image($this->logo_path, 80, 10, 30, 0, 'JPG', '', '', false, 300);
        }
        
        // Header
        $pdf->SetY(35);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 7, 'REPUBLIC OF THE PHILIPPINES', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 6, 'PROVINCE OF PAMPANGA', 0, 1, 'C');
        $pdf->Cell(0, 6, 'MUNICIPALITY OF STO. TOMAS', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, 'BARANGAY SAN BARTOLOME', 0, 1, 'C');
        
        // Separator line
        $pdf->SetDrawColor(0, 100, 0);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(8);
        
        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetTextColor(0, 100, 0);
        $pdf->Cell(0, 10, strtoupper($request['document_type']), 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);
        
        // Get data
        $resident_name = $request['first_name'] . ' ' . $request['last_name'];
        $purpose = isset($data['purpose']) ? $data['purpose'] : $request['purpose'];
        
        // Write content
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->MultiCell(0, 8, "TO WHOM IT MAY CONCERN:", 0, 'L');
        $pdf->Ln(3);
        
        $pdf->SetFont('helvetica', '', 12);
        $text = "This is to certify that " . ucwords($resident_name) . ", a resident of " . $request['address'] . ", Barangay San Bartolome, Municipality of Sto. Tomas, Province of Pampanga.\n\n";
        $text .= "This certification is issued upon the request of the above-named resident for {$purpose} purposes.\n\n";
        $text .= "Issued this " . date('jS') . " day of " . date('F') . ", " . date('Y') . " at Barangay San Bartolome, Sto. Tomas, Pampanga.";
        
        $pdf->MultiCell(0, 8, $text, 0, 'J');
        
        // Footer with signatures
        $pdf->Ln(15);
        $pdf->SetFont('helvetica', '', 10);
        
        $pdf->SetXY(30, $pdf->GetY());
        $pdf->Cell(70, 6, '_________________________________', 0, 0, 'C');
        $pdf->SetXY(30, $pdf->GetY() + 5);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(70, 6, 'Signature of Applicant', 0, 0, 'C');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(30, $pdf->GetY() + 5);
        $pdf->Cell(70, 6, ucwords($resident_name), 0, 0, 'C');
        
        $pdf->SetXY(110, $pdf->GetY() - 16);
        $pdf->Cell(70, 6, '_________________________________', 0, 0, 'C');
        $pdf->SetXY(110, $pdf->GetY() + 5);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(70, 6, 'Signature of Barangay Captain', 0, 0, 'C');
        
        $pdf->Ln(15);
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->Cell(0, 6, 'OR No. ______________________________', 0, 1, 'C');
        $pdf->Cell(0, 6, 'Issued on: ' . date('F d, Y'), 0, 1, 'C');
        
        // Save PDF
        $pdf->Output($filepath, 'F');
    }
}
?>