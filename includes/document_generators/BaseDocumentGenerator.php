<?php
/**
 * Base Document Generator Class
 */
abstract class BaseDocumentGenerator {
    protected $conn;
    protected $upload_path;
    protected $logo_path;
    protected $logo2_path;
    protected $error_log;
    protected $barangay_name;
    protected $municipality;
    protected $province;
    protected $barangay_captain;
    
    public function __construct($conn) {
        $this->conn = $conn;
        
        // Barangay Information
        $this->barangay_name = "BARANGAY SAN BARTOLOME";
        $this->municipality = "MUNICIPALITY OF STO. TOMAS";
        $this->province = "PROVINCE OF PAMPANGA";
        $this->barangay_captain = "HON. BARANGAY CAPTAIN";
        
        // Fix the base path resolution
        $base_path = realpath(__DIR__ . '/../../');
        
        if ($base_path === false) {
            // Fallback if realpath fails
            $base_path = dirname(__DIR__, 2);
        }
        
        $this->upload_path = $base_path . DIRECTORY_SEPARATOR . 'generated_documents' . DIRECTORY_SEPARATOR;
        $this->logo_path = $base_path . DIRECTORY_SEPARATOR . 'logo.jpg';
        $this->logo2_path = $base_path . DIRECTORY_SEPARATOR . 'logo2.jpg';
        $this->error_log = $base_path . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'document_generator_error.log';
        
        // Create directories if not exists
        $this->createDirectories();
    }
    
    private function createDirectories() {
        $directories = [$this->upload_path, dirname($this->error_log)];
        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                if (!mkdir($dir, 0777, true)) {
                    $this->logError("Failed to create directory: " . $dir);
                }
            }
        }
        
        // Check if upload directory is writable
        if (!is_writable($this->upload_path)) {
            $this->logError("Directory not writable: " . $this->upload_path);
        }
    }
    
    protected function logError($message) {
        $log_message = date('Y-m-d H:i:s') . " - " . $message . PHP_EOL;
        @file_put_contents($this->error_log, $log_message, FILE_APPEND);
        error_log($message);
    }
    
    protected function getRequestDetails($request_id) {
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
    
    protected function getCustomData($request_id) {
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
    
    protected function getDocumentFee($document_type_id) {
        $sql = "SELECT fee FROM document_types WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $document_type_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        return $row ? $row['fee'] : 0;
    }
    
    protected function generateFilename($request, $custom_data) {
        $resident_name = preg_replace('/[^a-zA-Z0-9]/', '_', $request['first_name'] . '_' . $request['last_name']);
        $doc_type = preg_replace('/[^a-zA-Z0-9]/', '_', $request['document_type']);
        $date = date('Y-m-d');
        $time = time();
        return "{$doc_type}_{$resident_name}_{$date}_{$time}.pdf";
    }
    
    protected function updateDocumentPath($request_id, $filename) {
        $sql = "UPDATE document_requests SET 
                document_generated = 1, 
                document_path = ?,
                document_generated_at = CURRENT_TIMESTAMP 
                WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "si", $filename, $request_id);
        return mysqli_stmt_execute($stmt);
    }
    
    protected function setupPDF() {
        // Check if TCPDF exists
        $tcpdf_path = __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';
        if (!file_exists($tcpdf_path)) {
            throw new Exception("TCPDF library not found. Please install it via composer.");
        }
        
        require_once $tcpdf_path;
        
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Tighter margins to fit everything on one page
        $pdf->SetMargins(20, 15, 20);
        $pdf->SetAutoPageBreak(false); // Disable auto page break to force single page
        $pdf->AddPage();
        
        return $pdf;
    }
    
    protected function addHeader($pdf) {
        // Left Logo (Barangay Logo)
        $x_left = 20;
        $y_logo = 10;
        $logo_width = 22;
        
        if (file_exists($this->logo_path)) {
            $pdf->Image($this->logo_path, $x_left, $y_logo, $logo_width, 0, 'JPG', '', '', false, 300);
        }
        
        // Right Logo (Municipal Logo)
        $x_right = 168;
        if (file_exists($this->logo2_path)) {
            $pdf->Image($this->logo2_path, $x_right, $y_logo, $logo_width, 0, 'JPG', '', '', false, 300);
        }
        
        // Header Text - Compact spacing
        $pdf->SetY(12);
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(0, 4, 'REPUBLIC OF THE PHILIPPINES', 0, 1, 'C');
        $pdf->SetFont('times', 'B', 10);
        $pdf->Cell(0, 4, $this->province, 0, 1, 'C');
        $pdf->Cell(0, 4, $this->municipality, 0, 1, 'C');
        $pdf->SetFont('times', 'B', 12);
        $pdf->Cell(0, 5, $this->barangay_name, 0, 1, 'C');
        
        // Office of the Barangay Captain - Formal style
        $pdf->SetFont('times', 'B', 12);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 4, 'OFFICE OF THE BARANGAY CAPTAIN', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
        
        // Double line separator
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(1);
        $pdf->SetLineWidth(0.8);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(5);
    }
    
    protected function addTitle($pdf, $title, $fontSize = 14) {
        $pdf->SetFont('times', 'B', $fontSize);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 6, strtoupper($title), 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
    }
    
    protected function addReferenceNumber($pdf, $request_id) {
       
    }
    
    protected function addToWhomItMayConcern($pdf) {
        // Add spacing before
        $pdf->Ln(4);
        
        $pdf->SetFont('times', 'B', 11);
        $pdf->MultiCell(0, 5, "TO WHOM IT MAY CONCERN:", 0, 'L');
        
        // Add spacing after
        $pdf->Ln(4);
    }
    
    protected function formatDate($date) {
        if (empty($date) || $date == '0000-00-00') {
            return '___________';
        }
        return date('F j, Y', strtotime($date));
    }
    
    // Side by side signatures at the bottom    // Side by side signatures at the bottom
       // Side by side signatures at the bottom
       // Side by side signatures at the bottom
    protected function addSignatures($pdf, $resident_name, $showOrNumber = true) {
        // Position signatures at the bottom of the page
        $pdf->SetY($pdf->getPageHeight() - 40);
        
        $current_y = $pdf->GetY();
        
        // Left side - Barangay Captain Signature
        $pdf->SetXY(25, $current_y);
        $pdf->SetFont('times', 'B', 10);
        $pdf->Cell(75, 4, 'HON. RAMIL PANGILINAN', 0, 0, 'C');
        $pdf->SetXY(25, $pdf->GetY() + 4);
        $pdf->SetFont('times', '', 11);
        $pdf->Cell(75, 4, '_________________________', 0, 0, 'C');
        $pdf->SetXY(25, $pdf->GetY() + 4);
        $pdf->SetFont('times', 'I', 10);
        $pdf->Cell(75, 4, 'Punong Barangay', 0, 0, 'C');
        
        // Right side - Applicant Signature
        $pdf->SetXY(110, $current_y);
        $pdf->SetFont('times', 'B', 10);
        $pdf->Cell(75, 4, strtoupper(ucwords($resident_name)), 0, 0, 'C');
        $pdf->SetXY(110, $pdf->GetY() + 4);
        $pdf->SetFont('times', '', 11);
        $pdf->Cell(75, 4, '_________________________', 0, 0, 'C');
        $pdf->SetXY(110, $pdf->GetY() + 4);
        $pdf->SetFont('times', 'I', 10);
        $pdf->Cell(75, 4, 'Signature', 0, 0, 'C');
    }
        protected function addFooter($pdf) {
        $pdf->SetY(-12);
        $pdf->SetFont('times', 'I', 10);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 4, '"This document is electronically generated".', 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }
    
    // Helper method to ensure content fits on one page
    protected function checkPageBreak($pdf, $needed_space = 0) {
        // Disable page breaks to force single page
        return false;
    }
    
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
            
            // Generate the specific document
            $this->generateSpecificDocument($request, $custom_data, $filepath);
            
            // Check if file was created
            if (file_exists($filepath) && filesize($filepath) > 0) {
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
    
    // Abstract method to be implemented by each document type
    abstract protected function generateSpecificDocument($request, $custom_data, $filepath);
}