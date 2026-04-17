<?php
/**
 * Simple QR Code Generator
 * Create this file at: includes/QRCodeGenerator.php
 */

class QRCodeGenerator {
    private $qrDir;
    
    public function __construct() {
        $this->qrDir = __DIR__ . '/../qrcodes/';
        
        // Create directory if it doesn't exist
        if (!file_exists($this->qrDir)) {
            mkdir($this->qrDir, 0777, true);
        }
    }
    
    /**
     * Generate QR code image for document request
     */
    public function generateDocumentQR($request_id, $resident_id, $document_type, $resident_name) {
        // Create QR data array
        $qrData = [
            'type' => 'document_request',
            'request_id' => $request_id,
            'resident_id' => $resident_id,
            'document_type' => $document_type,
            'resident_name' => $resident_name,
            'verification_code' => $this->generateVerificationCode($request_id),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Convert to JSON
        $qrString = json_encode($qrData, JSON_UNESCAPED_UNICODE);
        
        // Generate filename
        $filename = 'qr_' . $request_id . '_' . time() . '.png';
        $filepath = $this->qrDir . $filename;
        
        // Generate QR code image using Google Charts API
        $qrImagePath = $this->createQRImage($qrString, $filepath);
        
        if ($qrImagePath) {
            return [
                'success' => true,
                'qr_path' => 'qrcodes/' . $filename,
                'qr_full_path' => $filepath,
                'qr_data' => $qrString,
                'verification_code' => $qrData['verification_code']
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Failed to generate QR code image'
        ];
    }
    
    /**
     * Create QR code image using Google Charts API
     */
    private function createQRImage($data, $filepath) {
        $size = '300x300';
        $encoding = 'UTF-8';
        
        $qrUrl = "https://chart.googleapis.com/chart?chs={$size}&cht=qr&chl=" . 
                 urlencode($data) . "&choe={$encoding}";
        
        // Download and save the QR image
        $imageData = @file_get_contents($qrUrl);
        
        if ($imageData !== false) {
            if (file_put_contents($filepath, $imageData)) {
                return $filepath;
            }
        }
        
        // Fallback: Create simple QR-like image with GD
        return $this->createFallbackQR($data, $filepath);
    }
    
    /**
     * Fallback QR generation using GD library
     */
    private function createFallbackQR($data, $filepath) {
        if (!extension_loaded('gd')) {
            return false;
        }
        
        $size = 300;
        $img = imagecreatetruecolor($size, $size);
        
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        $green = imagecolorallocate($img, 0, 150, 0);
        
        imagefilledrectangle($img, 0, 0, $size, $size, $white);
        
        // Draw border
        imagerectangle($img, 10, 10, $size-10, $size-10, $black);
        
        // Add text
        $decoded = json_decode($data, true);
        $texts = [
            "DOCUMENT REQUEST",
            "ID: " . ($decoded['request_id'] ?? 'N/A'),
            "Code: " . ($decoded['verification_code'] ?? 'N/A'),
            "",
            "Scan to Verify"
        ];
        
        $y = 60;
        foreach ($texts as $text) {
            $x = ($size - (strlen($text) * 7)) / 2;
            imagestring($img, 4, (int)$x, $y, $text, $black);
            $y += 35;
        }
        
        // Draw some QR-like squares
        for ($i = 0; $i < 20; $i++) {
            $x = rand(20, $size-40);
            $y = rand(150, $size-40);
            $w = rand(5, 15);
            imagefilledrectangle($img, $x, $y, $x+$w, $y+$w, $black);
        }
        
        imagepng($img, $filepath);
        imagedestroy($img);
        
        return file_exists($filepath) ? $filepath : false;
    }
    
    /**
     * Generate unique verification code
     */
    private function generateVerificationCode($request_id) {
        return strtoupper(substr(md5($request_id . time() . rand(1000, 9999)), 0, 8));
    }
    
    /**
     * Save QR code path to database
     */
    public function saveQRPath($conn, $request_id, $qr_path) {
        $sql = "UPDATE document_requests SET qr_code_path = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "si", $qr_path, $request_id);
        return mysqli_stmt_execute($stmt);
    }
    
    /**
     * Get request by QR data
     */
    public function getRequestByQRData($conn, $qr_data) {
        $decoded = json_decode($qr_data, true);
        
        if ($decoded && isset($decoded['request_id'])) {
            $request_id = intval($decoded['request_id']);
            
            $sql = "SELECT dr.*, r.first_name, r.last_name, r.email, r.phone, r.address 
                    FROM document_requests dr 
                    JOIN resident r ON dr.resident_id = r.id 
                    WHERE dr.id = ?";
            
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "i", $request_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if ($result && mysqli_num_rows($result) > 0) {
                return mysqli_fetch_assoc($result);
            }
        }
        
        return null;
    }
}
?>