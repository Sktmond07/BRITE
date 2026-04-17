<?php
/**
 * Factory class to create appropriate document generator
 */
require_once 'BarangayClearanceGenerator.php';
require_once 'CertificateOfResidencyGenerator.php';
require_once 'FirstTimeJobSeekerGenerator.php';
require_once 'BarangayIndigencyGenerator.php';
require_once 'CertificateOfCohabitationGenerator.php';
require_once 'CertificateOfGuardianshipGenerator.php';
require_once 'CertificateOfNonResidencyGenerator.php';
require_once 'BarangayIDGenerator.php';

class DocumentGeneratorFactory {
    
    private $conn;
    private $document_type_mapping;
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->initializeMapping();
    }
    
    private function initializeMapping() {
        $this->document_type_mapping = [
            1 => 'BarangayClearanceGenerator',      // Barangay Clearance
            2 => 'CertificateOfResidencyGenerator',  // Certificate of Residency
            6 => 'FirstTimeJobSeekerGenerator',      // First Time Job Seeker
            7 => 'BarangayIndigencyGenerator',       // Barangay Indigency
            8 => 'CertificateOfCohabitationGenerator', // Certificate of Cohabitation
            9 => 'CertificateOfGuardianshipGenerator', // Certificate of Guardianship
            10 => 'CertificateOfNonResidencyGenerator', // Certificate of Non-Residency
            11 => 'BarangayIDGenerator'              // Barangay ID
        ];
    }
    
    public function getGenerator($document_type_id) {
        if (isset($this->document_type_mapping[$document_type_id])) {
            $generator_class = $this->document_type_mapping[$document_type_id];
            return new $generator_class($this->conn);
        }
        
        // Default to Barangay Clearance if not found
        return new BarangayClearanceGenerator($this->conn);
    }
    
    public function generateDocument($request_id) {
        // Get document type for this request
        $sql = "SELECT dr.document_type_id 
                FROM document_requests dr 
                WHERE dr.id = ?";
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        
        if (!$row) {
            return ['success' => false, 'message' => 'Request not found'];
        }
        
        $generator = $this->getGenerator($row['document_type_id']);
        return $generator->generateDocument($request_id);
    }
}